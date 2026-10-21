<?php

namespace Webkul\Ondc\Http\Controllers\Logistics;

use Webkul\Ondc\Services\Logistics\LogisticsOndcUpdateService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OndcUpdateController extends Controller
{
    public function __construct(
        protected LogisticsOndcUpdateService $updateService
    ) {}

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
            'transaction_id' => 'nullable|string',
        ]);

        $result = $this->updateService->update(
            orderId: $validated['order_id'],
            transactionId: $validated['transaction_id'] ?? ''
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
