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

        if (!is_array($context) || !isset($payload['message'])) {
            return response()->json([
                'error' => 'Invalid payload: context or message missing',
            ], 400);
        }

        if (($context['domain'] ?? null) !== 'ONDC:NTS10'
            || ($context['action'] ?? null) !== 'on_settle') {
            return response()->json([
                'error' => 'Invalid RSF context: expected domain ONDC:NTS10 and action on_settle',
            ], 400);
        }

        Log::info('[RSF][ON_SETTLE] Settlement callback received', [
            'transaction_id' => $context['transaction_id'] ?? null,
            'message_id' => $context['message_id'] ?? null,
            'settlement_id' => data_get($payload, 'message.settlement.id'),
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
