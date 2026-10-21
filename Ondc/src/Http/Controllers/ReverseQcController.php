<?php

namespace Webkul\Ondc\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Webkul\Ondc\Models\OndcReturnRequest;
use Webkul\Ondc\Services\CombinedFlowService;
use Webkul\Ondc\Services\ReverseQcService;

class ReverseQcController extends Controller
{
    public function __construct(
        protected ReverseQcService $reverseQc,
        protected CombinedFlowService $combinedFlow,
    ) {}

    // ──────────────────────────────────────────
    //  Return reasons + listings
    // ──────────────────────────────────────────

    public function listReturnReasons(): JsonResponse
    {
        $rows = collect(ReverseQcService::RETURN_REASON_CODES)
            ->map(fn ($desc, $code) => ['code' => $code, 'reason' => $desc])
            ->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function listReturns(Request $request): JsonResponse
    {
        $q = OndcReturnRequest::with(['qcResult'])->orderBy('created_at', 'desc')->limit(100);
        if ($s = $request->query('status')) $q->where('status', $s);
        if ($o = $request->query('ondc_order_id')) $q->where('ondc_order_id', $o);
        return response()->json(['success' => true, 'data' => $q->get()]);
    }

    public function showReturn(int $id): JsonResponse
    {
        $rr = OndcReturnRequest::with(['qcResult', 'events', 'cancellation'])->find($id);
        if (! $rr) return response()->json(['success' => false, 'error' => 'Not found'], 404);
        return response()->json(['success' => true, 'data' => $rr]);
    }

    // ──────────────────────────────────────────
    //  Buyer → Initiate Return
    // ──────────────────────────────────────────

    public function initiateReturn(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ondc_order_id'     => 'required|string',
            'transaction_id'    => 'required|string',
            'item_id'           => 'required|string',
            'item_quantity'     => 'required|integer|min:1',
            'reason_id'         => 'required|string',
            'reason_desc'       => 'nullable|string|max:500',
            'images'            => 'nullable|array',
            'images.*'          => 'url',
            'parent_item_id'    => 'nullable|string',
            'order_id'          => 'nullable|integer',
            'refund_amount'     => 'nullable|numeric|min:0',
            'ttl_approval'      => 'nullable|string',
            'ttl_reverseqc'     => 'nullable|string',
            'return_request_id' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $rr = $this->reverseQc->initiateReturn($request->all());
            return response()->json([
                'success' => true,
                'data'    => $rr,
                'update_payload' => $this->reverseQc->buildReturnRequestUpdatePayload($rr),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('[ONDC] Return init failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Return failed'], 500);
        }
    }

    // ──────────────────────────────────────────
    //  Seller → Approve / Reject
    // ──────────────────────────────────────────

    public function approveReturn(int $id, Request $request): JsonResponse
    {
        $rr = OndcReturnRequest::find($id);
        if (! $rr) return response()->json(['success' => false, 'error' => 'Not found'], 404);
        try {
            return response()->json(['success' => true, 'data' => $this->reverseQc->approveReturn($rr, $request->all())]);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        }
    }

    public function rejectReturn(int $id, Request $request): JsonResponse
    {
        $rr = OndcReturnRequest::find($id);
        if (! $rr) return response()->json(['success' => false, 'error' => 'Not found'], 404);
        try {
            return response()->json(['success' => true, 'data' => $this->reverseQc->rejectReturn($rr, $request->all())]);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        }
    }

    // ──────────────────────────────────────────
    //  Reverse logistics state machine
    // ──────────────────────────────────────────

    public function markPickedUp(int $id, Request $request): JsonResponse
    {
        $rr = OndcReturnRequest::find($id);
        if (! $rr) return response()->json(['success' => false, 'error' => 'Not found'], 404);
        try {
            return response()->json(['success' => true, 'data' => $this->reverseQc->markPickedUp($rr, $request->all())]);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        }
    }

    public function startQc(int $id, Request $request): JsonResponse
    {
        $rr = OndcReturnRequest::find($id);
        if (! $rr) return response()->json(['success' => false, 'error' => 'Not found'], 404);
        try {
            return response()->json(['success' => true, 'data' => $this->reverseQc->startQc($rr, $request->all())]);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        }
    }

    public function recordQcPass(int $id, Request $request): JsonResponse
    {
        $rr = OndcReturnRequest::find($id);
        if (! $rr) return response()->json(['success' => false, 'error' => 'Not found'], 404);
        $validator = Validator::make($request->all(), [
            'approved_refund' => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string|max:500',
            'evidence_urls'   => 'nullable|array',
            'checklist'       => 'nullable|array',
            'auto_liquidate'  => 'nullable|boolean',
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        $rr = $this->reverseQc->recordQcPass($rr, $request->all());

        // Optional: auto-trigger liquidation
        $liquidation = null;
        if ($request->boolean('auto_liquidate')) {
            $liquidation = $this->combinedFlow->onQcPassedTriggerLiquidation($rr, $request->all());
        }

        return response()->json([
            'success' => true,
            'data'    => $rr,
            'liquidation_triggered' => $liquidation ? [
                'cancellation_id'  => $liquidation->id,
                'disposition_type' => $liquidation->disposition_type,
                'channel'          => $liquidation->liquidation_channel,
                'value'            => $liquidation->liquidation_value,
            ] : null,
        ]);
    }

    public function recordQcFail(int $id, Request $request): JsonResponse
    {
        $rr = OndcReturnRequest::find($id);
        if (! $rr) return response()->json(['success' => false, 'error' => 'Not found'], 404);
        $validator = Validator::make($request->all(), [
            'failure_reason' => 'required|string|max:500',
            'evidence_urls'  => 'nullable|array',
            'checklist'      => 'nullable|array',
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        return response()->json(['success' => true, 'data' => $this->reverseQc->recordQcFail($rr, $request->all())]);
    }

    public function processRefund(int $id, Request $request): JsonResponse
    {
        $rr = OndcReturnRequest::find($id);
        if (! $rr) return response()->json(['success' => false, 'error' => 'Not found'], 404);
        try {
            return response()->json(['success' => true, 'data' => $this->reverseQc->markRefundProcessed($rr, $request->all())]);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        }
    }

    public function timeline(int $id): JsonResponse
    {
        $rr = OndcReturnRequest::with(['events', 'qcResult'])->find($id);
        if (! $rr) return response()->json(['success' => false, 'error' => 'Not found'], 404);

        return response()->json([
            'success' => true,
            'data' => [
                'return_request_id' => $rr->return_request_id,
                'status'            => $rr->status,
                'qc_result'         => $rr->qcResult,
                'events'            => $rr->events,
            ],
        ]);
    }

    // ──────────────────────────────────────────
    //  COMBINED FLOW — Part Cancel + Reverse QC + Liquidation
    // ──────────────────────────────────────────

    public function executeCombinedFlow(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ondc_order_id'          => 'required|string',
            'transaction_id'         => 'required|string',
            'cancellation_reason_id' => 'required|in:002,012',
            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'required|string',
            'items.*.original_quantity'  => 'required|integer|min:1',
            'items.*.cancelled_quantity' => 'required|integer|min:1',
            'items.*.item_price'         => 'required|numeric|min:0',
            'items.*.fulfillment_id'     => 'nullable|string',
            'return_reason_id'           => 'nullable|string',
            'return_reason_desc'         => 'nullable|string',
            'evidence_images'            => 'nullable|array',
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        try {
            return response()->json(['success' => true, 'data' => $this->combinedFlow->executeFullChain($request->all())], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('[ONDC] Combined flow failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Combined flow failed'], 500);
        }
    }

    public function combinedStatus(int $id): JsonResponse
    {
        $rr = OndcReturnRequest::with(['qcResult', 'cancellation'])->find($id);
        if (! $rr) return response()->json(['success' => false, 'error' => 'Not found'], 404);

        return response()->json(['success' => true, 'data' => $this->combinedFlow->getCombinedStatus($rr)]);
    }

    public function activeReturns(): JsonResponse
    {
        $items = OndcReturnRequest::with(['qcResult'])
            ->whereIn('status', [
                OndcReturnRequest::STATUS_REQUESTED,
                OndcReturnRequest::STATUS_APPROVED,
                OndcReturnRequest::STATUS_PICKUP_INITIATED,
                OndcReturnRequest::STATUS_QC_IN_PROGRESS,
                OndcReturnRequest::STATUS_QC_PASSED,
            ])
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json(['success' => true, 'data' => $items]);
    }
}
