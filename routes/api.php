<?php

use App\Http\Controllers\ShopifyOAuthController;
use Illuminate\Support\Facades\Route;

// POST /api/internal/shopify/claim — dashboard backend'den, server-to-server, bearer secret ile korunur
Route::post('/internal/shopify/claim', [ShopifyOAuthController::class, 'claim']);
