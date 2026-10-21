<?php

namespace Webkul\Ondc\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\OndcApiLog;
use App\Models\OndcTransaction;
use Webkul\Ondc\Models\OndcOrderCancellation;
use Webkul\Ondc\Models\OndcCancellationReason;
use Webkul\Ondc\Models\OndcCancellationTerm;
use Webkul\Ondc\Services\CancellationService;
use Webkul\Ondc\Http\Controllers\ApiController;

class CancellationController extends Controller
{
    public function __construct(
        protected CancellationService $cancellationService,
    ) {}

    /**
     * Flush ACK immediately to the caller and terminate connection.
     */
    protected function sendAckAndCloseConnection(array $context, string $status = 'ACK'): void
    {
        $ackPayload = json_encode([
            'context' => $context,
            'message' => [
                'ack' => ['status' => $status],
            ],
        ]);

        ignore_user_abort(true);
        set_time_limit(120);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        header('Connection: close');
        header('Content-Length: ' . strlen($ackPayload));

        echo $ackPayload;
        flush();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        
    }

    /**
     * POST /ondc/cancel
     */
    public function cancel(Request $request)
    {
        $payload   = $request->all();
        $context   = $payload['context'] ?? [];
        $txnId     = $context['transaction_id'] ?? '';
        $messageId = $context['message_id'] ?? '';

        // 1. Instantly return ACK and close socket so Workbench never hangs
        $this->sendAckAndCloseConnection($context);

        if (!$txnId || !$messageId) {
            exit;
        }

        // 2. Prevent duplicate execution slots for the same message_id
        $lockKey = "ondc:lock:cancel:{$txnId}:{$messageId}";
        if (! Cache::add($lockKey, true, 30)) {
            exit;
        }

        Log::info('[ONDC][CANCEL] Processing incoming /cancel request immediately', [
            'transaction_id' => $txnId,
            'message_id'     => $messageId,
        ]);

        // 3. Process cancellation immediately using the exact request context
        try {
            $this->processCancelSync($payload);
        } catch (\Throwable $e) {
            Log::error('[ONDC] Cancel processing failed', [
                'txn_id'  => $txnId,
                'message' => $e->getMessage(),
            ]);
        }

        exit;
    }

    public function processCancelSync(array $cancelData): void
    {
        $context       = $cancelData['context'] ?? [];
        $message       = $cancelData['message'] ?? [];
        $transactionId = $context['transaction_id'] ?? '';
        $orderId       = $message['order_id'] ?? '';
        $reasonId      = $message['cancellation_reason_id'] ?? '051';
        $messageId     = $context['message_id'] ?? '';

        Log::info('[ONDC] Processing valid cancellation after on_confirm green', [
            'transaction_id' => $transactionId,
            'order_id'       => $orderId,
            'message_id'     => $messageId,
        ]);

        Cache::put("ondc:order_state:{$transactionId}", 'Cancelled', now()->addHours(2));

        $confirmPayload = $this->findConfirmPayload($transactionId, $orderId);
        $messageOrder   = $confirmPayload ? (json_decode($confirmPayload->message_order, true) ?? []) : [];
        $savedContext   = $confirmPayload ? (is_array($confirmPayload->context) ? $confirmPayload->context : (json_decode($confirmPayload->context, true) ?? [])) : [];
        $bagistoOrderId = $confirmPayload->bagisto_order_id ?? null;
        $orderFulfillmentId = $messageOrder['fulfillments'][0]['id'] ?? 'F1';

        $cancellation = $this->cancellationService->initiateBuyerCancel([
            'ondc_order_id'               => $orderId ?: ($messageOrder['id'] ?? $transactionId),
            'cancellation_reason_id'      => $reasonId,
            'transaction_id'              => $transactionId,
            'bpp_id'                      => $context['bpp_id'] ?? config('services.ondc.bpp_id'),
            'bpp_uri'                     => $context['bpp_uri'] ?? config('services.ondc.bpp_uri'),
            'bap_id'                      => $context['bap_id'] ?? '',
            'bap_uri'                     => $context['bap_uri'] ?? '',
            'domain'                      => $context['domain'] ?? 'ONDC:RET10',
            'city'                        => $context['city'] ?? 'std:080',
            'order_id'                    => $bagistoOrderId,
            'fulfillment_id'              => $message['descriptor']['short_desc'] ?? $orderFulfillmentId,
            'precancel_fulfillment_state' => $this->resolvePrecancelFulfillmentState($messageOrder),
        ]);

        // Send matching on_cancel with exact inbound message_id
        $this->sendBuyerOnCancel($cancellation, $savedContext, $messageOrder, $context);
    }

    protected function findConfirmPayload(string $transactionId, string $ondcOrderId): ?object
    {
        $query = DB::table('ondc_confirm_payloads');

        if ($transactionId !== '') {
            $query->where(function ($q) use ($transactionId) {
                $q->where('transaction_id', $transactionId)
                  ->orWhere('increment_id', $transactionId);
            });
        } else {
            $query->where('increment_id', $ondcOrderId);
        }

        return $query->orderByDesc('created_at')->first();
    }

    protected function resolvePrecancelFulfillmentState(array $messageOrder): string
    {
        return $messageOrder['fulfillments'][0]['state']['descriptor']['code'] ?? 'Pending';
    }

  protected function sendBuyerOnCancel(
        OndcOrderCancellation $cancellation,
        array $savedContext,
        array $messageOrder,
        array $inboundContext
    ): void {
       $inboundTimestamp = $inboundContext['timestamp'] ?? gmdate('Y-m-d\TH:i:s.v\Z');
        
        // STRICT MONOTONIC: Always 1 second ahead of incoming /cancel timestamp
        $outboundTimestamp = gmdate('Y-m-d\TH:i:s.v\Z', strtotime($inboundTimestamp) + 1);
        $txnId = $inboundContext['transaction_id'] ?? ($savedContext['transaction_id'] ?? '');
        $bapUri = rtrim($inboundContext['bap_uri'] ?? ($savedContext['bap_uri'] ?? ''), '/');
        $bapId = $inboundContext['bap_id'] ?? ($savedContext['bap_id'] ?? 'workbench.ondc.tech');

        // Explicit, complete context with all required properties
        $callbackContext = [
            'domain'         => $inboundContext['domain'] ?? ($savedContext['domain'] ?? 'ONDC:RET10'),
            'country'        => $inboundContext['country'] ?? ($savedContext['country'] ?? 'IND'),
            'city'           => $inboundContext['city'] ?? ($savedContext['city'] ?? 'std:080'),
            'core_version'   => $inboundContext['core_version'] ?? ($savedContext['core_version'] ?? '1.2.0'),
            'action'         => 'on_cancel',
            'bap_id'         => $bapId,
            'bap_uri'        => $bapUri,
            'bpp_id'         => config('services.ondc.bpp_id', 'retail-seller.softtrust.org'),
            'bpp_uri'        => config('services.ondc.bpp_uri', 'https://retail-seller.softtrust.org'),
            'transaction_id' => $txnId,
            'message_id'     => $inboundContext['message_id'] ?? (string) Str::uuid(),
            'timestamp'      => $outboundTimestamp,
            'ttl'            => 'PT30S',
        ];

        $orderCreatedAt = $messageOrder['created_at'] ?? $outboundTimestamp;

        $payload = $this->cancellationService->buildOnCancelResponse(
            $cancellation,
            $callbackContext,
            [
                'id'           => $messageOrder['id'] ?? $cancellation->ondc_order_id,
                'items'        => $messageOrder['items'] ?? [],
                'provider'     => $messageOrder['provider'] ?? ['id' => 'P1'],
                'billing'      => $messageOrder['billing'] ?? [],
                'fulfillments' => $messageOrder['fulfillments'] ?? [],
                'quote'        => $messageOrder['quote'] ?? [],
                'payment'      => $messageOrder['payment'] ?? [],
                'created_at'   => $orderCreatedAt,
                'updated_at'   => $outboundTimestamp,
            ]
        );

        // Guarantee context is present in the final payload
        $payload['context'] = $callbackContext;

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $bppUri      = rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/');

        $headers = [
            'Accept'         => 'application/json',
            'Content-Type'   => 'application/json',
            'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
            'bap_uri'        => $bapUri,
            'bpp_uri'        => $bppUri,
        ];

        $endpoint = [
            'url'     => $bppUri . '/on_cancel',
            'type'    => 'bpp_internal',
            'headers' => $headers,
        ];

        Log::info('[ONDC][CANCEL] Dispatching on_cancel with verified context', [
            'txn_id'     => $txnId,
            'message_id' => $callbackContext['message_id'],
        ]);

        app(ApiController::class)->sendToEndpoint(
            $endpoint,
            $payloadJson,
            $txnId,
            'cancel',
            $payload,
            $bapUri,
            $bapId
        );
    }

    public function onCancel(Request $request): JsonResponse
    {
        return response()->json(['message' => ['ack' => ['status' => 'ACK']]]);
    }
}