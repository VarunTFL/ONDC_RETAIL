<?php

use Illuminate\Support\Facades\Route;
use Webkul\Ondc\Http\Controllers\Logistics\OndcSearchController;
use Webkul\Ondc\Http\Controllers\Logistics\OndcSelectController;
use Webkul\Ondc\Http\Controllers\Logistics\OndcInitController;
use Webkul\Ondc\Http\Controllers\Logistics\OndcConfirmController;
use Webkul\Ondc\Http\Controllers\Logistics\OndcStatusController;
use Webkul\Ondc\Http\Controllers\Logistics\OndcTrackController;
use Webkul\Ondc\Http\Controllers\Logistics\OndcUpdateController;

Route::group(['prefix' => 'ondc/logistics', 'middleware' => ['api']], function () {
    // Outgoing requests
    Route::post('/search', [OndcSearchController::class, 'search']);
    Route::post('/select', [OndcSelectController::class, 'select']);
    Route::post('/init', [OndcInitController::class, 'init']);
    Route::post('/confirm', [OndcConfirmController::class, 'confirm']);
    Route::post('/status', [OndcStatusController::class, 'status']);
    Route::post('/track', [OndcTrackController::class, 'track']);
    Route::post('/update', [OndcUpdateController::class, 'update']);

    // Callbacks


    // Matches bap_uri ending with /seller
    Route::group(['prefix' => 'seller'], function () {
        Route::post('/on_search', [OndcSearchController::class, 'on_search']);
    });
});