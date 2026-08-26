<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Users\ShopifyAuthLoginController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('shopify/webhook',[ShopifyAuthLoginController::class,'uninstall'])->name('uninstall');
Route::post('/shopify/webhooks/shop-update', [ShopifyAuthLoginController::class, 'shopUpdateWebhook'])->name('shopify.webhook.shop-update');
