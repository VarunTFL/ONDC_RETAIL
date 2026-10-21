<?php

namespace Webkul\Ondc\Http\Controllers;

use App\Models\OndcApiLog;
use App\Models\OndcTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Webkul\Ondc\Models\OndcOrderCancellation;
use Webkul\Ondc\Services\PartialCancellationService;
use Webkul\Ondc\Services\ReverseQcService;
use Webkul\Ondc\Services\ReplacementExchangeService;

/**
 * Handles the /update + /on_update ONDC protocol endpoints.
 *
 * The buyer's BAP sends /update for:
 *   - Part cancellation  → return_request tag with replace=no/exchange=no
 *   - Replacement        → return_request tag with replace=yes
 *   - Exchange           → return_request tag with exchange=yes + spec tags
 *   - Reverse QC return  → return_request tag (vanilla)
 *
 * This controller dispatches to the right service based on the tags it sees.
 */
class UpdateController extends Controller
{
    public function __construct(
        protected PartialCancellationService $partialService,
        protected ReverseQcService $reverseQcService,
        protected ReplacementExchangeService $replacementExchangeService,
    ) {}


/**
 * Flush ACK immediately to the caller and terminate connection,
 * allowing subsequent code to execute on the same thread.
 */
protected function sendAckAndCloseConnection(
    array $context,
    string $status = 'ACK'
): void {
    $ackPayload = json_encode([
        'context' => $context,
        'message' => [
            'ack' => [
                'status' => $status,
            ],
        ],
    ]);

    ignore_user_abort(true);
    set_time_limit(120);

    // Clear existing output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Force client disconnection
    header('Content-Type: application/json');
    header('Connection: close');
    header('Content-Length: ' . strlen($ackPayload));

    echo $ackPayload;

    // Flush all PHP and system write buffers
    if (ob_get_length()) {
        ob_flush();
    }

    flush();

    // If FastCGI is available, finish the HTTP request immediately
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}


    /**
 * POST /ondc/update
 * Receives the signed /update request from the BAP.
 *
 * Synchronous execution:
 * ACK is flushed first, then processUpdate() runs
 * on the same PHP thread without a Laravel job.
 */
public function update(Request $request): JsonResponse
{
    $payload = $request->all();

    $context = $payload['context'] ?? [];
    $message = $payload['message'] ?? [];
    $txnId   = $context['transaction_id'] ?? '';

    Log::info('[ONDC] /update received', [
        'transaction_id' => $txnId,
        'update_target'  => $message['update_target'] ?? '',
        'order_id'       => $message['order']['id'] ?? '',
    ]);

 // 1. VALIDATE PAYLOAD
    
    $validator = Validator::make($message, [
        'update_target' => 'required|string',
        'order'         => 'required|array',
        'order.id'      => 'required|string',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'context' => $context,
            'message' => [
                'ack' => [
                    'status' => 'NACK',
                ],
            ],
            'error' => [
                'type'    => 'DOMAIN-ERROR',
                'code'    => '30000',
                'message' => $validator->errors()->first(),
            ],
        ], 400);
    }

   // 2. LOG INBOUND REQUEST
    
    try {
        OndcApiLog::create([
            'type'           => 'update_inbound',
            'endpoint'       => '/ondc/update',
            'request'        => json_encode($payload),
            'response'       => null,
            'status_code'    => 200,
            'transaction_id' => $txnId,
        ]);
    } catch (\Exception $e) {
        // Non-fatal — keep going
    }

    // 3. SEND ACK FIRST

    $this->sendAckAndCloseConnection($context);

    // 4. GIVE WORKBENCH TIME TO REGISTER THE ACK
    // usleep(500000);

    // 5. NOW PROCESS /update SYNCHRONOUSLY

    try {
        $this->processUpdate($payload);
    } catch (\Throwable $e) {
        Log::error('SEND_ON_UPDATE_EXCEPTION', [
            'txn_id'  => $txnId,
            'message' => $e->getMessage(),
            'trace'   => $e->getTraceAsString(),
        ]);
    }

   // 5. END REQUEST
    
    exit;
}

    /**
     * POST /ondc/on_update
     * Receives /on_update callbacks (when we're acting as BAP).
     */
    public function onUpdate(Request $request): JsonResponse
    {
        $payload = $request->all();
        $context = $payload['context'] ?? [];
        $txnId   = $context['transaction_id'] ?? '';

        Log::info('[ONDC] /on_update received', [
            'transaction_id' => $txnId,
            'order_state'    => $payload['message']['order']['state'] ?? '',
        ]);

        try {
            OndcApiLog::create([
                'type'           => 'on_update_inbound',
                'endpoint'       => '/ondc/on_update',
                'request'        => json_encode($payload),
                'response'       => null,
                'status_code'    => 200,
                'transaction_id' => $txnId,
            ]);
        } catch (\Exception $e) {
            // Non-fatal
        }

        if (! empty($payload['error'])) {
            Log::warning('[ONDC] on_update returned error', [
                'transaction_id' => $txnId,
                'error'          => $payload['error'],
            ]);
        }

        return response()->json([
            'message' => ['ack' => ['status' => 'ACK']],
        ]);
    }

    // ────────────────────────────────────────────────────────────
    //  Processing logic — dispatch based on /update content
    // ────────────────────────────────────────────────────────────

    protected function processUpdate(array $payload): void
{
    $context = $payload['context'] ?? [];
    $order   = $payload['message']['order'] ?? [];
    $txnId   = $context['transaction_id'] ?? '';
    $orderId = $order['id'] ?? '';

    $internalOrder = DB::table('orders')
        ->where('increment_id', $txnId)
        ->first();

    if (! $internalOrder) {
        Log::warning('[ONDC] /update received but no matching internal order found — skipping', [
            'transaction_id' => $txnId,
            'order_id'       => $orderId,
        ]);

        return;
    }

    $fulfillments = $order['fulfillments'] ?? [];
    $updateTarget = $payload['message']['update_target'] ?? '';

    /*
     * Handle settlement/payment updates BEFORE the delivery guard.
     * These updates are not the same as initiating a return.
     */
if ($updateTarget === 'payment') {
    $settlementFulfillment = $returnFulfillment
        ?: collect($order['fulfillments'] ?? [])->firstWhere('type', 'Cancel');

    if ($settlementFulfillment) {
        // If this is a Return fulfillment and the matching OndcReturnRequest
        // has already reached a terminal state (we already sent the final
        // on_update at QC-pass/refund time), the buyer's settlement /update
        // is just their own confirmation — no new on_update needed, just ACK.
        if (($settlementFulfillment['type'] ?? '') === 'Return') {
            $rr = \Webkul\Ondc\Models\OndcReturnRequest::where('ondc_transaction_id', $txnId)
                ->whereIn('status', [
                    \Webkul\Ondc\Models\OndcReturnRequest::STATUS_COMPLETED,
                    \Webkul\Ondc\Models\OndcReturnRequest::STATUS_REFUND_PROCESSED,
                ])
                ->exists();

            if ($rr) {
                Log::info('[ONDC] /update settlement received after return already COMPLETED — skipping duplicate on_update', [
                    'transaction_id' => $txnId,
                ]);
                return;
            }
        }

        Log::info('[ONDC] /update → SETTLEMENT confirmation', [
            'transaction_id'    => $txnId,
            'fulfillment_id'    => $settlementFulfillment['id'] ?? null,
            'fulfillment_type'  => $settlementFulfillment['type'] ?? null,
        ]);

        if ($this->isStale($txnId, $msgId)) {
            return;
        }

        $this->sendSettlementOnUpdate($payload, $settlementFulfillment);
        return;
    }


        Log::info('[ONDC] /update payment target has no Return/Cancel fulfillment — skipping', [
            'transaction_id' => $txnId,
            'order_id'       => $orderId,
        ]);

        return;
    }

    /*
     * Find the Return-type fulfillment.
     */
    $returnFulfillment = collect($fulfillments)
        ->firstWhere('type', 'Return');

    if (! $returnFulfillment) {
        Log::info('[ONDC] /update has no Return fulfillment — skipping', [
            'transaction_id' => $txnId,
            'order_id'       => $orderId,
            'update_target'  => $updateTarget,
        ]);

        return;
    }

    /*
     * GUARD:
     * Ignore return requests before the order is delivered.
     *
     * This guard applies only to return processing.
     * It does not block payment/settlement updates above.
     */
    $currentStatus = trim((string) ($internalOrder->status ?? ''));

    if (strcasecmp($currentStatus, 'Order-delivered') !== 0) {
        Log::info('[ONDC] /update return received before order delivered — ignoring; no return processing or on_update sent', [
            'transaction_id' => $txnId,
            'order_id'       => $orderId,
            'message_id'     => $context['message_id'] ?? null,
            'current_status' => $currentStatus,
        ]);

        return;
    }

    /*
     * Parse the return_request tag list.
     */
    $returnRequest = [];
    $hasReplace    = false;
    $hasExchange   = false;
    $exchangeSpecs = [];

    foreach ($returnFulfillment['tags'] ?? [] as $tag) {
        if (($tag['code'] ?? '') !== 'return_request') {
            continue;
        }

        foreach ($tag['list'] ?? [] as $kv) {
            $code  = $kv['code'] ?? '';
            $value = $kv['value'] ?? '';

            switch ($code) {
                case 'id':
                    $returnRequest['id'] = $value;
                    break;

                case 'item_id':
                    $returnRequest['item_id'] = $value;
                    break;

                case 'parent_item_id':
                    $returnRequest['parent_item_id'] = $value;
                    break;

                case 'item_quantity':
                    $returnRequest['item_quantity'] = (int) $value;
                    break;

                case 'reason_id':
                    $returnRequest['reason_id'] = $value;
                    break;

                case 'reason_desc':
                    $returnRequest['reason_desc'] = $value;
                    break;

                case 'images':
                    $returnRequest['images'] = explode(',', $value);
                    break;

                case 'ttl_approval':
                    $returnRequest['ttl_approval'] = $value;
                    break;

                case 'ttl_reverseqc':
                    $returnRequest['ttl_reverseqc'] = $value;
                    break;

                case 'replace':
                    $hasReplace = ($value === 'yes');
                    break;

                case 'exchange':
                    $hasExchange = ($value === 'yes');
                    break;

                case 'brand':
                    $exchangeSpecs['brand'] = $value;
                    break;

                case 'model':
                    $exchangeSpecs['model'] = $value;
                    break;

                case 'ram':
                    $exchangeSpecs['ram'] = $value;
                    break;

                case 'ram_unit':
                    $exchangeSpecs['ram_unit'] = $value;
                    break;

                case 'storage':
                    $exchangeSpecs['storage'] = $value;
                    break;

                case 'storage_unit':
                    $exchangeSpecs['storage_unit'] = $value;
                    break;

                case 'condition_id':
                    $returnRequest['condition_id'] = $value;
                    break;

                case 'condition_desc':
                    $returnRequest['condition_desc'] = $value;
                    break;
            }
        }
    }

    if (empty($returnRequest['item_id'])) {
        Log::warning('[ONDC] /update return_request missing item_id', [
            'transaction_id' => $txnId,
            'order_id'       => $orderId,
            'message_id'     => $context['message_id'] ?? null,
        ]);

        return;
    }

    /*
     * Build common parameters.
     */
    $common = [
        'ondc_order_id'     => $orderId,
        'transaction_id'    => $txnId,
        'order_id'           => $internalOrder->id ?? null,
        'item_id'            => $returnRequest['item_id'],
        'parent_item_id'     => $returnRequest['parent_item_id'] ?? null,
        'item_quantity'      => $returnRequest['item_quantity'] ?? 1,
        'reason_id'           => $returnRequest['reason_id'] ?? '003',
        'reason_desc'         => $returnRequest['reason_desc'] ?? null,
        'images'              => $returnRequest['images'] ?? [],
        'ttl_approval'        => $returnRequest['ttl_approval'] ?? 'PT24H',
        'ttl_reverseqc'       => $returnRequest['ttl_reverseqc'] ?? 'P3D',
        'return_request_id'   => $returnRequest['id'] ?? null,
    ];

    /*
     * Dispatch to the appropriate return flow.
     */
    if ($hasReplace) {
        Log::info('[ONDC] /update → REPLACEMENT flow');

        $rr = $this->replacementExchangeService->initiateReplacement(
            array_merge($common, [
                'replacement_item_id' => $returnRequest['item_id'],
            ])
        );
    } elseif ($hasExchange) {
        Log::info('[ONDC] /update → EXCHANGE flow');

        $rr = $this->replacementExchangeService->initiateExchange(
            array_merge($common, [
                'exchange_item_id'    => $returnRequest['item_id'],
                'exchange_item_specs' => $exchangeSpecs,
                'condition_id'        => $returnRequest['condition_id'] ?? null,
                'condition_desc'      => $returnRequest['condition_desc'] ?? null,
            ])
        );
    } else {
        /*
         * Standard return → Reverse QC flow.
         */
        Log::info('[ONDC] /update → REVERSE-QC / PART-CANCEL flow');

        $rr = $this->reverseQcService->initiateReturn($common);

        /*
         * For a partial return, create the cancellation record
         * so it appears under Active Returns / Part Cancels.
         */
        $items   = $order['items'] ?? [];
        $itemRow = collect($items)
            ->firstWhere('id', $returnRequest['item_id']);

        $originalQty = $itemRow
            ? (int) ($itemRow['quantity']['count'] ?? 0)
            : 0;

        $cancelQty = (int) ($returnRequest['item_quantity'] ?? 1);

        if (
            $originalQty > 0
            && $cancelQty > 0
            && $cancelQty <= $originalQty
        ) {
            try {
                $cancellation = $this->partialService->initiatePartialCancel([
                    'ondc_order_id'               => $orderId,
                    'transaction_id'              => $txnId,
                    'order_id'                    => $internalOrder->id ?? null,
                    'cancellation_reason_id'      => $this->mapReturnReasonToCancelCode(
                        $returnRequest['reason_id'] ?? '003'
                    ),
                    'precancel_fulfillment_state' => 'Order-delivered',
                    'initiated_by'                => 'BUYER',
                    'cancelled_by'                => $context['bap_id'] ?? 'buyerNP.com',
                    'items'                       => [[
                        'item_id'            => $returnRequest['item_id'],
                        'fulfillment_id'     => $itemRow['fulfillment_id'] ?? null,
                        'original_quantity'  => $originalQty,
                        'cancelled_quantity' => $cancelQty,
                        'item_price'         => $this->getOrderItemPrice(
                            $order,
                            $returnRequest['item_id']
                        ),
                    ]],
                    'buyer_note' => $returnRequest['reason_desc'] ?? '',
                ]);

                $rr->update([
                    'cancellation_id' => $cancellation->id,
                ]);

                Log::info('[ONDC] Linked partial cancel to return request', [
                    'cancellation_id'   => $cancellation->id,
                    'return_request_id' => $rr->id,
                ]);
            } catch (\InvalidArgumentException $e) {
                Log::info('[ONDC] Part cancel skipped (reason not eligible)', [
                    'reason' => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                Log::warning('[ONDC] Part cancel creation failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /*
     * Send callback only after return processing succeeds.
     */
    $this->sendOnUpdate($payload, $rr);
}
    

    /**
     * Build + send on_update callback to BAP.
     */
protected function sendOnUpdate(array $inbound, $rr): void
{
    Log::info('[ONDC][DEBUG] sendOnUpdate ENTRY - CODE VERSION CHECK v3');

    $inboundContext = $inbound['context'] ?? [];

    $bapUri  = $inboundContext['bap_uri'] ?? '';
    $bapId   = $inboundContext['bap_id'] ?? '';
    $txnId   = $inboundContext['transaction_id'] ?? '';

    // IMPORTANT:
    // Reuse the exact message_id received in /update.
    $updateMessageId = $inboundContext['message_id'] ?? null;

    $orderId = $inbound['message']['order']['id'] ?? '';

    Log::info('[ONDC][MESSAGE_ID] /update -> /on_update correlation', [
        'transaction_id'  => $txnId,
        'update_message_id' => $updateMessageId,
        'order_id'        => $orderId,
    ]);

    // Build payload
    if ($rr->isReplacement() || $rr->isExchange()) {

        $payload = $this->replacementExchangeService
            ->buildReplacementOrExchangePayload($rr);

    } elseif ($rr->cancellation_id) {

        $cancellation = OndcOrderCancellation::with('cancelledItems')
            ->find($rr->cancellation_id);

        if ($cancellation) {

            $payload = $this->partialService
                ->buildOnUpdatePartialCancelPayload($cancellation, [
                    'bap_id'       => $bapId,
                    'bap_uri'      => $bapUri,
                    'quote'        => $inbound['message']['order']['quote'] ?? [],
                    'provider'     => $inbound['message']['order']['provider'] ?? [],
                    'billing'      => $inbound['message']['order']['billing'] ?? [],
                    'fulfillments' => $inbound['message']['order']['fulfillments'] ?? [],
                    'state'        => 'Created',
                ]);

        } else {

            $payload = $this->reverseQcService
                ->buildReturnRequestUpdatePayload($rr);
        }

    } else {

        $payload = $this->reverseQcService
            ->buildReturnRequestUpdatePayload($rr);
    }

    /*
     * ============================================================
     * FORCE /update message_id INTO /on_update
     * ============================================================
     */
    if (empty($updateMessageId)) {
        Log::error('[ONDC][MESSAGE_ID] Incoming /update has no message_id', [
            'transaction_id' => $txnId,
        ]);

        // You can choose to return instead of generating a new ID.
        return;
    }

    // Add 1 second to the inbound /update's timestamp, per request.
    $updateTimestamp = $inboundContext['timestamp'] ?? null;
    $onUpdateTimestamp = $updateTimestamp
        ? gmdate('Y-m-d\TH:i:s.v\Z', strtotime($updateTimestamp) + 1)
        : gmdate('Y-m-d\TH:i:s.v\Z', time() + 1);

    $payload['context'] = array_merge(
        $payload['context'] ?? [],
        [
            'action'         => 'on_update',
            'transaction_id' => $txnId,

            // SAME MESSAGE ID AS INBOUND /update
            'message_id'     => $updateMessageId,

            'timestamp'      => $onUpdateTimestamp,
            'bap_id'         => $bapId,
            'bap_uri'        => $bapUri,
            'bpp_id'         => env('ONDC_BPP_ID', $inboundContext['bpp_id'] ?? ''),
            'bpp_uri'        => env('ONDC_BPP_URI', $inboundContext['bpp_uri'] ?? ''),
        ]
    );

    Log::info('[ONDC][MESSAGE_ID] FINAL correlation', [
        'transaction_id' => $txnId,
        'update_message_id' => $updateMessageId,
        'on_update_message_id' => $payload['context']['message_id'] ?? null,
        'same' => (
            $updateMessageId === ($payload['context']['message_id'] ?? null)
        ),
    ]);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $url         = rtrim($bapUri, '/') . '/on_update';

        Log::info('[ONDC] Sending on_update', ['url' => $url, 'transaction_id' => $txnId]);

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
                'transaction_id'        => $txnId,
                'action'                => 'on_update',
                'domain'                => $inboundContext['domain'] ?? 'ONDC:RET10',
                'bap_id'                => $bapId,
                'bap_uri'               => $bapUri,
                'bpp_id'                => env('ONDC_BPP_ID'),
                'status'                => 'ack_received',
                'request_payload'       => $payload,
                'response_payload'      => $response->json(),
                'response_status_code'  => $response->status(),
                'sent_at'               => now(),
                'ack_received_at'       => now(),
            ]);

            Log::info('[ONDC] on_update response', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 200),
            ]);
        } catch (\Exception $e) {
            Log::error('[ONDC] on_update send failed', [
                'transaction_id' => $txnId,
                'error'          => $e->getMessage(),
            ]);
            try {
                OndcApiLog::create([
                    'type'           => 'on_update_outbound_error',
                    'endpoint'       => $url,
                    'request'        => $payloadJson,
                    'response'       => json_encode(['error' => $e->getMessage()]),
                    'status_code'    => 500,
                    'transaction_id' => $txnId,
                ]);
            } catch (\Exception $_) {}
        }
    }



   protected function sendSettlementOnUpdate(array $inbound, array $cancelFulfillment): void
{
    $context = $inbound['context'] ?? [];
    $order   = $inbound['message']['order'] ?? [];
    $bapUri  = $context['bap_uri'] ?? '';
    $bapId   = $context['bap_id']  ?? '';
    $txnId   = $context['transaction_id'] ?? '';

    if (empty($context['message_id'])) {
        Log::error('[ONDC][MESSAGE_ID] Settlement /update missing message_id', [
            'transaction_id' => $txnId,
        ]);
        return;
    }

    $updateTimestamp = $context['timestamp'] ?? null;
    $onUpdateTimestamp = $updateTimestamp
        ? gmdate('Y-m-d\TH:i:s.v\Z', strtotime($updateTimestamp) + 1)
        : gmdate('Y-m-d\TH:i:s.v\Z', time() + 1);

    $payload = [
        'context' => [
            'domain'         => $context['domain'] ?? 'ONDC:RET10',
            'country'        => $context['country'] ?? 'IND',
            'city'           => $context['city'] ?? 'std:080',
            'core_version'   => $context['core_version'] ?? '1.2.0',
            'action'         => 'on_update',
            'transaction_id' => $txnId,
            'message_id'     => $context['message_id'],
            'timestamp'      => $onUpdateTimestamp,
            'bap_id'         => $bapId,
            'bap_uri'        => $bapUri,
            'bpp_id'         => env('ONDC_BPP_ID', $context['bpp_id'] ?? ''),
            'bpp_uri'        => env('ONDC_BPP_URI', $context['bpp_uri'] ?? ''),
        ],
        'message' => [
            'order' => [
                'id'           => $order['id'] ?? '',
                'fulfillments' => [[
                    'id'   => $cancelFulfillment['id'] ?? '',
                    'type' => 'Cancel',
                ]],
                'payment' => $order['payment'] ?? [],
            ],
        ],
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $url = rtrim($bapUri, '/') . '/on_update';

    Log::info('[ONDC] Sending settlement on_update', ['url' => $url, 'transaction_id' => $txnId]);

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
            'type'           => 'on_update_outbound_settlement',
            'endpoint'       => $url,
            'request'        => $payloadJson,
            'response'       => $response->body(),
            'status_code'    => $response->status(),
            'transaction_id' => $txnId,
        ]);

        OndcTransaction::create([
            'transaction_id'       => $txnId,
            'action'               => 'on_update',
            'domain'               => $payload['context']['domain'],
            'bap_id'               => $bapId,
            'bap_uri'              => $bapUri,
            'bpp_id'               => env('ONDC_BPP_ID'),
            'status'               => $response->successful() ? 'ack_received' : 'rejected',
            'request_payload'      => $payload,
            'response_payload'     => $response->json(),
            'response_status_code' => $response->status(),
            'sent_at'              => now(),
            'ack_received_at'      => $response->successful() ? now() : null,
        ]);

        Log::info('[ONDC] Settlement on_update response', [
            'status' => $response->status(),
            'body'   => substr($response->body(), 0, 200),
        ]);
    } catch (\Throwable $e) {
        Log::error('[ONDC] Settlement on_update send failed', [
            'transaction_id' => $txnId,
            'error'          => $e->getMessage(),
        ]);
    }
}

    // ────────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────────

    /**
     * Map a return-reason code to the closest cancel-reason code so the
     * partial-cancel service accepts it (must be in {002, 012}).
     */
    protected function mapReturnReasonToCancelCode(string $returnReason): string
    {
        return match ($returnReason) {
            '001', '004' => '012',  // buyer doesn't want anymore / delivery delay
            '002', '003', '005', '006', '007', '008' => '002', // OOS / quality / damaged / wrong / not delivered
            default => '002',
        };
    }
    /**
 * Respond to a premature buyer return request.
 *
 * The synchronous ACK has already been sent by update().
 * This callback does not create a return, initiate Reverse QC,
 * or create a cancellation.
 */
protected function sendPrematureReturnOnUpdate(array $inbound): void
{
    $context = $inbound['context'] ?? [];
    $order   = $inbound['message']['order'] ?? [];

    $txnId     = $context['transaction_id'] ?? '';
    $messageId = $context['message_id'] ?? '';
    $bapUri    = rtrim($context['bap_uri'] ?? '', '/');
    $bapId     = $context['bap_id'] ?? '';

    if (! $messageId || ! $bapUri) {
        Log::error('[ONDC] Cannot send premature-return callback: missing context fields', [
            'transaction_id' => $txnId,
            'message_id'     => $messageId,
            'bap_uri'        => $bapUri,
        ]);

        return;
    }

    $bppId = config('services.ondc.bpp_id', $context['bpp_id'] ?? '');
    $bppUri = rtrim(
        config('services.ondc.bpp_uri', $context['bpp_uri'] ?? ''),
        '/'
    );

    $responsePayload = [
        'context' => [
            'domain'         => $context['domain'] ?? 'ONDC:RET10',
            'country'        => $context['country'] ?? 'IND',
            'city'           => $context['city'] ?? 'std:080',
            'core_version'   => $context['core_version'] ?? '1.2.0',
            'action'         => 'on_update',
            'transaction_id' => $txnId,

            // Correlate this callback to the inbound /update.
            'message_id'     => $messageId,

            'timestamp'      => gmdate('Y-m-d\TH:i:s.v\Z'),
            'bap_id'         => $bapId,
            'bap_uri'        => $bapUri,
            'bpp_id'         => $bppId,
            'bpp_uri'        => $bppUri,
            'ttl'            => $context['ttl'] ?? 'PT30S',
        ],
        'message' => [
            'order' => [
                'id' => $order['id'] ?? '',
            ],
        ],
        'error' => [
            'type'    => 'DOMAIN-ERROR',
            'code'    => '30000',
            'message' => 'Return request cannot be processed because the order has not been delivered.',
        ],
    ];

    $payloadJson = json_encode(
        $responsePayload,
        JSON_UNESCAPED_SLASHES
    );

    $url = $bapUri . '/on_update';

    Log::info('[ONDC] Sending premature-return on_update', [
        'transaction_id' => $txnId,
        'message_id'     => $messageId,
        'url'            => $url,
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
            'type'           => 'on_update_premature_return',
            'endpoint'       => $url,
            'request'        => $payloadJson,
            'response'       => $response->body(),
            'status_code'    => $response->status(),
            'transaction_id' => $txnId,
        ]);

        Log::info('[ONDC] Premature-return on_update response', [
            'transaction_id' => $txnId,
            'message_id'     => $messageId,
            'status'         => $response->status(),
            'body'           => substr($response->body(), 0, 500),
        ]);
    } catch (\Throwable $e) {
        Log::error('[ONDC] Failed to send premature-return on_update', [
            'transaction_id' => $txnId,
            'message_id'     => $messageId,
            'error'          => $e->getMessage(),
        ]);
    }
}

    protected function getOrderItemPrice(array $order, string $itemId): float
    {
        // Try to read price from quote breakup
        foreach ($order['quote']['breakup'] ?? [] as $line) {
            if (($line['@ondc/org/item_id'] ?? '') === $itemId
                && ($line['@ondc/org/title_type'] ?? '') === 'item') {
                return (float) ($line['item']['price']['value'] ?? $line['price']['value'] ?? 0);
            }
        }
        return 0.0;
    }
}