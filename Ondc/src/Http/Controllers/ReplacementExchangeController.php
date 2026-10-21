<?php

namespace Webkul\Ondc\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Webkul\Ondc\Models\OndcReplacementFulfillment;
use Webkul\Ondc\Models\OndcReturnRequest;
use Webkul\Ondc\Services\ReplacementExchangeService;

class ReplacementExchangeController extends Controller
{
    public function __construct(
        protected ReplacementExchangeService $service,
    ) {}

    // ──────────────────────────────────────────
    //  Replacement
    // ──────────────────────────────────────────

    public function initiateReplacement(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ondc_order_id'       => 'required|string',
            'transaction_id'      => 'required|string',
            'item_id'             => 'required|string',
            'item_quantity'       => 'required|integer|min:1',
            'reason_id'           => 'required|string',
            'reason_desc'         => 'nullable|string|max:500',
            'images'              => 'nullable|array',
            'replacement_item_id' => 'nullable|string',
            'order_id'            => 'nullable|integer',
            'refund_amount'       => 'nullable|numeric|min:0',
            'ttl_approval'        => 'nullable|string',
            'ttl_reverseqc'       => 'nullable|string',
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        try {
            $rr = $this->service->initiateReplacement($request->all());
            return response()->json([
                'success'        => true,
                'data'           => $rr,
                'update_payload' => $this->service->buildReplacementOrExchangePayload($rr),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('[ONDC] Replacement init failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Replacement init failed'], 500);
        }
    }

    // ──────────────────────────────────────────
    //  Exchange
    // ──────────────────────────────────────────

    public function initiateExchange(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ondc_order_id'                => 'required|string',
            'transaction_id'               => 'required|string',
            'item_id'                      => 'required|string',
            'item_quantity'                => 'required|integer|min:1',
            'reason_id'                    => 'required|string',
            'reason_desc'                  => 'nullable|string|max:500',
            'images'                       => 'nullable|array',
            'exchange_item_id'             => 'required|string',
            'exchange_item_specs'          => 'nullable|array',
            'exchange_item_specs.brand'    => 'nullable|string',
            'exchange_item_specs.model'    => 'nullable|string',
            'exchange_item_specs.ram'      => 'nullable|string',
            'exchange_item_specs.ram_unit' => 'nullable|string',
            'exchange_item_specs.storage'  => 'nullable|string',
            'exchange_item_specs.storage_unit' => 'nullable|string',
            'condition_id'                 => 'nullable|string|max:10',
            'condition_desc'               => 'nullable|string|max:500',
            'exchange_price_difference'    => 'nullable|numeric',
            'order_id'                     => 'nullable|integer',
            'refund_amount'                => 'nullable|numeric|min:0',
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        try {
            $rr = $this->service->initiateExchange($request->all());
            return response()->json([
                'success'        => true,
                'data'           => $rr,
                'update_payload' => $this->service->buildReplacementOrExchangePayload($rr),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('[ONDC] Exchange init failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Exchange init failed'], 500);
        }
    }

    // ──────────────────────────────────────────
    //  Forward fulfillment management
    // ──────────────────────────────────────────

    public function startForwardFulfillment(int $id, Request $request): JsonResponse
    {
        $rr = OndcReturnRequest::find($id);
        if (! $rr) return response()->json(['success' => false, 'error' => 'Not found'], 404);

        try {
            $rf = $this->service->createForwardFulfillment($rr, $request->all());
            return response()->json(['success' => true, 'data' => $rf], 201);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function advanceFulfillment(int $rfId, Request $request): JsonResponse
    {
        $rf = OndcReplacementFulfillment::find($rfId);
        if (! $rf) return response()->json(['success' => false, 'error' => 'Not found'], 404);

        try {
            $rf = $this->service->advanceFulfillment($rf, $request->all());
            return response()->json(['success' => true, 'data' => $rf]);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        }
    }

    public function cancelFulfillment(int $rfId, Request $request): JsonResponse
    {
        $rf = OndcReplacementFulfillment::find($rfId);
        if (! $rf) return response()->json(['success' => false, 'error' => 'Not found'], 404);

        try {
            $rf = $this->service->cancelFulfillment($rf, $request->all());
            return response()->json(['success' => true, 'data' => $rf]);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        }
    }

    public function fulfillmentTimeline(int $rfId): JsonResponse
    {
        $rf = OndcReplacementFulfillment::with(['events', 'returnRequest'])->find($rfId);
        if (! $rf) return response()->json(['success' => false, 'error' => 'Not found'], 404);

        return response()->json([
            'success' => true,
            'data'    => [
                'replacement_fulfillment_id' => $rf->replacement_fulfillment_id,
                'flow_type'                  => $rf->flow_type,
                'state'                      => $rf->state,
                'item_id'                    => $rf->item_id,
                'quantity'                   => $rf->quantity,
                'agent_name'                 => $rf->agent_name,
                'agent_phone'                => $rf->agent_phone,
                'tracking_url'               => $rf->tracking_url,
                'packed_at'                  => $rf->packed_at,
                'picked_at'                  => $rf->picked_at,
                'out_for_delivery_at'        => $rf->out_for_delivery_at,
                'delivered_at'               => $rf->delivered_at,
                'events'                     => $rf->events,
                'return_request'             => $rf->returnRequest,
            ],
        ]);
    }

    // ──────────────────────────────────────────
    //  Exchange settlement
    // ──────────────────────────────────────────

    public function recordSettlement(int $id, Request $request): JsonResponse
    {
        $rr = OndcReturnRequest::find($id);
        if (! $rr) return response()->json(['success' => false, 'error' => 'Not found'], 404);

        $validator = Validator::make($request->all(), [
            'settlement_status' => 'required|in:PENDING,COLLECTED,REFUNDED,NOT_REQUIRED',
            'notes'             => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        try {
            return response()->json(['success' => true, 'data' => $this->service->recordSettlement($rr, $request->all())]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        }
    }

    // ──────────────────────────────────────────
    //  Listings + dashboard
    // ──────────────────────────────────────────

    public function activeReplacements(): JsonResponse
    {
        $items = OndcReturnRequest::with(['replacementFulfillment'])
            ->where('flow_type', OndcReturnRequest::FLOW_REPLACEMENT)
            ->whereNotIn('status', [
                OndcReturnRequest::STATUS_REJECTED,
                OndcReturnRequest::STATUS_QC_FAILED,
                OndcReturnRequest::STATUS_COMPLETED,
                OndcReturnRequest::STATUS_CLOSED,
            ])
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function activeExchanges(): JsonResponse
    {
        $items = OndcReturnRequest::with(['replacementFulfillment'])
            ->where('flow_type', OndcReturnRequest::FLOW_EXCHANGE)
            ->whereNotIn('status', [
                OndcReturnRequest::STATUS_REJECTED,
                OndcReturnRequest::STATUS_QC_FAILED,
                OndcReturnRequest::STATUS_COMPLETED,
                OndcReturnRequest::STATUS_CLOSED,
            ])
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json(['success' => true, 'data' => $items]);
    }
}
