<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MegaMenuService
{
    private $shopifyDomain;
    private $shopifyAccessToken;
    private $apiBaseUrl;
    private TokenService $tokenService;

    public function __construct()
    {
        $this->shopifyDomain = env('SHOPIFY_SHOP_DOMAIN');
        $this->shopifyAccessToken = env('SHOPIFY_API_PASSWORD');
        $this->apiBaseUrl = env('EXTERNAL_API_BASE_URL', 'http://14.203.153.238:58200/rest');
        $this->tokenService = new TokenService();
    }

    /**
     * Generate the complete mega menu structure
     * Returns an array with 'menu' data and 'stats' for logging
     */
    public function generate(): array
    {
        $stats = [
            'collections' => 0,
            'departments' => 0,
            'sub_departments' => 0,
            'categories' => 0,
            'logs' => []
        ];

        // Step 1: Get all Shopify collections with products
        $collections = $this->getShopifyCollections($stats);
        $stats['collections'] = count($collections);

        // Step 2: Get departments from API
        $departments = $this->getDepartments();
        $stats['departments'] = count($departments);

        // Step 3: Get sub-departments from API
        $subDepartments = $this->getSubDepartments();
        $stats['sub_departments'] = count($subDepartments);

        // Step 4: Generate mega menu JSON
        $megaMenu = $this->generateQikifyMegaMenu($collections, $departments, $subDepartments, $stats);

        return [
            'menu' => $megaMenu,
            'stats' => $stats
        ];
    }

    /**
     * Get all Shopify collections that have products
     */
    private function getShopifyCollections(array &$stats): array
    {
        $collections = [];

        // Get custom collections
        $customCollections = $this->fetchCollections('custom_collections', $stats);
        $collections = array_merge($collections, $customCollections);

        // Get smart collections
        $smartCollections = $this->fetchCollections('smart_collections', $stats);
        $collections = array_merge($collections, $smartCollections);

        return $collections;
    }

    /**
     * Fetch collections by type
     */
    private function fetchCollections(string $type, array &$stats): array
    {
        $collections = [];
        $url = "https://{$this->shopifyDomain}/admin/api/2025-07/{$type}.json?limit=250";

        do {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $this->shopifyAccessToken,
                'Content-Type' => 'application/json',
            ])->get($url);

            if (!$response->successful()) {
                throw new \Exception("Failed to fetch {$type}: " . $response->body());
            }

            $data = $response->json();

            foreach ($data[$type] ?? [] as $collection) {
                $productCount = $this->getCollectionProductCount($collection['id']);

                // Only include collections with products
                if ($productCount > 0) {
                    $collections[] = [
                        'id' => (string) $collection['id'],
                        'title' => $collection['title'],
                        'handle' => $collection['handle'],
                        'product_count' => $productCount
                    ];
                }
            }

            $linkHeader = $response->header('Link');
            $url = $this->getNextPageUrl($linkHeader);

        } while ($url);

        return $collections;
    }

    /**
     * Get product count for a collection
     */
    private function getCollectionProductCount(int $collectionId): int
    {
        // Use collects endpoint to count products in custom collections
        $url = "https://{$this->shopifyDomain}/admin/api/2025-07/collects/count.json?collection_id={$collectionId}";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->shopifyAccessToken,
            'Content-Type' => 'application/json',
        ])->get($url);

        if (!$response->successful()) {
            // Fallback: try products endpoint for smart collections
            $url2 = "https://{$this->shopifyDomain}/admin/api/2025-07/products/count.json?collection_id={$collectionId}";
            $response2 = Http::withHeaders([
                'X-Shopify-Access-Token' => $this->shopifyAccessToken,
                'Content-Type' => 'application/json',
            ])->get($url2);

            if ($response2->successful()) {
                return $response2->json()['count'] ?? 0;
            }
            return 0;
        }

        return $response->json()['count'] ?? 0;
    }

    /**
     * Get departments from API with automatic token refresh
     */
    private function getDepartments(): array
    {
        return $this->tokenService->withAutoRefresh(function ($token) {
            $url = "{$this->apiBaseUrl}/GetDepList/JSON?token={$token}";

            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                throw new \Exception('Failed to fetch departments: ' . $response->body());
            }

            $data = $response->json();

            // Check for token error in response
            if (isset($data['Message']) && str_contains(strtolower($data['Message']), 'token')) {
                throw new \Exception($data['Message']);
            }

            // Handle wrapped response (e.g., {"Departments": [...]})
            return $data['Departments'] ?? $data;
        });
    }

    /**
     * Get sub-departments from API with automatic token refresh
     */
    private function getSubDepartments(): array
    {
        return $this->tokenService->withAutoRefresh(function ($token) {
            $url = "{$this->apiBaseUrl}/GetSubList/JSON?token={$token}";

            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                throw new \Exception('Failed to fetch sub-departments: ' . $response->body());
            }

            $data = $response->json();

            // Check for token error in response
            if (isset($data['Message']) && str_contains(strtolower($data['Message']), 'token')) {
                throw new \Exception($data['Message']);
            }

            // Handle wrapped response (e.g., {"Sub-Departments": [...]})
            return $data['Sub-Departments'] ?? $data;
        });
    }

    /**
     * Generate random menu ID
     */
    private function generateMenuId(): string
    {
        return 'tmenu-menu-' . rand(100000, 999999);
    }

    /**
     * Generate Qikify mega menu structure
     * Structure: Products -> Categories (hardcoded) -> Departments (matched by category) -> Sub-Departments (linked to collections)
     */
    private function generateQikifyMegaMenu(array $collections, array $departments, array $subDepartments, array &$stats): array
    {
        $collectionMap = $this->createCollectionMap($collections);

        // Hardcoded categories with their Group IDs
        $categories = [
            'Aquarium' => 'd64323da-f13b-409e-84c6-a92921c62dd8',
            'Birds' => '422687da-6d95-47d2-88b5-924650c5ad30',
            'Cat' => '7b017cc9-100a-4eeb-88be-f744683695ae',
            'Dog' => '4e9f4017-d7d6-4bc6-b433-6a649ef804d2',
            'Gardening' => 'ff114529-bf9d-4990-9f8d-c4d8a3f4c501',
            'Ponds' => '9e11904f-e255-45a3-b233-6f605d45692c',
            'POULTRY' => 'f82a1edc-5128-4a48-9be2-b47422995291',
            'Small Animals' => 'c891aae3-01f3-427d-9011-d878ce6635ca',
            'STOCKFEED' => '27ec004b-1de3-499a-a696-e39b318d3215',
        ];

        // Build category menus (Level 1 under Products)
        $categoryMenus = [];

        foreach ($categories as $categoryName => $groupId) {
            $stats['logs'][] = "Processing category: {$categoryName}";

            // Find departments belonging to this group
            $matchingDepartments = array_filter($departments, function($dept) use ($groupId) {
                return ($dept['GrpID'] ?? null) === $groupId;
            });

            // Build department menus (Level 2)
            $departmentMenus = [];
            foreach ($matchingDepartments as $department) {
                $departmentName = $department['DepTitle'] ?? $department['DepName'] ?? $department['name'] ?? null;
                $departmentId = $department['DepID'] ?? $department['id'] ?? null;

                if (!$departmentName || !$departmentId) {
                    continue;
                }

                // Get sub-departments for this department (match by DepID)
                $deptSubItems = array_values(array_filter($subDepartments, function($sub) use ($departmentId) {
                    $subDepId = $sub['DepID'] ?? $sub['department_id'] ?? null;
                    return $subDepId == $departmentId; // Use loose comparison
                }));

                // Build sub-department menus (Level 3)
                $subDeptMenus = [];
                foreach ($deptSubItems as $subDept) {
                    // Get sub-department title
                    $subDeptName = $subDept['SubTitle'] ?? $subDept['SubDepTitle'] ?? $subDept['SubDepName'] ?? $subDept['Title'] ?? $subDept['name'] ?? null;

                    if (!$subDeptName) {
                        continue;
                    }

                    // Skip if sub-department name is same as department name (avoid duplicates)
                    if (strtoupper(trim($subDeptName)) === strtoupper(trim($departmentName))) {
                        continue;
                    }

                    // Find matching Shopify collection for this sub-department
                    $matchedCollection = $this->findMatchingCollection($subDeptName, $collectionMap);

                    // Skip sub-departments without a matching collection (no products)
                    if (!$matchedCollection) {
                        continue;
                    }

                    // Build sub-department menu with collection URL
                    $subDeptMenu = [
                        'id' => $this->generateMenuId(),
                        'setting' => [
                            'item_layout' => 'text',
                            'title' => $subDeptName,
                            'url' => [
                                'type' => [
                                    'id' => 'collection'
                                ],
                                'collection' => [
                                    'id' => $matchedCollection['id'],
                                    'title' => $matchedCollection['title'],
                                    'handle' => $matchedCollection['handle']
                                ]
                            ]
                        ],
                        'menus' => []
                    ];

                    $subDeptMenus[] = $subDeptMenu;
                }

                // Find matching Shopify collection for this department
                $deptCollection = $this->findMatchingCollection($departmentName, $collectionMap);

                // Skip departments without any sub-departments AND without a matching collection
                if (empty($subDeptMenus) && !$deptCollection) {
                    continue;
                }

                // Build department menu
                $deptMenu = [
                    'id' => $this->generateMenuId(),
                    'setting' => [
                        'item_layout' => 'text',
                        'title' => $departmentName
                    ],
                    'menus' => $subDeptMenus,
                    'hide_submenu' => false
                ];

                // Add collection URL to department if found
                if ($deptCollection) {
                    $deptMenu['setting']['url'] = [
                        'type' => [
                            'id' => 'collection'
                        ],
                        'collection' => [
                            'id' => $deptCollection['id'],
                            'title' => $deptCollection['title'],
                            'handle' => $deptCollection['handle']
                        ]
                    ];
                }

                $departmentMenus[] = $deptMenu;
            }

            // Add category with its departments if there are any
            if (count($departmentMenus) > 0) {
                $categoryMenu = [
                    'id' => $this->generateMenuId(),
                    'setting' => [
                        'item_layout' => 'text',
                        'title' => $categoryName
                    ],
                    'menus' => $departmentMenus,
                    'hide_submenu' => false
                ];

                $categoryMenus[] = $categoryMenu;
                $stats['categories']++;
                $stats['logs'][] = "Added category: {$categoryName} with " . count($departmentMenus) . " departments";
            }
        }

        // Build the root "Products" menu
        $productsMenu = [
            'id' => $this->generateMenuId(),
            'setting' => [
                'item_layout' => 'text',
                'submenu_type' => 'flyout',
                'submenu_mega_position' => 'fullwidth',
                'title' => 'Products',
                'submenu_flyout_width' => '200'
            ],
            'menus' => $categoryMenus,
            'hide_submenu' => false
        ];

        // Build complete Qikify mega menu structure
        return [
            'menu_selector' => 'selector',
            'theme_selector' => 'all',
            'transition' => 'fade',
            'trigger' => 'hover',
            'show_indicator' => true,
            'show_mobile_indicator' => true,
            'submenu_background' => '#ffffff',
            'item_color' => '#000000',
            'item_hover_color' => '#000000',
            'item_header_border' => '#000000',
            'price_color' => '#00992b',
            'menu_height' => 50,
            'alignment' => 'left',
            'root_typography' => [
                'fontFamily' => '',
                'fontSize' => '14',
                'letterSpacing' => '0'
            ],
            'root_padding' => 10,
            'submenu_fullwidth' => true,
            'typography' => [
                'fontFamily' => '',
                'fontSize' => '14',
                'letterSpacing' => '0'
            ],
            'megamenu' => [$productsMenu],
            'navigator' => [
                'id' => 'main-menu',
                'title' => 'Main menu',
                'items' => [
                    '/',
                    '/collections/all',
                    '/collections',
                    '/pages/contact'
                ]
            ],
            'enable_quickview' => false,
            'orientation' => 'horizontal',
            'mobile_navigator' => [
                'id' => 'main-menu',
                'title' => 'Main menu',
                'items' => [
                    '/',
                    '/collections/all',
                    '/collections',
                    '/pages/contact'
                ]
            ],
            'navigator_selector' => '.header__inline-menu',
            'mobile_navigator_selector' => '.header__inline-menu',
            'menu_wrap' => true
        ];
    }

    /**
     * Create a searchable map of collections
     */
    private function createCollectionMap(array $collections): array
    {
        $map = [];
        foreach ($collections as $collection) {
            $key = strtolower(trim($collection['title']));
            $map[$key] = $collection;

            // Also add by handle
            $handleKey = strtolower(trim($collection['handle']));
            $map[$handleKey] = $collection;
        }
        return $map;
    }

    /**
     * Find matching collection by name (with partial matching)
     */
    private function findMatchingCollection(string $name, array $collectionMap): ?array
    {
        $searchKey = strtolower(trim($name));

        // Exact match
        if (isset($collectionMap[$searchKey])) {
            return $collectionMap[$searchKey];
        }

        // Handle match (convert spaces to dashes)
        $handleKey = strtolower(str_replace(' ', '-', trim($name)));
        if (isset($collectionMap[$handleKey])) {
            return $collectionMap[$handleKey];
        }

        // Partial match
        foreach ($collectionMap as $key => $collection) {
            if (str_contains($key, $searchKey) || str_contains($searchKey, $key)) {
                return $collection;
            }
        }

        return null;
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
}
