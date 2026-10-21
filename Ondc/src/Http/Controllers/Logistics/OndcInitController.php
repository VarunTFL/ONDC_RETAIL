<?php

namespace Webkul\Ondc\Http\Controllers\Logistics;

use Webkul\Ondc\Services\Logistics\LogisticsOndcInitService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OndcInitController extends Controller
{
    public function __construct(
        protected LogisticsOndcInitService $initService
    ) {}

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'ondc_item_id' => 'required|integer',
            'billing_address' => 'required|array',
            'fulfillment_start' => 'required|array',
            'fulfillment_end' => 'required|array',
            'payment_details' => 'nullable|array',
        ]);

        $result = $this->initService->init(
            ondcItemId: $validated['ondc_item_id'],
            billingAddress: $validated['billing_address'],
            fulfillmentStart: $validated['fulfillment_start'],
            fulfillmentEnd: $validated['fulfillment_end'],
            paymentDetails: $validated['payment_details'] ?? []
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
