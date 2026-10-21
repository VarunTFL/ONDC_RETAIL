<?php

namespace Webkul\Ondc\Services\Buyer;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OndcSwitchPollService
{
    /**
     * Poll the ONDC Switch for an async callback response.
     *
     * The Switch stores the seller NP's on_* callback and returns it
     * when queried via POST /api/get_ondc_response.
     *
     * @param  string      $transactionId  UUID from the original request
     * @param  string      $action         e.g. "on_search", "on_select", "on_confirm"
     * @param  string|null $messageId      Optional — narrows to a specific message
     * @param  int         $maxAttempts    How many times to retry if not ready yet
     * @param  int         $retryDelayMs   Milliseconds to wait between retries
     * @return array|null  Decoded JSON payload, or null if not available
     */
    public function poll(
        string  $transactionId,
        string  $action        = 'on_search',
        ?string $messageId     = null,
        int     $maxAttempts   = 5,
        int     $retryDelayMs  = 2000
    ): ?array {
        $switchUrl = 'https://retail-buyer.softtrust.org/api/get_ondc_response';

        $body = [
            'transaction_id' => $transactionId,
            'action'         => $action,
        ];

        if ($messageId !== null) {
            $body['message_id'] = $messageId;
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            Log::info("ONDC poll attempt {$attempt}/{$maxAttempts}", [
                'action'         => $action,
                'transaction_id' => $transactionId,
            ]);

            $response = Http::withHeaders([
                'Content-Type'   => 'application/json',
                'X-Internal-Key' => 'RB7LyU6/R9X0MD4Ntr7pY0khxyxTJ/uNYWVTWO/RYWY=', // env('ONDC_BUYER_INTERNAL_KEY'),
            ])->post($switchUrl, $body);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('ONDC poll response', [
                    'attempt' => $attempt,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);

                // Switch returns null / empty / error message when not ready yet.
                // A valid on_search payload always contains context.action.
                if (isset($data['response_code'])
                    && $data['response_code'] == 1
                    && ! empty($data['response'])) {
                    Log::info('ONDC poll success', [
                        'transaction_id' => $transactionId,
                        'response_count' => count($data['response']),
                    ]);

                    return $data;
                }
            }

            if ($attempt < $maxAttempts) {
                usleep($retryDelayMs * 1000);
            }
        }

        Log::warning('ONDC poll exhausted without result', [
            'action'         => $action,
            'transaction_id' => $transactionId,
            'max_attempts'   => $maxAttempts,
        ]);

        return null;
    }
}