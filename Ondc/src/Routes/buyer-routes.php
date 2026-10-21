<?php

use Illuminate\Support\Facades\Route;
use Webkul\Ondc\Http\Controllers\Buyer\LatestOnSearchController;
use Webkul\Ondc\Http\Controllers\Buyer\OnSearchController;
use Webkul\Ondc\Http\Controllers\Buyer\OndcPollController;
use Webkul\Ondc\Http\Controllers\Buyer\SearchOndcController;
use Webkul\Ondc\Http\Controllers\Shop\OndcProductController;

Route::group(['prefix' => 'ondc', 'middleware' => ['api']], function () {

    /*
    |--------------------------------------------------------------------------
    | Seller NP → Buyer NP (direct callback from seller, kept for compatibility)
    |--------------------------------------------------------------------------
    */
    Route::post('/buyer/on_search', [OnSearchController::class, 'onSearch']);

    /*
    |--------------------------------------------------------------------------
    | Switch-based search + poll — Category page (category slug)
    | Frontend calls this once per category page load.
    |--------------------------------------------------------------------------
    */
    Route::post('/buyer/search-and-poll', [OndcPollController::class, 'searchAndPoll']);

    /*
    |--------------------------------------------------------------------------
    | Switch-based search + poll — Search page (free keyword)
    | Frontend calls this once per search query.
    |
    | POST /ondc/buyer/keyword-search
    | Body: { "keyword": "laptop", "domain": "ONDC:RET14" }
    |--------------------------------------------------------------------------
    */
    Route::post('/buyer/keyword-search', [SearchOndcController::class, 'search']);

    /*
    |--------------------------------------------------------------------------
    | AJAX Product Fetch (reads from ondc_items table)
    |--------------------------------------------------------------------------
    */
    Route::get('/products/{categorySlug}', [OndcProductController::class, 'products']);

    /*
    |--------------------------------------------------------------------------
    | Dev / Debug: latest raw on_search payload
    |--------------------------------------------------------------------------
    */
    Route::get('/buyer/latest-on-search', [LatestOnSearchController::class, 'index']);

    
});
//added new
/*
|--------------------------------------------------------------------------
| Shop-facing ONDC Product Detail Page
|--------------------------------------------------------------------------
*/
Route::group(['prefix' => 'ondc', 'middleware' => ['web']], function () {
    Route::get('/product/{id}', [OndcProductController::class, 'show'])
        ->name('ondc.shop.product.show');
});

Route::group(['prefix' => 'ondc', 'middleware' => ['web']], function () {
    Route::get('/product/{id}', [OndcProductController::class, 'show'])
        ->name('ondc.shop.product.show');

    // ADD THIS:
    Route::post('/buyer/select', [\Webkul\Ondc\Http\Controllers\Buyer\OndcSelectController::class, 'select'])
        ->name('ondc.buyer.select');
});