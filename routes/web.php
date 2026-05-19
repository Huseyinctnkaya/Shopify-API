<?php

use App\Http\Controllers\ShopifyOAuthController;
use Illuminate\Support\Facades\Route;

// GET /oauth/install?shop=magaza.myshopify.com&client_id=X
Route::get('/oauth/install',  [ShopifyOAuthController::class, 'install']);

// GET /oauth/callback  (Shopify buraya yönlendirir)
Route::get('/oauth/callback', [ShopifyOAuthController::class, 'callback']);
