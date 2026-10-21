<?php

namespace Webkul\Ondc\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Webkul\Ondc\Models\OndcCancellationReason;
use Webkul\Ondc\Models\OndcOrderCancellation;
use Webkul\Ondc\Services\SellerCancellationService;
use Webkul\Ondc\Services\CancellationService;

class SellerCancellationController extends Controller
{
    public function __construct(
        protected SellerCancellationService $sellerCancellationService,
    ) {}

    // ────────────────────────────────────────────────
    //  Seller-facing REST APIs
    // ────────────────────────────────────────────────

    /**
     * GET /api/ondc/cancellation/seller/reasons
     */
    public function listSellerReasons(): JsonResponse
    {
        $reasons = OndcCancellationReason::where('is_active', true)
            ->where(function ($q) {
                $q->where('who_can_use', 'LIKE', '%SNP%')
                  ->orWhere('who_can_use', 'LIKE', '%LSP%')
                  ->orWhere('who_can_use', 'LIKE', '%LBNP%');
            })
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $reasons->map(fn ($r) => [
                'code'                       => $r->code,
                'reason'                     => $r->reason,
                'triggers_rto'               => $r->triggers_rto,
                'who_can_use'                => $r->who_can_use,
                'cost_attributed_to'         => $r->cost_attributed_to,
                'applicable_for_part_cancel' => $r->applicable_for_part_cancel,
            ]),
        ]);
    }
 


    /**
     * POST /api/ondc/cancellation/seller/initiate
     */
    public function initiate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ondc_order_id'              => 'required|string',
            'transaction_id'             => 'required|string',
            'cancellation_reason_id'     => 'required|string|exists:ondc_cancellation_reasons,code',
            'precancel_fulfillment_state' => 'nullable|string|in:Pending,Packed,Order-picked-up,Out-for-delivery',
            'order_id'                   => 'nullable|integer',
            'fulfillment_id'             => 'nullable|string',
            'cancel_type'                => 'nullable|in:FULL,PARTIAL',
            'seller_note'                => 'nullable|string|max:500',
            'cancelled_by'               => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $cancellation = $this->sellerCancellationService->initiateSellerCancel($request->all());

            return response()->json([
                'success' => true,
                'data'    => $cancellation,
                'rto'     => [
                    'triggered'  => $cancellation->triggers_rto,
                    'status'     => $cancellation->rto_status,
                    'fulfillment_id' => $cancellation->rto_fulfillment_id,
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('[ONDC] Seller cancellation failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Cancellation failed'], 500);
        }
    }

    // ────────────────────────────────────────────────
    //  RTO state-machine APIs
    // ────────────────────────────────────────────────

    /**
     * POST /api/ondc/cancellation/{id}/rto/initiate
     * Manually start RTO if not already triggered.
     */
    public function initiateRto(int $id, Request $request): JsonResponse
    {
        $cancellation = OndcOrderCancellation::find($id);
        if (! $cancellation) {
            return response()->json(['success' => false, 'error' => 'Not found'], 404);
        }

        if ($cancellation->triggers_rto && $cancellation->rto_status) {
            return response()->json([
                'success' => false,
                'error'   => "RTO already in state {$cancellation->rto_status}",
            ], 409);
        }

        try {
            $updated = $this->sellerCancellationService->initiateRto($cancellation, $request->all());
            return response()->json(['success' => true, 'data' => $updated]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/ondc/cancellation/{id}/rto/delivered
     * Mark package returned to seller.
     */
    public function markRtoDelivered(int $id, Request $request): JsonResponse
    {
        $cancellation = OndcOrderCancellation::find($id);
        if (! $cancellation) {
            return response()->json(['success' => false, 'error' => 'Not found'], 404);
        }

        try {
            $updated = $this->sellerCancellationService->markRtoDelivered($cancellation, $request->all());
            return response()->json(['success' => true, 'data' => $updated]);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/ondc/cancellation/{id}/rto/disposed
     * Mark RTO complete — seller has put item back to stock / disposed.
     */
    public function markRtoDisposed(int $id, Request $request): JsonResponse
    {
        $cancellation = OndcOrderCancellation::find($id);
        if (! $cancellation) {
            return response()->json(['success' => false, 'error' => 'Not found'], 404);
        }

        try {
            $updated = $this->sellerCancellationService->markRtoDisposed($cancellation, $request->all());
            return response()->json(['success' => true, 'data' => $updated]);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/ondc/cancellation/{id}/rto/timeline
     */
    public function rtoTimeline(int $id): JsonResponse
    {
        $cancellation = OndcOrderCancellation::with('rtoEvents')->find($id);
        if (! $cancellation) {
            return response()->json(['success' => false, 'error' => 'Not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'cancellation_id'    => $cancellation->id,
                'triggers_rto'       => $cancellation->triggers_rto,
                'rto_status'         => $cancellation->rto_status,
                'rto_fulfillment_id' => $cancellation->rto_fulfillment_id,
                'rto_initiated_at'   => $cancellation->rto_initiated_at,
                'rto_delivered_at'   => $cancellation->rto_delivered_at,
                'rto_disposed_at'    => $cancellation->rto_disposed_at,
                'events'             => $cancellation->rtoEvents,
            ],
        ]);
    }

    /**
     * GET /api/ondc/rto/active
     * List all RTOs in progress.
     */
    public function activeRtos(): JsonResponse
    {
        $items = OndcOrderCancellation::with(['reason', 'rtoEvents'])
            ->where('triggers_rto', true)
            ->whereIn('rto_status', [
                OndcOrderCancellation::RTO_INITIATED,
                OndcOrderCancellation::RTO_DELIVERED,
            ])
            ->orderBy('rto_initiated_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $items]);
    }

    /**
     * POST /api/ondc/cancellation/{id}/build-on-cancel
     * Generate the unsolicited /on_cancel payload (preview).
     */
    public function buildOnCancelPayload(int $id, Request $request): JsonResponse
    {
        $cancellation = OndcOrderCancellation::with(['quoteTrails'])->find($id);
        if (! $cancellation) {
            return response()->json(['success' => false, 'error' => 'Not found'], 404);
        }

        $orderData = $request->input('order_data', []);
        $payload   = $this->sellerCancellationService->buildUnsolicitedOnCancel($cancellation, $orderData);

        return response()->json(['success' => true, 'data' => $payload]);
    }
}
