<?php

namespace Webkul\Ondc\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Webkul\Ondc\Models\OndcCancellationReason;
use Webkul\Ondc\Models\OndcCancelledItem;
use Webkul\Ondc\Models\OndcOrderCancellation;
use Webkul\Ondc\Services\LiquidationService;
use Webkul\Ondc\Services\PartialCancellationService;

class PartialCancellationController extends Controller
{
    public function __construct(
        protected PartialCancellationService $partialService,
        protected LiquidationService $liquidationService,
    ) {}

    // ────────────────────────────────────────────────────────────
    //  PARTIAL CANCELLATION
    // ────────────────────────────────────────────────────────────

    /**
     * GET /api/ondc/cancellation/partial/reasons
     * List reason codes applicable for part cancel.
     */
    public function partCancelReasons(): JsonResponse
    {
        $reasons = OndcCancellationReason::where('is_active', true)
            ->where('applicable_for_part_cancel', true)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $reasons->map(fn ($r) => [
                'code'         => $r->code,
                'reason'       => $r->reason,
                'who_can_use'  => $r->who_can_use,
                'triggers_rto' => $r->triggers_rto,
            ]),
        ]);
    }

    /**
     * POST /api/ondc/cancellation/partial/initiate
     */
    public function initiatePartial(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ondc_order_id'                      => 'required|string',
            'transaction_id'                     => 'required|string',
            'cancellation_reason_id'             => 'required|string|exists:ondc_cancellation_reasons,code',
            'items'                              => 'required|array|min:1',
            'items.*.item_id'                    => 'required|string',
            'items.*.original_quantity'          => 'required|integer|min:1',
            'items.*.cancelled_quantity'         => 'required|integer|min:1',
            'items.*.item_price'                 => 'required|numeric|min:0',
            'items.*.fulfillment_id'             => 'nullable|string',
            'precancel_fulfillment_state'        => 'nullable|string',
            'order_id'                           => 'nullable|integer',
            'initiated_by'                       => 'nullable|in:BUYER,SELLER',
            'cancelled_by'                       => 'nullable|string',
            'seller_note'                        => 'nullable|string|max:500',
            'buyer_note'                         => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $cancellation = $this->partialService->initiatePartialCancel($request->all());
            return response()->json(['success' => true, 'data' => $cancellation], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('[ONDC] Partial cancel failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Partial cancel failed'], 500);
        }
    }

    /**
     * GET /api/ondc/cancellation/{id}/items
     */
    public function listItems(int $id): JsonResponse
    {
        $cancellation = OndcOrderCancellation::find($id);
        if (! $cancellation) {
            return response()->json(['success' => false, 'error' => 'Not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->partialService->listCancelledItems($cancellation),
        ]);
    }

    /**
     * POST /api/ondc/cancellation/{id}/build-on-update
     * Generate the on_update payload for a partial cancellation.
     */
    public function buildOnUpdatePayload(int $id, Request $request): JsonResponse
    {
        $cancellation = OndcOrderCancellation::with('cancelledItems')->find($id);
        if (! $cancellation) {
            return response()->json(['success' => false, 'error' => 'Not found'], 404);
        }

        $payload = $this->partialService->buildOnUpdatePartialCancelPayload(
            $cancellation,
            $request->input('order_data', [])
        );

        return response()->json(['success' => true, 'data' => $payload]);
    }

    // ────────────────────────────────────────────────────────────
    //  LIQUIDATION
    // ────────────────────────────────────────────────────────────

    /**
     * POST /api/ondc/cancellation/{id}/liquidation/request
     */
    public function requestLiquidation(int $id, Request $request): JsonResponse
    {
        $cancellation = OndcOrderCancellation::find($id);
        if (! $cancellation) {
            return response()->json(['success' => false, 'error' => 'Not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'channel' => 'nullable|in:AUCTION,DISCOUNT_SALE,WRITE_OFF,B2B_BULK',
            'value'   => 'nullable|numeric|min:0',
            'notes'   => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $updated = $this->liquidationService->requestLiquidation($cancellation, $request->all());
            return response()->json(['success' => true, 'data' => $updated]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/ondc/cancellation/{id}/liquidation/interim
     */
    public function interimLiquidation(int $id, Request $request): JsonResponse
    {
        $cancellation = OndcOrderCancellation::find($id);
        if (! $cancellation) {
            return response()->json(['success' => false, 'error' => 'Not found'], 404);
        }

        try {
            $updated = $this->liquidationService->recordInterimStatus($cancellation, $request->all());
            return response()->json(['success' => true, 'data' => $updated]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/ondc/cancellation/{id}/liquidation/complete
     */
    public function completeLiquidation(int $id, Request $request): JsonResponse
    {
        $cancellation = OndcOrderCancellation::find($id);
        if (! $cancellation) {
            return response()->json(['success' => false, 'error' => 'Not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'disposition' => 'required|in:RESTOCKED,LIQUIDATED,DISPOSED,PARTIAL_LIQUIDATED',
            'channel'     => 'nullable|in:AUCTION,DISCOUNT_SALE,WRITE_OFF,B2B_BULK',
            'value'       => 'nullable|numeric|min:0',
            'notes'       => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $updated = $this->liquidationService->completeLiquidation($cancellation, $request->all());
            return response()->json(['success' => true, 'data' => $updated]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/ondc/cancellation/items/{itemId}/disposition
     * Set disposition on a single cancelled item (partial liquidation).
     */
    public function setItemDisposition(int $itemId, Request $request): JsonResponse
    {
        $item = OndcCancelledItem::find($itemId);
        if (! $item) {
            return response()->json(['success' => false, 'error' => 'Item not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'disposition'       => 'required|in:PENDING,INTERIM,RESTOCKED,LIQUIDATED,DISPOSED',
            'channel'           => 'nullable|string',
            'liquidation_value' => 'nullable|numeric|min:0',
            'notes'             => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $updated = $this->liquidationService->setItemDisposition($item, $request->all());
            return response()->json(['success' => true, 'data' => $updated]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/ondc/cancellation/{id}/liquidation/timeline
     */
    public function liquidationTimeline(int $id): JsonResponse
    {
        $cancellation = OndcOrderCancellation::with(['liquidationEvents', 'cancelledItems'])->find($id);
        if (! $cancellation) {
            return response()->json(['success' => false, 'error' => 'Not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'cancellation_id'     => $cancellation->id,
                'disposition_type'    => $cancellation->disposition_type,
                'liquidation_value'   => $cancellation->liquidation_value,
                'liquidation_channel' => $cancellation->liquidation_channel,
                'liquidated_at'       => $cancellation->liquidated_at,
                'items'               => $cancellation->cancelledItems,
                'events'              => $cancellation->liquidationEvents,
            ],
        ]);
    }

    /**
     * GET /api/ondc/liquidation/active
     * Dashboard — all cancellations awaiting liquidation decisions.
     */
    public function activeLiquidations(): JsonResponse
    {
        $items = OndcOrderCancellation::with(['reason', 'cancelledItems'])
            ->whereIn('disposition_type', [
                OndcOrderCancellation::DISP_INTERIM,
                OndcOrderCancellation::DISP_PARTIAL_LIQUIDATED,
            ])
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $items]);
    }
}
