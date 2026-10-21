<?php

namespace Webkul\Ondc\Http\Controllers\Logistics;

use Webkul\Ondc\Services\Logistics\LogisticsOndcConfirmService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OndcConfirmController extends Controller
{
    public function __construct(
        protected LogisticsOndcConfirmService $confirmService
    ) {}

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|string',
            'order_id' => 'required|string',
            'linked_order' => 'nullable|array',
        ]);

        $result = $this->confirmService->confirm(
            transactionId: $validated['transaction_id'],
            orderId: $validated['order_id'],
            linkedOrder: $validated['linked_order'] ?? []
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
