<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Services\ShopifyService;
use App\Models\SyncLog;

class ShopifyImportController extends Controller
{
    protected $shopify;

    public function __construct(ShopifyService $shopify)
    {
        $this->shopify = $shopify;
    }

    /**
     * Import Departments → SubDepartments → Products to Shopify
     */
    public function import(): JsonResponse
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        try {
            Log::info('Starting Shopify import process...');

            $departmentsResponse = $this->shopify->getDepartments();
            $subDepartmentsResponse = $this->shopify->getSubDepartments();
            $productsResponse = $this->shopify->getProducts();

            $departments = $departmentsResponse['Departments'] ?? $departmentsResponse ?? [];
            $subDepartments = $subDepartmentsResponse['Sub-Departments'] ?? $subDepartmentsResponse ?? [];
            $products = $productsResponse['Products'] ?? $productsResponse ?? [];

            if (empty($departments) || empty($subDepartments) || empty($products)) {
                return response()->json(['error' => 'Failed to fetch data from external API'], 500);
            }

            //Import Departments
            $collectionMap = [];
            $departmentsCreated = $departmentsSkipped = 0;

            foreach ($departments as $dept) {

                if (!is_array($dept)) continue;

                $depId = $dept['DepID'] ?? null;
                $depTitle = $dept['DepTitle'] ?? null;

                if (!$depId || !$depTitle) continue;

                $existingId = $this->shopify->findCollectionByTitle($depTitle);
                $collectionId = $this->shopify->getOrCreateCollection($depTitle);

                if ($collectionId) {
                    $collectionMap[$depId] = $collectionId;

                    if ($existingId) {
                        $departmentsSkipped++;
                        Log::info("Department already exists: {$depTitle}");
                    } else {
                        $departmentsCreated++;
                        Log::info("Department created: {$depTitle}");
                    }
                }
            }

            //Import Sub Departments
            $subCollectionMap = [];
            $subDepartmentsCreated = $subDepartmentsSkipped = 0;

            foreach ($subDepartments as $sub) {
                if (!is_array($sub)) continue;

                $subId = $sub['SubID'] ?? null;
                $subTitle = $sub['SubTitle'] ?? null;
                $depId = $sub['DepID'] ?? null;

                if (!$subId || !$subTitle || !$depId) continue;

                $parentCollectionId = $collectionMap[$depId] ?? null;
                $existingSubId = $this->shopify->findCollectionByTitle($subTitle);
                $subCollectionId = $this->shopify->getOrCreateCollection($subTitle, '', $parentCollectionId);

                if ($subCollectionId) {
                    $subCollectionMap[$subId] = $subCollectionId;

                    if ($existingSubId) {
                        $subDepartmentsSkipped++;
                        Log::info("Sub-department already exists: {$subTitle}");
                    } else {
                        $subDepartmentsCreated++;
                        Log::info("Sub-department created: {$subTitle}");
                    }
                }
            }

            //Import Products
            $productsCreated = $productsSkipped = 0;

            // Pre-fetch all existing Shopify products for fast lookup
            Log::info("Fetching existing Shopify products for cache...");
            $existingProducts = $this->shopify->getAllShopifyProducts();
            $existingByBarcode = $existingProducts['byBarcode'];
            $existingByTitle = $existingProducts['byTitle'];
            Log::info("Product cache ready", ['barcodes' => count($existingByBarcode), 'titles' => count($existingByTitle)]);

            // Pre-fetch all existing product-collection mappings
            Log::info("Fetching existing product-collection mappings...");
            $collectsCache = $this->shopify->getAllCollects();
            Log::info("Collects cache ready", ['count' => count($collectsCache)]);

            foreach ($products as $index => $product) {
                // Handle nested product arrays (some APIs return grouped data)
                $productList = (is_array($product) && isset($product[0]) && is_array($product[0]))
                    ? $product
                    : [$product];

                foreach ($productList as $item) {
                    if (!is_array($item)) continue;

                    $title = $item['Description'] ?? null;
                    $barcode = trim($item['Barcode'] ?? '');
                    $depId = $item['DepID'] ?? null;
                    $subId = $item['SubID'] ?? null;

                    // Price logic:
                    // - If StorePromoPrice exists: use it as price, and StoreUnitPrice (or WebUnitPrice) as compare_at_price
                    // - Otherwise: use StoreUnitPrice or WebUnitPrice as price, no compare_at_price
                    $storePromoPrice = $item['StorePromoPrice'] ?? null;
                    $storeUnitPrice = $item['StoreUnitPrice'] ?? null;
                    $webUnitPrice = $item['WebUnitPrice'] ?? 0;

                    $compareAtPrice = null;

                    if (!empty($storePromoPrice) && $storePromoPrice > 0) {
                        // StorePromoPrice exists - show two prices
                        $price = (float) $storePromoPrice;
                        $compareAtPrice = (float) ($storeUnitPrice ?? $webUnitPrice);
                    } else {
                        // No promo - use StoreUnitPrice or WebUnitPrice as the single price
                        $price = (float) ($storeUnitPrice ?? $webUnitPrice);
                    }

                    if (!$title) continue;

                    // Check if product exists using pre-fetched cache (no API calls!)
                    $existingProductId = null;
                    $titleKey = strtolower(trim($title));

                    if (!empty($barcode) && isset($existingByBarcode[$barcode])) {
                        $existingProductId = $existingByBarcode[$barcode];
                    } elseif (isset($existingByTitle[$titleKey])) {
                        $existingProductId = $existingByTitle[$titleKey];
                    }

                    if ($existingProductId) {
                        // Product already exists, skip creation
                        $productsSkipped++;
                        $productId = $existingProductId;
                    } else {
                        // Create new product
                        $productId = $this->shopify->createProductOnly($title, $price, $barcode, $compareAtPrice);

                        if ($productId) {
                            $productsCreated++;
                            // Add to cache for future iterations
                            if (!empty($barcode)) {
                                $existingByBarcode[$barcode] = $productId;
                            }
                            $existingByTitle[$titleKey] = $productId;
                        }
                    }

                    if ($productId) {
                        $added = false;

                        if (isset($collectionMap[$depId])) {
                            $added = $this->shopify->addProductToCollection($productId, $collectionMap[$depId], $collectsCache);
                        }

                        if (isset($subCollectionMap[$subId])) {
                            $added = $this->shopify->addProductToCollection($productId, $subCollectionMap[$subId], $collectsCache) || $added;
                        }

                        if (!$added) {
                            Log::warning("Product created but not mapped: {$title}");
                        }
                    }
                }

                // Reduced sleep since we're making fewer API calls now
                if ($index % 10 === 0) {
                    usleep(100000);
                }
            }

            // Build a set of all product identifiers from the API (barcodes and titles)
            $apiProductBarcodes = [];
            $apiProductTitles = [];

            foreach ($products as $product) {
                $productList = (is_array($product) && isset($product[0]) && is_array($product[0]))
                    ? $product
                    : [$product];

                foreach ($productList as $item) {
                    if (!is_array($item)) continue;

                    $title = $item['Description'] ?? null;
                    $barcode = trim($item['Barcode'] ?? '');

                    if (!empty($barcode)) {
                        $apiProductBarcodes[$barcode] = true;
                    }
                    if (!empty($title)) {
                        $apiProductTitles[strtolower(trim($title))] = true;
                    }
                }
            }

            // Delete products from Shopify that are not in the API response
            $productsDeleted = 0;
            Log::info("Checking for orphaned products to delete...");

            // Re-fetch fresh Shopify products to get accurate list
            $shopifyProducts = $this->shopify->getAllShopifyProducts();

            foreach ($shopifyProducts['byBarcode'] as $barcode => $productId) {
                // If barcode exists in Shopify but not in API, delete it
                if (!isset($apiProductBarcodes[$barcode])) {
                    // Double-check: also check if title exists in API
                    $titleKey = array_search($productId, $shopifyProducts['byTitle']);
                    if ($titleKey === false || !isset($apiProductTitles[$titleKey])) {
                        if ($this->shopify->deleteProduct($productId)) {
                            $productsDeleted++;
                            Log::info("Deleted orphaned product: {$productId} (barcode: {$barcode})");
                        }
                    }
                }
            }

            // Also check products that only have title (no barcode)
            foreach ($shopifyProducts['byTitle'] as $title => $productId) {
                // Skip if already deleted via barcode check
                if (in_array($productId, array_values($shopifyProducts['byBarcode']))) {
                    continue;
                }

                // If title exists in Shopify but not in API, delete it
                if (!isset($apiProductTitles[$title])) {
                    if ($this->shopify->deleteProduct($productId)) {
                        $productsDeleted++;
                        Log::info("Deleted orphaned product: {$productId} (title: {$title})");
                    }
                }
            }

            Log::info("Shopify import completed.", [
                'departments' => ['created' => $departmentsCreated, 'skipped' => $departmentsSkipped],
                'sub_departments' => ['created' => $subDepartmentsCreated, 'skipped' => $subDepartmentsSkipped],
                'products' => ['created' => $productsCreated, 'skipped' => $productsSkipped, 'deleted' => $productsDeleted],
            ]);

            // Record sync time
            SyncLog::recordSync('sync_products');

            return response()->json([
                'message' => 'Import completed successfully',
                'departments' => [
                    'created' => $departmentsCreated,
                    'skipped' => $departmentsSkipped,
                    'total' => $departmentsCreated + $departmentsSkipped
                ],
                'sub_departments' => [
                    'created' => $subDepartmentsCreated,
                    'skipped' => $subDepartmentsSkipped,
                    'total' => $subDepartmentsCreated + $subDepartmentsSkipped
                ],
                'products' => [
                    'created' => $productsCreated,
                    'skipped' => $productsSkipped,
                    'deleted' => $productsDeleted,
                    'total' => $productsCreated + $productsSkipped
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Shopify import failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'An error occurred during import',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display products page with UI
     */
    public function index()
    {
        $lastProductSync = SyncLog::getLastSync('sync_products');
        $lastMegaMenuSync = SyncLog::getLastSync('mega_menu');

        return view('products.index', compact('lastProductSync', 'lastMegaMenuSync'));
    }
}