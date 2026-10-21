<?php

use Illuminate\Support\Facades\Route;
use Webkul\Ondc\Http\Controllers\Admin\OndcProductController;
use Webkul\Ondc\Http\Controllers\Admin\ProductConfirmationController;
use Webkul\Ondc\Http\Controllers\Admin\ReturnManagementController;
use Webkul\Ondc\DataGrids\OndcProductDataGrid;
use Webkul\Ondc\DataGrids\OrderStatusDataGrid;
use Webkul\Ondc\Http\Controllers\ApiController;

Route::group([
    'middleware' => ['web', 'admin'],
    'prefix' => 'admin/ondc',
], function () {    

    Route::get('/products', [OndcProductController::class, 'index'])->name('admin.ondc.products.index');
    Route::post('/products/store', [OndcProductController::class, 'store'])->name('admin.ondc.products.store');
    Route::get('/products/data', function () {return app()->make(OndcProductDataGrid::class)->toJson();})->name('admin.ondc.products.data');
    
    Route::get('/products/conformance', [ProductConfirmationController::class, 'index'])->name('admin.ondc.products.conformance');
    Route::get('/products/conformance/data', function () {return app()->make(OrderStatusDataGrid::class)->toJson();})->name('admin.ondc.products.conformance.data');
    // Route::get('/products/conformance/data',[ProductConfirmationController::class, 'conformanceData'])->name('admin.ondc.products.conformance.data');

    Route::post('/products/conformance/status', [ProductConfirmationController::class, 'updateStatus'])->name('admin.ondc.products.conformance.status');
    Route::post('/orders/{order_id}/packed', [ProductConfirmationController::class, 'markPacked'])->name('admin.ondc.orders.packed');
    Route::post('/orders/{order_id}/agent-assign', [ProductConfirmationController::class, 'markAgentAssigned'])->name('admin.ondc.orders.agent-assign');
    Route::post('/orders/{order_id}/picked', [ProductConfirmationController::class, 'markPicked'])->name('admin.ondc.orders.picked');
    Route::post('/orders/{order_id}/out-for-delivery', [ProductConfirmationController::class, 'markOutForDelivery'])->name('admin.ondc.orders.out-for-delivery');
    Route::post('/orders/{order_id}/delivered', [ProductConfirmationController::class, 'markDelivered'])->name('admin.ondc.orders.delivered');
    // temporary
   Route::post('/orders/{order_id}/rto-delivered', [ProductConfirmationController::class, 'markRtoDelivered'])->name('admin.ondc.orders.rto-delivered');

    // Cancellation (admin / seller-side)
    Route::get('/orders/{order_id}/cancel-data',     [ProductConfirmationController::class, 'cancelData'])->name('admin.ondc.orders.cancel-data');
    Route::post('/orders/{order_id}/cancel',         [ProductConfirmationController::class, 'cancelOrder'])->name('admin.ondc.orders.cancel');
    Route::post('/orders/{order_id}/cancel/partial', [ProductConfirmationController::class, 'partialCancelOrder'])->name('admin.ondc.orders.cancel.partial');

    // Reverse QC / Return management (admin / seller-side)
    Route::post('/returns/{returnRequestId}/approve', [ReturnManagementController::class, 'approve'])->name('admin.ondc.returns.approve');
    Route::post('/returns/{returnRequestId}/reject', [ReturnManagementController::class, 'reject'])->name('admin.ondc.returns.reject');
    Route::post('/returns/{returnRequestId}/picked', [ReturnManagementController::class, 'markPicked'])->name('admin.ondc.returns.picked');
    Route::post('/returns/{returnRequestId}/qc-pass', [ReturnManagementController::class, 'qcPass'])->name('admin.ondc.returns.qc-pass');
    Route::post('/returns/{returnRequestId}/qc-fail', [ReturnManagementController::class, 'qcFail'])->name('admin.ondc.returns.qc-fail');

    // Liquidation (admin / seller-side)
    Route::post('/cancellations/{cancellationId}/liquidation/request', [ReturnManagementController::class, 'requestLiquidation'])->name('admin.ondc.cancellations.liquidation.request');
    Route::post('/cancellations/{cancellationId}/liquidation/complete', [ReturnManagementController::class, 'completeLiquidation'])->name('admin.ondc.cancellations.liquidation.complete');

    // for testingt workbench
    Route::post(
    '/test/on-status/{order_id}/{status}',
    [ProductConfirmationController::class, 'testOnStatus']
)->name('admin.ondc.test.on-status');
});

/*
|--------------------------------------------------------------------------
| ONDC API Routes (Public - No Auth Middleware)
|--------------------------------------------------------------------------
*/

// Main search endpoint (BAP calls this)
// Route::post('/ondc/search', [ApiController::class, 'search']);
// Route::post('/ondc/select', [ApiController::class, 'select']);
// Route::post('/ondc/init', [ApiController::class, 'init']);
// Route::post('/ondc/confirm', [ApiController::class, 'confirm']);

// // Callback endpoints (ONDC network calls these)
// Route::post('/ondc/callback', [ApiController::class, 'callback']);
// Route::post('/ondc/callback/on_search', [ApiController::class, 'onSearch']);
// Route::post('/ondc/callback/on_select', [ApiController::class, 'onSelect']);

// Route::post('/ondc/callback/on_init', [ApiController::class, 'onInit']);
// Route::post('/ondc/callback/on_confirm', [ApiController::class, 'onConfirm']);
// Route::post('/ondc/callback/on_status', [ApiController::class, 'onStatus']);
// Route::post('/ondc/callback/on_cancel', [ApiController::class, 'onCancel']);
// Route::post('/ondc/callback/on_update', [ApiController::class, 'onUpdate']);

// // Utility endpoints (Admin use)
// Route::post('/ondc/fetch-response', [ApiController::class, 'fetchOndcResponse']);
// Route::get('/ondc/fetch-response', [ApiController::class, 'fetchOndcResponse']);
// Route::get('/admin/ondc/transaction/check', [ApiController::class, 'checkTransactionStatus'])->name('admin.ondc.transaction.check');
// Route::get('/admin/ondc/transactions', [ApiController::class, 'listTransactions'])->name('admin.ondc.transactions.list');
// Route::post('/admin/ondc/fetch-response', [ApiController::class, 'fetchOndcResponse'])->name('admin.ondc.fetch_response');
// ===================================================

