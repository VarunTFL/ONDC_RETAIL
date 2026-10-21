<?php

namespace Webkul\Ondc\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Ondc\Models\OndcReturnRequest;
use Webkul\Ondc\Services\ReverseQcService;
use Webkul\Ondc\Services\LiquidationService;
use App\Models\OndcTransaction;
use App\Models\OndcApiLog;

class ReturnManagementController extends Controller
{
    public function __construct(
        protected ReverseQcService $reverseQc,
        protected LiquidationService $liquidation,
    ) {}

    public function approve(Request $request, $returnRequestId)
    {
        $rr = OndcReturnRequest::findOrFail($returnRequestId);
        try {
            $rr = $this->reverseQc->approveReturn($rr, ['triggered_by' => 'admin']);
            $this->pushOnUpdate($rr);
            return redirect()->back()->with('success', 'Return approved. Buyer notified.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $request, $returnRequestId)
    {
        $rr = OndcReturnRequest::findOrFail($returnRequestId);
        try {
            $rr = $this->reverseQc->rejectReturn($rr, ['reason' => $request->input('reason', 'Rejected by seller')]);
            $this->pushOnUpdate($rr);
            return redirect()->back()->with('success', 'Return rejected. Buyer notified.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function markPicked(Request $request, $returnRequestId)
    {
        $rr = OndcReturnRequest::findOrFail($returnRequestId);
        try {
            $rr = $this->reverseQc->markPickedUp($rr, ['pickup_proof_code' => $request->input('pickup_proof_code')]);
            $this->pushOnUpdate($rr);
            return redirect()->back()->with('success', 'Return marked picked. Buyer notified.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function qcPass(Request $request, $returnRequestId)
    {
        $rr = OndcReturnRequest::findOrFail($returnRequestId);
        try {
            $rr = $this->reverseQc->recordQcPass($rr, ['approved_refund' => $request->input('refund_amount')]);
            $this->reverseQc->markRefundProcessed($rr, []); // -> COMPLETED, sends "Return_Delivered"
            $this->pushOnUpdate($rr->fresh());
            return redirect()->back()->with('success', 'QC passed, refund processed. Buyer notified.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function qcFail(Request $request, $returnRequestId)
    {
        $rr = OndcReturnRequest::findOrFail($returnRequestId);
        try {
            $rr = $this->reverseQc->recordQcFail($rr, ['failure_reason' => $request->input('failure_reason')]);
            $this->pushOnUpdate($rr);
            return redirect()->back()->with('success', 'QC failed recorded. Buyer notified.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Liquidation flow (image 3): interim -> completeLiquidation.
     * Operates on OndcOrderCancellation, not OndcReturnRequest.
     */
    public function requestLiquidation(Request $request, $cancellationId)
    {
        $cancellation = \Webkul\Ondc\Models\OndcOrderCancellation::findOrFail($cancellationId);
        try {
            $cancellation = $this->liquidation->requestLiquidation($cancellation, $request->only(['channel', 'value', 'notes']));
            $this->pushOnUpdateForCancellation($cancellation, 'Liquidated_Interim');
            return redirect()->back()->with('success', 'Liquidation requested. Buyer notified.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function completeLiquidation(Request $request, $cancellationId)
    {
        $cancellation = \Webkul\Ondc\Models\OndcOrderCancellation::findOrFail($cancellationId);
        try {
            $cancellation = $this->liquidation->completeLiquidation($cancellation, $request->only(['disposition', 'channel', 'value', 'notes']));
            $this->pushOnUpdateForCancellation($cancellation, 'Liquidated');
            return redirect()->back()->with('success', 'Liquidation completed. Buyer notified.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    // ────────────────────────────────────────────
    // Dispatch helpers
    // ────────────────────────────────────────────

    protected function pushOnUpdate(OndcReturnRequest $rr): void
    {
        $orderCtx = $this->resolveOrderContext($rr->ondc_transaction_id);
        $originalOrder = $this->resolveOriginalOrder($rr->ondc_transaction_id);
        $payload = $this->reverseQc->buildOnUpdateForStage($rr, $orderCtx['context'], $originalOrder);
        $this->sendOnUpdate($payload, $rr->ondc_transaction_id, $orderCtx['bap_uri']);
    }


    /**
     * Pull the original order snapshot (items, billing, quote, provider,
     * fulfillments, payment) from ondc_confirm_payloads, so the full-order
     * on_update at each return stage matches the spec's expected structure.
     */
    protected function resolveOriginalOrder(string $txnId): array
    {
        $ondcPayload = DB::table('ondc_confirm_payloads')->where('transaction_id', $txnId)->first();

        if (! $ondcPayload) {
            return [];
        }

        return json_decode($ondcPayload->message_order, true) ?? [];
    }

    

    protected function pushOnUpdateForCancellation($cancellation, string $stateCode): void
    {
        $orderCtx = $this->resolveOrderContext($cancellation->ondc_transaction_id);

        $payload = [
            'context' => array_merge($orderCtx['context'], [
                'action'     => 'on_update',
                'message_id' => (string) \Illuminate\Support\Str::uuid(),
                'timestamp'  => gmdate('Y-m-d\TH:i:s.v\Z'),
                'ttl'        => 'PT30S',
            ]),
            'message' => [
                'order' => [
                    'id'           => $cancellation->ondc_order_id,
                    'fulfillments' => [[
                        'id'    => 'RET-' . $cancellation->id,
                        'type'  => 'Return',
                        'state' => ['descriptor' => ['code' => $stateCode]],
                        'tags'  => [[
                            'code' => 'return_request',
                            'list' => [
                                ['code' => 'id', 'value' => (string) $cancellation->id],
                            ],
                        ]],
                    ]],
                ],
            ],
        ];

        $this->sendOnUpdate($payload, $cancellation->ondc_transaction_id, $orderCtx['bap_uri']);
    }

    /**
     * Pull bap_uri / bap_id / domain / city / core_version from the stored
     * confirm payload (same source ProductConfirmationController uses),
     * keyed by transaction_id — reused so every unsolicited on_update
     * targets the correct BAP and preserves the original context fields.
     */
    protected function resolveOrderContext(string $txnId): array
    {
        $ondcPayload = DB::table('ondc_confirm_payloads')->where('transaction_id', $txnId)->first();

        if (! $ondcPayload) {
            Log::error('[ONDC][RETURN] No confirm payload found for txn', ['txn_id' => $txnId]);
            return ['bap_uri' => '', 'context' => []];
        }

        $context = json_decode($ondcPayload->context, true) ?? [];

        return [
            'bap_uri' => $ondcPayload->bap_uri,
            'context' => [
                'domain'         => $ondcPayload->domain ?: ($context['domain'] ?? 'ONDC:RET10'),
                'country'        => $context['country'] ?? 'IND',
                'city'           => $context['city'] ?? 'std:080',
                'core_version'   => $context['core_version'] ?? '1.2.0',
                'transaction_id' => $txnId,
                'bap_id'         => $ondcPayload->bap_id,
                'bap_uri'        => $ondcPayload->bap_uri,
                'bpp_id'         => env('ONDC_BPP_ID'),
                'bpp_uri'        => env('ONDC_BPP_URI'),
            ],
        ];
    }

    protected function sendOnUpdate(array $payload, string $txnId, string $bapUri): void
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $url = rtrim($bapUri, '/') . '/on_update';

        Log::info('[ONDC][RETURN] Sending unsolicited on_update', [
            'txn_id' => $txnId,
            'state'  => data_get($payload, 'message.order.fulfillments.0.state.descriptor.code'),
        ]);

        try {
            $response = Http::withHeaders([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->withBody($payloadJson, 'application/json')
                ->timeout(30)
                ->connectTimeout(10)
                ->post($url);

            OndcApiLog::create([
                'type'           => 'on_update_outbound',
                'endpoint'       => $url,
                'request'        => $payloadJson,
                'response'       => $response->body(),
                'status_code'    => $response->status(),
                'transaction_id' => $txnId,
            ]);

            OndcTransaction::create([
                'transaction_id'       => $txnId,
                'action'               => 'on_update',
                'domain'               => $payload['context']['domain'] ?? 'ONDC:RET10',
                'bap_id'               => $payload['context']['bap_id'] ?? null,
                'bap_uri'              => $bapUri,
                'bpp_id'               => env('ONDC_BPP_ID'),
                'status'               => $response->successful() ? 'ack_received' : 'rejected',
                'request_payload'      => $payload,
                'response_payload'     => $response->json(),
                'response_status_code' => $response->status(),
                'sent_at'              => now(),
                'ack_received_at'      => $response->successful() ? now() : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('[ONDC][RETURN] on_update push failed', ['txn_id' => $txnId, 'error' => $e->getMessage()]);
            OndcApiLog::create([
                'type'           => 'on_update_outbound_error',
                'endpoint'       => $url,
                'request'        => $payloadJson,
                'response'       => json_encode(['error' => $e->getMessage()]),
                'status_code'    => 500,
                'transaction_id' => $txnId,
            ]);
        }
    }
}