<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ShopifyImportController;
use App\Http\Controllers\MegaMenuController;

// Landing page is the products dashboard
Route::get('/', [ShopifyImportController::class, 'index']);
Route::get('import', [ShopifyImportController::class, 'import']);
Route::get('mega-menu/download', [MegaMenuController::class, 'download']);