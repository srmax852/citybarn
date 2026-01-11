<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    protected string $shopDomain;
    protected string $apiPassword;
    protected string $apiVersion;
    protected string $externalBaseUrl;
    protected TokenService $tokenService;

    public function __construct()
    {
        $this->shopDomain       = env('SHOPIFY_SHOP_DOMAIN', '0v4f6m-cg.myshopify.com');
        $this->apiPassword      = env('SHOPIFY_API_PASSWORD');
        $this->apiVersion       = env('SHOPIFY_API_VERSION', '2025-07');
        $this->externalBaseUrl  = env('EXTERNAL_API_BASE_URL', 'http://14.203.153.238:58200/rest');
        $this->tokenService     = new TokenService();
    }

    private function client()
    {
        return Http::withHeaders([
            'X-Shopify-Access-Token' => $this->apiPassword,
            'Content-Type' => 'application/json',
        ])->timeout(60);
    }

    /* 🔹 External Fetchers */
    public function getDepartments(): array { return $this->fetchExternal("GetDepList/JSON"); }
    public function getSubDepartments(): array { return $this->fetchExternal("GetSubList/JSON"); }
    public function getProducts(): array { return $this->fetchExternal("GetWebProductList/json", ['promos' => 'true']); }

    private function fetchExternal(string $endpoint, array $extraParams = []): array
    {
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '-1');

        return $this->tokenService->withAutoRefresh(function ($token) use ($endpoint, $extraParams) {
            $url = "{$this->externalBaseUrl}/{$endpoint}?token={$token}";

            // Add extra parameters if provided
            foreach ($extraParams as $key => $value) {
                $url .= "&{$key}={$value}";
            }

            try {
                $response = Http::timeout(60)->get($url);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::error("❌ Connection failed for {$endpoint}", ['error' => $e->getMessage()]);
                throw new \Exception("Unable to connect to the POS API. Please check if the API server is running.");
            }

            if ($response->failed()) {
                Log::error("❌ Failed to fetch from {$endpoint}", ['body' => $response->body()]);
                throw new \Exception("POS API returned an error. Please try again later.");
            }

            $data = $response->json() ?? [];

            // Check for token error in response
            if (isset($data['Message']) && str_contains(strtolower($data['Message']), 'token')) {
                throw new \Exception($data['Message']);
            }

            return $data;
        });
    }

    /* 🔹 Collections */
    public function getOrCreateCollection(string $title, string $bodyHtml = '', ?int $parentCollectionId = null): ?int
    {
        $existing = $this->findCollectionByTitle($title);
        if ($existing) return $existing;

        return $this->createCollection($title, $bodyHtml, $parentCollectionId);
    }

    public function findCollectionByTitle(string $title): ?int
    {
        try {
            $res = $this->client()->get("https://{$this->shopDomain}/admin/api/{$this->apiVersion}/custom_collections.json", [
                'title' => $title
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("❌ Connection failed to Shopify (findCollectionByTitle)", ['error' => $e->getMessage()]);
            throw new \Exception("Unable to connect to Shopify. Please check your internet connection and try again.");
        }

        if ($res->failed()) return null;

        $collections = $res->json('custom_collections') ?? [];
        return $collections[0]['id'] ?? null;
    }

    private function createCollection(string $title, string $bodyHtml = '', ?int $parentCollectionId = null): ?int
    {
        $data = [
            'custom_collection' => [
                'title' => $title,
                'body_html' => $bodyHtml ?: "<p>Created via API</p>"
            ]
        ];

        if ($parentCollectionId) {
            $data['custom_collection']['body_html'] .= "<p>Subcategory under {$parentCollectionId}</p>";
        }

        try {
            $res = $this->client()->post("https://{$this->shopDomain}/admin/api/{$this->apiVersion}/custom_collections.json", $data);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("❌ Connection failed to Shopify (createCollection)", ['error' => $e->getMessage()]);
            throw new \Exception("Unable to connect to Shopify. Please check your internet connection and try again.");
        }

        if ($res->failed()) {
            Log::error("❌ Failed to create collection", ['response' => $res->body()]);
            return null;
        }

        return $res->json('custom_collection.id');
    }

    /* 🔹 Products */

    /**
     * Fetch all existing Shopify products and build lookup maps
     * Returns ['byBarcode' => [...], 'byTitle' => [...]]
     */
    public function getAllShopifyProducts(): array
    {
        $byBarcode = [];
        $byTitle = [];

        $url = "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/products.json?limit=250&fields=id,title,variants";

        do {
            try {
                $response = $this->client()->timeout(60)->get($url);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::error("❌ Connection failed to Shopify", ['error' => $e->getMessage()]);
                throw new \Exception("Unable to connect to Shopify. Please check your internet connection.");
            }

            if ($response->failed()) {
                Log::error("❌ Failed to fetch products from Shopify", ['body' => $response->body()]);
                throw new \Exception("Failed to fetch existing products from Shopify.");
            }

            $products = $response->json('products') ?? [];

            foreach ($products as $product) {
                $productId = $product['id'];
                $title = strtolower(trim($product['title'] ?? ''));

                // Index by title
                if ($title) {
                    $byTitle[$title] = $productId;
                }

                // Index by barcode from variants
                foreach ($product['variants'] ?? [] as $variant) {
                    $barcode = trim($variant['barcode'] ?? '');
                    if ($barcode) {
                        $byBarcode[$barcode] = $productId;
                    }
                }
            }

            // Check for next page
            $linkHeader = $response->header('Link');
            $url = $this->getNextPageUrl($linkHeader);

        } while ($url);

        Log::info("Fetched all Shopify products", ['byBarcode' => count($byBarcode), 'byTitle' => count($byTitle)]);

        return ['byBarcode' => $byBarcode, 'byTitle' => $byTitle];
    }

    /**
     * Extract next page URL from Link header
     */
    private function getNextPageUrl(?string $linkHeader): ?string
    {
        if (!$linkHeader) {
            return null;
        }

        $links = explode(',', $linkHeader);
        foreach ($links as $link) {
            if (str_contains($link, 'rel="next"')) {
                preg_match('/<(.+?)>/', $link, $matches);
                return $matches[1] ?? null;
            }
        }

        return null;
    }

    public function getOrCreateProduct(string $title, float $price, string $barcode = '', ?float $compareAtPrice = null): ?int
    {
        // First, check by barcode if provided
        if (!empty($barcode)) {
            $existing = $this->findProductByBarcode($barcode);
            if ($existing) {
                Log::info("Product found by barcode: {$barcode} (ID: {$existing})");
                return $existing;
            }
        }

        // Then check by title
        $existing = $this->findProductByTitle($title);
        if ($existing) {
            Log::info("Product found by title: {$title} (ID: {$existing})");
            return $existing;
        }

        return $this->createProduct($title, $price, $barcode, $compareAtPrice);
    }

    /**
     * Create product only (used with pre-fetched cache)
     */
    public function createProductOnly(string $title, float $price, string $barcode = '', ?float $compareAtPrice = null): ?int
    {
        return $this->createProduct($title, $price, $barcode, $compareAtPrice);
    }

    public function findProductByTitle(string $title): ?int
    {
        $res = $this->client()->get("https://{$this->shopDomain}/admin/api/{$this->apiVersion}/products.json", [
            'title' => $title,
            'limit' => 1
        ]);

        if ($res->failed()) return null;

        $products = $res->json('products') ?? [];
        return $products[0]['id'] ?? null;
    }

    public function findProductByBarcode(string $barcode): ?int
    {
        // Search for products by barcode using GraphQL or REST API
        $res = $this->client()->get("https://{$this->shopDomain}/admin/api/{$this->apiVersion}/products.json", [
            'fields' => 'id,variants',
            'limit' => 250
        ]);

        if ($res->failed()) return null;

        $products = $res->json('products') ?? [];

        foreach ($products as $product) {
            $variants = $product['variants'] ?? [];
            foreach ($variants as $variant) {
                if (isset($variant['barcode']) && $variant['barcode'] === $barcode) {
                    return $product['id'];
                }
            }
        }

        return null;
    }

    private function createProduct(string $title, float $price, string $barcode = '', ?float $compareAtPrice = null): ?int
    {
        $variant = [
            'price' => $price,
            'barcode' => $barcode,
            'inventory_management' => 'shopify',
            'inventory_policy' => 'continue'
        ];

        // Only add compare_at_price if it exists and is greater than price
        if ($compareAtPrice !== null && $compareAtPrice > $price) {
            $variant['compare_at_price'] = $compareAtPrice;
        }

        $data = [
            'product' => [
                'title' => $title,
                'status' => 'active', // ✅ ensure product is published
                'variants' => [$variant]
            ]
        ];

        $res = $this->client()->post("https://{$this->shopDomain}/admin/api/{$this->apiVersion}/products.json", $data);

        if ($res->failed()) {
            Log::error("❌ Failed to create product", ['response' => $res->body()]);
            return null;
        }

        return $res->json('product.id');
    }

    /* 🔹 Product → Collection Mapping */

    /**
     * Fetch all existing product-collection mappings
     * Returns array of "productId-collectionId" keys for fast lookup
     */
    public function getAllCollects(): array
    {
        $collectsMap = [];
        $url = "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/collects.json?limit=250";

        do {
            try {
                $response = $this->client()->get($url);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::error("❌ Connection failed to Shopify (getAllCollects)", ['error' => $e->getMessage()]);
                throw new \Exception("Unable to connect to Shopify. Please check your internet connection.");
            }

            if ($response->failed()) {
                Log::error("❌ Failed to fetch collects from Shopify", ['body' => $response->body()]);
                throw new \Exception("Failed to fetch product-collection mappings from Shopify.");
            }

            $collects = $response->json('collects') ?? [];

            foreach ($collects as $collect) {
                $key = $collect['product_id'] . '-' . $collect['collection_id'];
                $collectsMap[$key] = true;
            }

            // Check for next page
            $linkHeader = $response->header('Link');
            $url = $this->getNextPageUrl($linkHeader);

        } while ($url);

        Log::info("Fetched all collects", ['count' => count($collectsMap)]);

        return $collectsMap;
    }

    public function addProductToCollection(int $productId, int $collectionId, array &$collectsCache = []): bool
    {
        $key = "{$productId}-{$collectionId}";

        // Check cache first if provided
        if (isset($collectsCache[$key])) {
            return true;
        }

        $data = [
            'collect' => [
                'product_id' => $productId,
                'collection_id' => $collectionId
            ]
        ];

        try {
            $res = $this->client()->post("https://{$this->shopDomain}/admin/api/{$this->apiVersion}/collects.json", $data);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("❌ Connection failed to Shopify (addProductToCollection)", ['error' => $e->getMessage()]);
            return false;
        }

        if ($res->failed()) {
            // If it's a duplicate error, that's actually OK - product is already in collection
            $body = $res->body();
            if (str_contains($body, 'already exists') || str_contains($body, 'duplicate')) {
                $collectsCache[$key] = true;
                return true;
            }
            Log::error("❌ Failed to map product {$productId} to collection {$collectionId}", ['response' => $body]);
            return false;
        }

        // Add to cache
        $collectsCache[$key] = true;
        return true;
    }
}