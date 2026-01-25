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
            $productsCreated = $productsSkipped = $productsUpdated = 0;

            // Pre-fetch all existing Shopify products for fast lookup
            Log::info("Fetching existing Shopify products for cache...");
            $existingProducts = $this->shopify->getAllShopifyProducts();
            $existingByBarcode = $existingProducts['byBarcode'];
            $existingByTitle = $existingProducts['byTitle'];
            $shopifyProductsData = $existingProducts['products'] ?? [];
            Log::info("Product cache ready", ['barcodes' => count($existingByBarcode), 'titles' => count($existingByTitle)]);

            // Pre-fetch all existing product-collection mappings
            Log::info("Fetching existing product-collection mappings...");
            $collectsCache = $this->shopify->getAllCollects();
            Log::info("Collects cache ready", ['count' => count($collectsCache)]);

            // Track API products for deletion check (build during main loop)
            $apiProductBarcodes = [];
            $apiProductTitles = [];
            $processedProductIds = []; // Track which Shopify products we've seen

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

                    if (!$title) continue;

                    $titleKey = strtolower(trim($title));

                    // Track for deletion check
                    if (!empty($barcode)) {
                        $apiProductBarcodes[$barcode] = true;
                    }
                    $apiProductTitles[$titleKey] = true;

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

                    // Check if product exists using pre-fetched cache (no API calls!)
                    $existingProductId = null;

                    if (!empty($barcode) && isset($existingByBarcode[$barcode])) {
                        $existingProductId = $existingByBarcode[$barcode];
                    } elseif (isset($existingByTitle[$titleKey])) {
                        $existingProductId = $existingByTitle[$titleKey];
                    }

                    if ($existingProductId) {
                        // Product exists - check if it needs updating
                        $productId = $existingProductId;
                        $processedProductIds[$existingProductId] = true;
                        $existingData = $shopifyProductsData[$existingProductId] ?? null;

                        if ($existingData) {
                            // Compare all fields to see if update is needed
                            $needsUpdate = false;

                            // Title
                            if ($existingData['title'] !== $title) {
                                $needsUpdate = true;
                            }
                            // Price
                            if (abs($existingData['price'] - $price) > 0.01) {
                                $needsUpdate = true;
                            }
                            // Compare at price
                            $existingCompare = $existingData['compare_at_price'] ?? 0;
                            $newCompare = $compareAtPrice ?? 0;
                            if (abs($existingCompare - $newCompare) > 0.01) {
                                $needsUpdate = true;
                            }
                            // Body HTML / Description
                            $bodyHtml = $item['WebDesc'] ?? '';
                            if (!empty($bodyHtml) && ($existingData['body_html'] ?? '') !== $bodyHtml) {
                                $needsUpdate = true;
                            }

                            if ($needsUpdate) {
                                $variantId = $existingData['variant_id'] ?? null;
                                if ($this->shopify->updateProduct($existingProductId, $title, $price, $barcode, $compareAtPrice, $bodyHtml, $variantId)) {
                                    $productsUpdated++;
                                    Log::info("Updated product: {$title}");
                                }
                            } else {
                                $productsSkipped++;
                            }
                        } else {
                            $productsSkipped++;
                        }
                    } else {
                        // Create new product
                        $productId = $this->shopify->createProductOnly($title, $price, $barcode, $compareAtPrice);

                        if ($productId) {
                            $productsCreated++;
                            $processedProductIds[$productId] = true;
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
                if ($index % 20 === 0) {
                    usleep(50000);
                }
            }

            // Delete products from Shopify that are not in the API response
            // Use the already-fetched data instead of re-fetching
            $productsDeleted = 0;
            Log::info("Checking for orphaned products to delete...");

            $deletedIds = [];
            foreach ($existingByBarcode as $barcode => $productId) {
                // If barcode exists in Shopify but not in API, delete it
                if (!isset($apiProductBarcodes[$barcode]) && !isset($deletedIds[$productId])) {
                    // Double-check: also check if title exists in API
                    $titleKey = array_search($productId, $existingByTitle);
                    if ($titleKey === false || !isset($apiProductTitles[$titleKey])) {
                        if ($this->shopify->deleteProduct($productId)) {
                            $productsDeleted++;
                            $deletedIds[$productId] = true;
                            Log::info("Deleted orphaned product: {$productId} (barcode: {$barcode})");
                        }
                    }
                }
            }

            // Also check products that only have title (no barcode)
            foreach ($existingByTitle as $title => $productId) {
                // Skip if already deleted
                if (isset($deletedIds[$productId])) {
                    continue;
                }

                // If title exists in Shopify but not in API, delete it
                if (!isset($apiProductTitles[$title])) {
                    if ($this->shopify->deleteProduct($productId)) {
                        $productsDeleted++;
                        $deletedIds[$productId] = true;
                        Log::info("Deleted orphaned product: {$productId} (title: {$title})");
                    }
                }
            }

            Log::info("Shopify import completed.", [
                'departments' => ['created' => $departmentsCreated, 'skipped' => $departmentsSkipped],
                'sub_departments' => ['created' => $subDepartmentsCreated, 'skipped' => $subDepartmentsSkipped],
                'products' => ['created' => $productsCreated, 'updated' => $productsUpdated, 'skipped' => $productsSkipped, 'deleted' => $productsDeleted],
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
                    'updated' => $productsUpdated,
                    'skipped' => $productsSkipped,
                    'deleted' => $productsDeleted,
                    'total' => $productsCreated + $productsUpdated + $productsSkipped
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