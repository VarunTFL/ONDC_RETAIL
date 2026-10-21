<?php

namespace Webkul\Ondc\Http\Controllers\Logistics;

use Webkul\Ondc\Services\Logistics\LogisticsTrackService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OndcTrackController extends Controller
{
    public function __construct(
        protected LogisticsTrackService $trackService
    ) {}

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
            'transaction_id' => 'nullable|string',
        ]);

        $result = $this->trackService->track(
            orderId: $validated['order_id'],
            transactionId: $validated['transaction_id'] ?? ''
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
