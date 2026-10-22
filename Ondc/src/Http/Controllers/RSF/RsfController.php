<?php

namespace Webkul\Ondc\Http\Controllers\RSF;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Http\Controllers\Controller;

class RsfController extends Controller
{
    /**
     * Submit the Collector's NP-NP settlement instructions to the Settlement Agency.
     */
    public function settle(Request $request)
    {
        $payload = $request->all();
        $context = $payload['context'] ?? null;

        if (!is_array($context) || !isset($payload['message'])) {
            return response()->json([
                'error' => 'Invalid payload: context or message missing',
            ], 400);
        }

        if (($context['domain'] ?? null) !== 'ONDC:NTS10'
            || ($context['action'] ?? null) !== 'settle') {
            return response()->json([
                'error' => 'Invalid RSF context: expected domain ONDC:NTS10 and action settle',
            ], 400);
        }

        $settlement = data_get($payload, 'message.settlement');
        $bppUri = rtrim((string) ($context['bpp_uri'] ?? ''), '/');

        if (!is_array($settlement) || $bppUri === '') {
            return response()->json([
                'error' => 'Required parameters missing: message.settlement or context.bpp_uri',
            ], 400);
        }

        if (($settlement['type'] ?? null) !== 'NP-NP') {
            return response()->json([
                'error' => 'This endpoint currently supports settlement type NP-NP only',
            ], 400);
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(30)
                ->post($bppUri . '/settle', $payload);


            if ($response->failed()) {
            Log::warning('[RSF][SETTLE] Settlement Agency error response', [
                'transaction_id' => $context['transaction_id'] ?? null,
                'message_id' => $context['message_id'] ?? null,
                'http_status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);
        }

            Log::info('[RSF][SETTLE] Settlement Agency response received', [
                'transaction_id' => $context['transaction_id'] ?? null,
                'message_id' => $context['message_id'] ?? null,
                'http_status' => $response->status(),
            ]);

            return response()->json(
                $response->json() ?? ['message' => ['ack' => ['status' => 'ACK']]],
                $response->status()
            );
        } catch (\Throwable $e) {
            Log::error('[RSF][SETTLE] Failed to send settlement instructions', [
                'transaction_id' => $context['transaction_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Unable to reach the Settlement Agency',
            ], 502);
        }
    }

    /**
     * Receive the Settlement Agency's on_settle callback.
     * Settlement callback data is intentionally not persisted at this stage.
     */
   
    public function onSettle(Request $request)
    {
        $payload = $request->all();
        $context = $payload['context'] ?? null;

        // Validate context and ensure either message or error is present.
        if (
            !is_array($context) ||
            (!isset($payload['message']) && !isset($payload['error']))
        ) {
            return response()->json([
                'error' => 'Invalid payload: context and message or error missing',
            ], 400);
        }

        // Validate ONDC domain and callback action.
        if (
            ($context['domain'] ?? null) !== 'ONDC:NTS10' ||
            ($context['action'] ?? null) !== 'on_settle'
        ) {
            return response()->json([
                'error' => 'Invalid RSF context: expected domain ONDC:NTS10 and action on_settle',
            ], 400);
        }

        // Handle an error response from the Settlement Agency.
        if (isset($payload['error'])) {
            if (!is_array($payload['error'])) {
                return response()->json([
                    'error' => 'Invalid payload: error must be an object',
                ], 400);
            }

            Log::warning('[RSF][ON_SETTLE] Error callback received', [
                'transaction_id' => $context['transaction_id'] ?? null,
                'message_id' => $context['message_id'] ?? null,
                'error_code' => $payload['error']['code'] ?? null,
                'error_message' => $payload['error']['message'] ?? null,
            ]);

            return response()->json([
                'message' => [
                    'ack' => [
                        'status' => 'ACK',
                    ],
                ],
            ], 200);
        }

        // Validate a normal successful callback.
        if (!is_array($payload['message'])) {
            return response()->json([
                'error' => 'Invalid payload: message must be an object',
            ], 400);
        }

        Log::info('[RSF][ON_SETTLE] Settlement callback received', [
            'transaction_id' => $context['transaction_id'] ?? null,
            'message_id' => $context['message_id'] ?? null,
            'settlement_id' => data_get($payload, 'message.settlement.id'),
        ]);

        // Acknowledge the successful callback.
        return response()->json([
            'message' => [
                'ack' => [
                    'status' => 'ACK',
                ],
            ],
        ], 200);
    }

      /*
    |--------------------------------------------------------------------------
    | RSF 2.0 — NIL Settlement Flow (ADD-ON)
    |--------------------------------------------------------------------------
    | Append these methods INSIDE the existing RsfController class,
    | immediately before the class's final closing brace.
    | These methods do not modify the existing settle() / onSettle() methods.
    */

    /**
     * NIL-only settle endpoint.
     *
     * Forwards a NIL settlement payload to the Settlement Agency.
     */
    public function nilSettle(Request $request)
    {
        $payload = $request->all();
        $context = $payload['context'] ?? null;
        $settlement = data_get($payload, 'message.settlement');

        if (!is_array($context) || !is_array($settlement)) {
            return response()->json([
                'error' => 'Invalid payload: context or message.settlement missing',
            ], 400);
        }

        if (
            ($context['domain'] ?? null) !== 'ONDC:NTS10' ||
            ($context['action'] ?? null) !== 'settle'
        ) {
            return response()->json([
                'error' => 'Invalid RSF context: expected ONDC:NTS10 and settle',
            ], 400);
        }

        if (($settlement['type'] ?? null) !== 'NIL') {
            return response()->json([
                'error' => 'This endpoint supports settlement type NIL only',
            ], 400);
        }

        $bppUri = rtrim((string) ($context['bpp_uri'] ?? ''), '/');

        if ($bppUri === '') {
            return response()->json([
                'error' => 'Required parameter missing: context.bpp_uri',
            ], 400);
        }

        $endpoint = $bppUri . '/settle';

        try {
            Log::info('[RSF][NIL][SETTLE] Forwarding NIL settlement request', [
                'transaction_id' => $context['transaction_id'] ?? null,
                'message_id' => $context['message_id'] ?? null,
                'endpoint' => $endpoint,
            ]);

            $response = Http::acceptJson()
                ->asJson()
                ->timeout(30)
                ->post($endpoint, $payload);

            Log::info('[RSF][NIL][SETTLE] Settlement Agency response received', [
                'transaction_id' => $context['transaction_id'] ?? null,
                'http_status' => $response->status(),
            ]);

            $contentType = $response->header('Content-Type', '');

            if (str_contains(strtolower($contentType), 'json')) {
                return response(
                    $response->body(),
                    $response->status(),
                    ['Content-Type' => 'application/json']
                );
            }

            return response($response->body(), $response->status());
        } catch (\Throwable $e) {
            Log::error('[RSF][NIL][SETTLE] Failed to reach Settlement Agency', [
                'transaction_id' => $context['transaction_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Unable to reach the Settlement Agency',
            ], 502);
        }
    }

        /**
         * NIL-only on_settle callback endpoint.
         */
        public function nilOnSettle(Request $request)
        {
            $payload = $request->all();
            $context = $payload['context'] ?? null;
            $settlement = data_get($payload, 'message.settlement');

            if (!is_array($context) || !is_array($settlement)) {
                return response()->json([
                    'error' => 'Invalid payload: context or message.settlement missing',
                ], 400);
            }

            if (
                ($context['domain'] ?? null) !== 'ONDC:NTS10' ||
                ($context['action'] ?? null) !== 'on_settle'
            ) {
                return response()->json([
                    'error' => 'Invalid RSF context: expected ONDC:NTS10 and on_settle',
                ], 400);
            }

            if (($settlement['type'] ?? null) !== 'NIL') {
                return response()->json([
                    'error' => 'This endpoint supports settlement type NIL only',
                ], 400);
            }

            Log::info('[RSF][NIL][ON_SETTLE] NIL callback received', [
                'transaction_id' => $context['transaction_id'] ?? null,
                'message_id' => $context['message_id'] ?? null,
            ]);

            return response()->json([
                'message' => [
                    'ack' => [
                        'status' => 'ACK',
                    ],
                ],
            ], 200);
        }
        /*
|--------------------------------------------------------------------------
| RSF 2.0 — REPORT Flow (ADD-ON)
|--------------------------------------------------------------------------
| Add these methods INSIDE the existing RsfController class, before its
| final closing brace. Do not replace existing settle(), onSettle(), or NIL
| methods.
*/

/**
 * Receive a REPORT request and forward it to the Settlement Agency.
 *
 * Expected message fields:
 * - ref_transaction_id
 * - ref_message_id
 */
public function report(Request $request)
{
    $payload = $request->all();
    $context = $payload['context'] ?? null;
    $message = $payload['message'] ?? null;

    if (!is_array($context) || !is_array($message)) {
        return response()->json([
            'error' => 'Invalid payload: context or message missing',
        ], 400);
    }

    if (
        ($context['domain'] ?? null) !== 'ONDC:NTS10' ||
        ($context['action'] ?? null) !== 'report'
    ) {
        return response()->json([
            'error' => 'Invalid RSF context: expected domain ONDC:NTS10 and action report',
        ], 400);
    }

    if (
        empty($message['ref_transaction_id']) ||
        empty($message['ref_message_id'])
    ) {
        return response()->json([
            'error' => 'Required message fields missing: ref_transaction_id or ref_message_id',
        ], 400);
    }

    $bppUri = rtrim((string) ($context['bpp_uri'] ?? ''), '/');

    if ($bppUri === '') {
        return response()->json([
            'error' => 'Required parameter missing: context.bpp_uri',
        ], 400);
    }

    $endpoint = $bppUri . '/report';

    try {
        Log::info('[RSF][REPORT] Forwarding report request to Settlement Agency', [
            'transaction_id' => $context['transaction_id'] ?? null,
            'message_id' => $context['message_id'] ?? null,
            'ref_transaction_id' => $message['ref_transaction_id'],
            'ref_message_id' => $message['ref_message_id'],
            'endpoint' => $endpoint,
        ]);

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->post($endpoint, $payload);

        Log::info('[RSF][REPORT] Settlement Agency response received', [
            'transaction_id' => $context['transaction_id'] ?? null,
            'http_status' => $response->status(),
        ]);

        $contentType = $response->header('Content-Type', '');

        if (str_contains(strtolower($contentType), 'json')) {
            return response($response->body(), $response->status(), [
                'Content-Type' => 'application/json',
            ]);
        }

        return response($response->body(), $response->status());
    } catch (\Throwable $e) {
        Log::error('[RSF][REPORT] Failed to reach Settlement Agency', [
            'transaction_id' => $context['transaction_id'] ?? null,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'error' => 'Unable to reach the Settlement Agency',
        ], 502);
    }
}

    /**
     * Receive the Settlement Agency's on_report callback.
     *
     * The callback settlement object is logged but not persisted here.
     */
    public function onReport(Request $request)
    {
        $payload = $request->all();
        $context = $payload['context'] ?? null;
        $message = $payload['message'] ?? null;

        if (!is_array($context) || !is_array($message)) {
            return response()->json([
                'error' => 'Invalid payload: context or message missing',
            ], 400);
        }

        if (
            ($context['domain'] ?? null) !== 'ONDC:NTS10' ||
            ($context['action'] ?? null) !== 'on_report'
        ) {
            return response()->json([
                'error' => 'Invalid RSF context: expected domain ONDC:NTS10 and action on_report',
            ], 400);
        }

        if (!isset($message['settlement']) || !is_array($message['settlement'])) {
            return response()->json([
                'error' => 'Required message field missing: message.settlement',
            ], 400);
        }

        Log::info('[RSF][ON_REPORT] Settlement report callback received', [
            'transaction_id' => $context['transaction_id'] ?? null,
            'message_id' => $context['message_id'] ?? null,
            'settlement_id' => data_get($message, 'settlement.id'),
            'settlement_type' => data_get($message, 'settlement.type'),
            'order_count' => is_array(data_get($message, 'settlement.orders'))
                ? count(data_get($message, 'settlement.orders'))
                : 0,
        ]);

        return response()->json([
            'message' => [
                'ack' => [
                    'status' => 'ACK',
                ],
            ],
        ], 200);
    }
    /*
|--------------------------------------------------------------------------
| RSF 2.0 — RECON Flow (ADD-ON)
|--------------------------------------------------------------------------
| Add these methods INSIDE the existing RsfController class, immediately
| before its final closing brace. Do not replace existing RSF methods.
*/

/**
 * Forward a RECON request to the counterparty specified by context.bpp_uri.
 * RECON is bidirectional; either participant may initiate the request.
 */
public function recon(Request $request)
{
    $payload = $request->all();
    $context = $payload['context'] ?? null;
    $message = $payload['message'] ?? null;
    $orders = is_array($message) ? ($message['orders'] ?? null) : null;

    if (!is_array($context) || !is_array($message)) {
        return response()->json(['error' => 'Invalid payload: context or message missing'], 400);
    }

    if (($context['domain'] ?? null) !== 'ONDC:NTS10' || ($context['action'] ?? null) !== 'recon') {
        return response()->json(['error' => 'Invalid RSF context: expected domain ONDC:NTS10 and action recon'], 400);
    }

    if (!is_array($orders) || count($orders) === 0) {
        return response()->json(['error' => 'Required message field missing or invalid: message.orders'], 400);
    }

    $bppUri = rtrim((string) ($context['bpp_uri'] ?? ''), '/');
    if ($bppUri === '') {
        return response()->json(['error' => 'Required parameter missing: context.bpp_uri'], 400);
    }

    $endpoint = $bppUri . '/recon';

    try {
        Log::info('[RSF][RECON] Forwarding reconciliation request', [
            'transaction_id' => $context['transaction_id'] ?? null,
            'message_id' => $context['message_id'] ?? null,
            'order_count' => count($orders),
            'endpoint' => $endpoint,
        ]);

        $response = Http::acceptJson()->asJson()->timeout(30)->post($endpoint, $payload);

        Log::info('[RSF][RECON] Counterparty response received', [
            'transaction_id' => $context['transaction_id'] ?? null,
            'http_status' => $response->status(),
        ]);

        $contentType = $response->header('Content-Type', '');
        if (str_contains(strtolower($contentType), 'json')) {
            return response($response->body(), $response->status(), ['Content-Type' => 'application/json']);
        }

        return response($response->body(), $response->status());
    } catch (\Throwable $e) {
        Log::error('[RSF][RECON] Failed to reach counterparty', [
            'transaction_id' => $context['transaction_id'] ?? null,
            'error' => $e->getMessage(),
        ]);

        return response()->json(['error' => 'Unable to reach the reconciliation counterparty'], 502);
    }
}

/**
 * Receive the counterparty's on_recon callback. The reconciliation details
 * are accepted and summarized in logs, but are not persisted by this add-on.
 */
public function onRecon(Request $request)
{
    $payload = $request->all();
    $context = $payload['context'] ?? null;
    $message = $payload['message'] ?? null;
    $orders = is_array($message) ? ($message['orders'] ?? null) : null;

    if (!is_array($context) || !is_array($message)) {
        return response()->json(['error' => 'Invalid payload: context or message missing'], 400);
    }

    if (($context['domain'] ?? null) !== 'ONDC:NTS10' || ($context['action'] ?? null) !== 'on_recon') {
        return response()->json(['error' => 'Invalid RSF context: expected domain ONDC:NTS10 and action on_recon'], 400);
    }

    if (!is_array($orders) || count($orders) === 0) {
        return response()->json(['error' => 'Required message field missing or invalid: message.orders'], 400);
    }

    $settlementCount = 0;
    foreach ($orders as $order) {
        if (is_array($order) && is_array($order['settlements'] ?? null)) {
            $settlementCount += count($order['settlements']);
        }
    }

    Log::info('[RSF][ON_RECON] Reconciliation response received', [
        'transaction_id' => $context['transaction_id'] ?? null,
        'message_id' => $context['message_id'] ?? null,
        'order_count' => count($orders),
        'settlement_count' => $settlementCount,
    ]);

    return response()->json([
        'message' => [
            'ack' => [
                'status' => 'ACK',
            ],
        ],
    ], 200);
}
}

