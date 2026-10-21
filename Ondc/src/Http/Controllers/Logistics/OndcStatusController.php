<?php

namespace Webkul\Ondc\Http\Controllers\Logistics;

use Webkul\Ondc\Services\Logistics\LogisticsStatusService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OndcStatusController extends Controller
{
    public function __construct(
        protected LogisticsStatusService $statusService
    ) {}

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
            'transaction_id' => 'nullable|string',
        ]);

        $result = $this->statusService->getStatus(
            orderId: $validated['order_id'],
            transactionId: $validated['transaction_id'] ?? ''
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
