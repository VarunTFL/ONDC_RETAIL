<?php

use Illuminate\Support\Facades\Route;
use Webkul\Ondc\Http\Controllers\ApiController;
use Webkul\Ondc\Http\Controllers\CancellationController;
use Webkul\Ondc\Http\Controllers\SellerCancellationController;
use Webkul\Ondc\Http\Controllers\PartialCancellationController;
use Webkul\Ondc\Http\Controllers\ReverseQcController;
use Webkul\Ondc\Http\Controllers\ReplacementExchangeController;
use Webkul\Ondc\Http\Controllers\UpdateController;
use Webkul\Ondc\Http\Controllers\FnbController;

/*
|--------------------------------------------------------------------------
| ONDC Protocol Routes (Network-facing, No Auth)
|--------------------------------------------------------------------------
*/
Route::group(['prefix' => 'ondc', 'middleware' => ['api']], function () {
    Route::post('/search',    [ApiController::class, 'search']);
    Route::post('/select',    [ApiController::class, 'select']);
    Route::post('/init',      [ApiController::class, 'init']);
    Route::post('/confirm',   [ApiController::class, 'confirm']);
    Route::post('/status',    [ApiController::class, 'status']);
    Route::post('/track',     [ApiController::class, 'track']);        // tracking
    Route::post('/cancel',    [CancellationController::class, 'cancel']);
    // Route::post('/cancel', [ApiController::class, 'cancel']);
    Route::post('/on_cancel', [CancellationController::class, 'onCancel']);
    Route::post('/update',    [UpdateController::class, 'update']);     // part-cancel / replacement / exchange
    Route::post('/on_update', [UpdateController::class, 'onUpdate']);
    
    // RSF 2.0 
    Route::post('/settle',    [RsfController::class, 'settle']);
    Route::post('/on_settle', [RsfController::class, 'onSettle']);
});

/*
|--------------------------------------------------------------------------
| Buyer-facing Cancellation REST APIs (for frontend apps)
|--------------------------------------------------------------------------
*/
Route::group(['prefix' => 'api/ondc/cancellation', 'middleware' => ['api']], function () {
    Route::get('/reasons',            [CancellationController::class, 'listReasons']);
    Route::get('/check/{ondcOrderId}', [CancellationController::class, 'checkEligibility']);
    Route::post('/initiate',          [CancellationController::class, 'initiate']);
    Route::get('/order/{ondcOrderId}', [CancellationController::class, 'byOrder']);
    Route::get('/terms/{ondcOrderId}', [CancellationController::class, 'terms']);

    // Seller-side cancellation
    Route::get('/seller/reasons',  [SellerCancellationController::class, 'listSellerReasons']);
    Route::post('/seller/initiate', [SellerCancellationController::class, 'initiate']);

    // RTO state machine
    Route::post('/{id}/rto/initiate',  [SellerCancellationController::class, 'initiateRto'])->where('id', '[0-9]+');
    Route::post('/{id}/rto/delivered', [SellerCancellationController::class, 'markRtoDelivered'])->where('id', '[0-9]+');
    Route::post('/{id}/rto/disposed',  [SellerCancellationController::class, 'markRtoDisposed'])->where('id', '[0-9]+');
    Route::get('/{id}/rto/timeline',   [SellerCancellationController::class, 'rtoTimeline'])->where('id', '[0-9]+');
    Route::post('/{id}/build-on-cancel', [SellerCancellationController::class, 'buildOnCancelPayload'])->where('id', '[0-9]+');

    // Partial cancellation
    Route::get('/partial/reasons',    [PartialCancellationController::class, 'partCancelReasons']);
    Route::post('/partial/initiate',  [PartialCancellationController::class, 'initiatePartial']);
    Route::get('/{id}/items',          [PartialCancellationController::class, 'listItems'])->where('id', '[0-9]+');
    Route::post('/{id}/build-on-update', [PartialCancellationController::class, 'buildOnUpdatePayload'])->where('id', '[0-9]+');

    // Liquidation state machine
    Route::post('/{id}/liquidation/request',  [PartialCancellationController::class, 'requestLiquidation'])->where('id', '[0-9]+');
    Route::post('/{id}/liquidation/interim',  [PartialCancellationController::class, 'interimLiquidation'])->where('id', '[0-9]+');
    Route::post('/{id}/liquidation/complete', [PartialCancellationController::class, 'completeLiquidation'])->where('id', '[0-9]+');
    Route::get('/{id}/liquidation/timeline',  [PartialCancellationController::class, 'liquidationTimeline'])->where('id', '[0-9]+');

    // Per-item disposition
    Route::post('/items/{itemId}/disposition', [PartialCancellationController::class, 'setItemDisposition'])->where('itemId', '[0-9]+');

    // Generic show — keep last so it doesn't shadow seller routes
    Route::get('/{id}', [CancellationController::class, 'show'])->where('id', '[0-9]+');
});

Route::get('/api/ondc/rto/active',         [SellerCancellationController::class, 'activeRtos'])->middleware('api');
Route::get('/api/ondc/liquidation/active', [PartialCancellationController::class, 'activeLiquidations'])->middleware('api');

/*
|--------------------------------------------------------------------------
| Reverse QC + Combined Flow APIs
|--------------------------------------------------------------------------
*/
Route::group(['prefix' => 'api/ondc/returns', 'middleware' => ['api']], function () {
    // Listings
    Route::get('/reasons',  [ReverseQcController::class, 'listReturnReasons']);
    Route::get('/active',   [ReverseQcController::class, 'activeReturns']);
    Route::get('/',         [ReverseQcController::class, 'listReturns']);

    // Buyer initiates return
    Route::post('/initiate', [ReverseQcController::class, 'initiateReturn']);

    // Combined flow (part cancel + return + QC + liquidation)
    Route::post('/combined-flow', [ReverseQcController::class, 'executeCombinedFlow']);

    // Per-return operations
    Route::get('/{id}',                  [ReverseQcController::class, 'showReturn'])->where('id', '[0-9]+');
    Route::get('/{id}/timeline',         [ReverseQcController::class, 'timeline'])->where('id', '[0-9]+');
    Route::get('/{id}/combined-status',  [ReverseQcController::class, 'combinedStatus'])->where('id', '[0-9]+');

    Route::post('/{id}/approve',         [ReverseQcController::class, 'approveReturn'])->where('id', '[0-9]+');
    Route::post('/{id}/reject',          [ReverseQcController::class, 'rejectReturn'])->where('id', '[0-9]+');
    Route::post('/{id}/picked-up',       [ReverseQcController::class, 'markPickedUp'])->where('id', '[0-9]+');
    Route::post('/{id}/qc/start',        [ReverseQcController::class, 'startQc'])->where('id', '[0-9]+');
    Route::post('/{id}/qc/pass',         [ReverseQcController::class, 'recordQcPass'])->where('id', '[0-9]+');
    Route::post('/{id}/qc/fail',         [ReverseQcController::class, 'recordQcFail'])->where('id', '[0-9]+');
    Route::post('/{id}/refund',          [ReverseQcController::class, 'processRefund'])->where('id', '[0-9]+');
});

/*
|--------------------------------------------------------------------------
| Replacement & Exchange APIs (Retail 1.2.5)
|--------------------------------------------------------------------------
*/
Route::group(['prefix' => 'api/ondc', 'middleware' => ['api']], function () {
    // Replacement
    Route::post('/replacement/initiate', [ReplacementExchangeController::class, 'initiateReplacement']);
    Route::get('/replacement/active',    [ReplacementExchangeController::class, 'activeReplacements']);

    // Exchange
    Route::post('/exchange/initiate',    [ReplacementExchangeController::class, 'initiateExchange']);
    Route::get('/exchange/active',       [ReplacementExchangeController::class, 'activeExchanges']);

    // Forward fulfillment management (replacement OR exchange)
    Route::post('/returns/{id}/forward/start',     [ReplacementExchangeController::class, 'startForwardFulfillment'])->where('id', '[0-9]+');
    Route::post('/forward/{rfId}/advance',         [ReplacementExchangeController::class, 'advanceFulfillment'])->where('rfId', '[0-9]+');
    Route::post('/forward/{rfId}/cancel',          [ReplacementExchangeController::class, 'cancelFulfillment'])->where('rfId', '[0-9]+');
    Route::get('/forward/{rfId}/timeline',         [ReplacementExchangeController::class, 'fulfillmentTimeline'])->where('rfId', '[0-9]+');

    // Exchange settlement (price difference)
    Route::post('/returns/{id}/settlement',        [ReplacementExchangeController::class, 'recordSettlement'])->where('id', '[0-9]+');
});

/*
|--------------------------------------------------------------------------
| F&B Flow APIs (ONDC:RET11)  — Discovery, Delivery, OOS, Cancel, RTO,
|                                Part Cancel, Liquidation
|--------------------------------------------------------------------------
*/
Route::group(['prefix' => 'api/ondc/fnb', 'middleware' => ['api']], function () {
    // Catalog management (admin)
    Route::get('/catalogs',              [FnbController::class, 'listCatalogs']);
    Route::get('/catalogs/{id}',         [FnbController::class, 'showCatalog'])->where('id', '[0-9]+');
    Route::post('/catalogs',             [FnbController::class, 'createCatalog']);
    Route::post('/catalogs/{id}/items',  [FnbController::class, 'addItem'])->where('id', '[0-9]+');
    Route::post('/items/{id}/availability', [FnbController::class, 'updateItemAvailability'])->where('id', '[0-9]+');

    // Flow 1 — Discovery
    Route::post('/search',          [FnbController::class, 'search']);
    Route::post('/build-search',    [FnbController::class, 'buildSearch']);

    // Flow 2 — Delivery (select → init → confirm → status)
    Route::post('/select',          [FnbController::class, 'select']);
    Route::post('/init',            [FnbController::class, 'init']);
    Route::post('/confirm',         [FnbController::class, 'confirm']);
    Route::post('/orders/{orderId}/advance', [FnbController::class, 'advanceStatus']);
    Route::get('/orders/{orderId}', [FnbController::class, 'getOrder']);

    // Flow 3 — Out of stock
    Route::post('/check-stock',     [FnbController::class, 'checkOutOfStock']);
    Route::get('/items/{itemId}/alternatives', [FnbController::class, 'suggestAlternatives']);

    // Flow 4 — Buyer cancel
    Route::get('/cancel/buyer/reasons', [FnbController::class, 'buyerReasons']);
    Route::post('/cancel/buyer',        [FnbController::class, 'buyerCancel']);

    // Flow 5 — Seller cancel + RTO
    Route::get('/cancel/seller/reasons', [FnbController::class, 'sellerReasons']);
    Route::post('/cancel/seller',        [FnbController::class, 'sellerCancel']);

    // Flow 6 — Part cancellation
    Route::post('/cancel/partial',  [FnbController::class, 'partCancel']);

    // Flow 7 — Liquidation
    Route::post('/cancel/{cancellationId}/liquidation', [FnbController::class, 'liquidation'])->where('cancellationId', '[0-9]+');
});