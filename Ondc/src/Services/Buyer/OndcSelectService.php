<?php
 
namespace Webkul\Ondc\Services\Buyer;
 
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
 
class OndcSelectService
{
    public function __construct(
        protected OndcSwitchPollService $pollService
    ) {}
 
    /**
     * Send /select for an ondc_items row, poll for on_select, return the quote.
     */
    public function select(int $ondcItemId, int $quantity = 1, array $deliveryLocation = []): array
    {
        // Lookup by ondc_items.id directly
        $item = DB::table('ondc_items')
            ->where('id', $ondcItemId)
            ->whereNotNull('bpp_id')
            ->first();
 
        if (! $item) {
            // Try by bagisto_product_id as fallback
            $item = DB::table('ondc_items')
                ->where('bagisto_product_id', $ondcItemId)
                ->whereNotNull('bpp_id')
                ->latest()
                ->first();
        }
 
        if (! $item) {
            Log::info('[ONDC Select] Non-ONDC product — skipping select', [
                'ondc_item_id' => $ondcItemId,
            ]);
            return ['success' => true, 'skipped' => true, 'reason' => 'non-ondc-product'];
        }
 
        // Reuse transaction_id from the search (stored in cache by bpp_id)
        $transactionId = Cache::get("ondc_bpp_txn:{$item->bpp_id}") ?? $item->transaction_id;
        $messageId     = (string) Str::uuid();
        $timestamp     = now()->utc()->toIso8601ZuluString();
 
        // Default delivery location if not supplied
        $gps      = $deliveryLocation['gps']      ?? '21.1458,79.0882';  // Nagpur
        $areaCode = $deliveryLocation['area_code'] ?? '440016';           // Nagpur pincode
 
        $payload = [
            'context' => [
                'domain'         => $item->domain ?? 'ONDC:RET10',
                'action'         => 'select',
                'core_version'   => '1.2.0',
                'bap_id'         => env('ONDC_BAP_ID', 'retail-buyer.softtrust.org'),
                'bap_uri'        => env('ONDC_BAP_URI', 'https://retail-buyer.softtrust.org/'),
                'bpp_id'         => $item->bpp_id,
                'bpp_uri'        => $item->bpp_uri,
                'transaction_id' => $transactionId,
                'message_id'     => $messageId,
                'city'           => 'std:022',
                'country'        => 'IND',
                'timestamp'      => $timestamp,
                'ttl'            => 'PT30S',
            ],
            'message' => [
                'order' => [
                    'provider' => [
                        'id'        => $item->provider_id,
                        'locations' => [['id' => $item->location_id]],
                    ],
                    'items' => [
                        [
                            'id'          => $item->item_id,
                            'quantity'    => ['count' => $quantity],
                            'location_id' => $item->location_id,
                        ],
                    ],
                    'fulfillments' => [
                        [
                            'type'          => 'Delivery',
                            '@ondc/org/TAT' => 'PT24H',
                            'end'           => [
                                'location' => [
                                    'gps'     => $gps,
                                    'address' => ['area_code' => $areaCode],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
 
        $switchUrl = rtrim(env('ONDC_SWITCH_URL', 'https://retail-buyer.softtrust.org'), '/') . '/select';
 
        Log::info('[ONDC Select] Sending /select to switch', [
            'transaction_id' => $transactionId,
            'message_id'     => $messageId,
            'item_id'        => $item->item_id,
            'provider_id'    => $item->provider_id,
            'bpp_id'         => $item->bpp_id,
        ]);
 
        $response = Http::withHeaders([
            'Content-Type'   => 'application/json',
            'X-Internal-Key' => env('ONDC_BUYER_INTERNAL_KEY', 'RB7LyU6/R9X0MD4Ntr7pY0khxyxTJ/uNYWVTWO/RYWY='),
        ])->post($switchUrl, $payload);
 
        Log::info('[ONDC Select] ACK received from switch', [
            'transaction_id' => $transactionId,
            'status'         => $response->status(),
            'ack'            => $response->json(),
        ]);
 
        if (! $response->successful()) {
            throw new \RuntimeException('Select request failed: ' . $response->body());
        }
 
        // Log select sent to flow log
        if (DB::getSchemaBuilder()->hasTable('ondc_flow_log')) {
            DB::table('ondc_flow_log')->insert([
                'transaction_id'     => $transactionId,
                'action'             => 'select',
                'status'             => 'sent',
                'bagisto_product_id' => $item->id,
                'payload'            => json_encode($payload),
                'created_at'         => now(),
            ]);
        }
 
        // Poll for on_select
        $onSelect = $this->pollService->poll(
            transactionId: $transactionId,
            action:        'on_select',
            messageId:     $messageId,
            maxAttempts:   6,
            retryDelayMs:  2000
        );
 
        if (! $onSelect) {
            throw new \RuntimeException('on_select polling timed out for transaction ' . $transactionId);
        }
 
        // Extract quote from on_select response
        $responses = $onSelect['response'] ?? [$onSelect];
        $firstResp = $responses[0] ?? [];
        $quote     = data_get($firstResp, 'message.order.quote') ?? [];
        $items     = data_get($firstResp, 'message.order.items') ?? [];
        $error     = data_get($firstResp, 'error');
 
        if ($error) {
            Log::warning('[ONDC Select] on_select returned error', [
                'transaction_id' => $transactionId,
                'error'          => $error,
            ]);
        }

        // After extracting error:
        $error            = data_get($firstResp, 'error');
        $fulfillmentState = data_get($firstResp, 'message.order.fulfillments.0.state.descriptor.code');
        $isNonServiceable = $fulfillmentState === 'Non-serviceable' || 
                            data_get($error, 'code') === '30010';

        if ($isNonServiceable) {
            Log::warning('[ONDC Select] Item non-serviceable — blocking cart add', [
                'transaction_id' => $transactionId,
                'error'          => $error,
            ]);

            return [
                'success'   => false,
                'skipped'   => false,
                'error'     => 'This product is not deliverable to your location. Please try a product from a nearby seller.',
                'code'      => 'NON_SERVICEABLE',
            ];
        }
 
        Log::info('[ONDC Select] on_select received', [
            'transaction_id' => $transactionId,
            'quote'          => $quote,
            'item_count'     => count($items),
        ]);
 
        // Log on_select to flow log
        if (DB::getSchemaBuilder()->hasTable('ondc_flow_log')) {
            DB::table('ondc_flow_log')->insert([
                'transaction_id'     => $transactionId,
                'action'             => 'on_select',
                'status'             => 'received',
                'bagisto_product_id' => $item->id,
                'payload'            => json_encode($firstResp),
                'created_at'         => now(),
            ]);
        }
 
        // Quote data to cache
        $quotePayload = [
            'transaction_id' => $transactionId,
            'item_id'        => $item->item_id,
            'provider_id'    => $item->provider_id,
            'bpp_id'         => $item->bpp_id,
            'bpp_uri'        => $item->bpp_uri,
            'quote'          => $quote,
            'items'          => $items,
            'on_select'      => $firstResp,
        ];
 
        // Store by ondc_items.id
        $quoteKey = 'ondc_quote_' . $item->id;
        cache()->put($quoteKey, $quotePayload, now()->addHours(1));
 
        // Store by bagisto_product_id (from DB row)
        if ($item->bagisto_product_id) {
            cache()->put('ondc_quote_bpid_' . $item->bagisto_product_id, $quotePayload, now()->addHours(1));
        }
 
        // Store by ondcItemId passed from JS (covers cases where bagisto_product_id is null in DB)
        cache()->put('ondc_quote_bpid_' . $ondcItemId, $quotePayload, now()->addHours(1));
 
        Log::info('[ONDC Select] Quote stored in cache', [
            'transaction_id'   => $transactionId,
            'quote_key'        => $quoteKey,
            'bpid_key'         => 'ondc_quote_bpid_' . $ondcItemId,
            'db_bagisto_pid'   => $item->bagisto_product_id,
            'total'            => data_get($quote, 'price.value'),
        ]);
 
        return [
            'success'        => true,
            'transaction_id' => $transactionId,
            'quote'          => $quote,
            'items'          => $items,
            'quote_key'      => $quoteKey,
        ];
    }
}
 