<?php
 
namespace Webkul\Ondc\Services\Buyer;
 
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
 
class OndcInitService
{
    public function __construct(
        protected OndcSwitchPollService $pollService
    ) {}
 
    public function init(array $cartItems, array $billing): void
    {
        foreach ($cartItems as $cartItem) {
 
            // Try bpid key first, then row id key
            $quoteData = Cache::get('ondc_quote_bpid_' . ($cartItem['bagisto_product_id'] ?? 0))
                      ?? Cache::get('ondc_quote_' . $cartItem['ondc_item_row_id']);
 
            if (! $quoteData) {
                Log::warning('[ONDC Init] No cached quote found', [
                    'ondc_item_row_id'   => $cartItem['ondc_item_row_id'],
                    'bagisto_product_id' => $cartItem['bagisto_product_id'] ?? null,
                ]);
                continue;
            }
 
            $transactionId = $quoteData['transaction_id'];
            $messageId     = (string) Str::uuid();
            $timestamp     = now()->utc()->toIso8601ZuluString();
 
            // Get data from on_select response
            $onSelect      = $quoteData['on_select'] ?? [];
            $fulfillments  = data_get($onSelect, 'message.order.fulfillments', []);
            $fulfillment   = $fulfillments[0] ?? [];
            $fulfillmentId = $fulfillment['id'] ?? 'F1';
            $fulfillType   = $fulfillment['type'] ?? 'Delivery';
            $tat           = $fulfillment['@ondc/org/TAT'] ?? 'PT24H';
            $providerName  = $fulfillment['@ondc/org/provider_name'] ?? '';
            $category      = $fulfillment['@ondc/org/category'] ?? 'Standard Delivery';
 
            // Provider location from on_select
            $providerLocs = data_get($onSelect, 'message.order.provider.locations', []);
            $locationId   = $providerLocs[0]['id'] ?? $cartItem['location_id'];
 
            // Quote from on_select
            $quote   = $quoteData['quote'] ?? [];
            $breakup = $quote['breakup'] ?? [];
 
            // Total price from quote
            $totalPrice = $quote['price']['value'] ?? '0';
            $currency   = $quote['price']['currency'] ?? 'INR';
 
            // Buyer finder fee — 3% hardcoded
            $finderFeeType   = 'percent';
            $finderFeeAmount = '3';
 
            // Build linked order items from quote breakup
            $linkedOrderItems = [];
            foreach ($breakup as $b) {
                if (($b['@ondc/org/title_type'] ?? '') === 'item') {
                    $linkedOrderItems[] = [
                        'category_id' => 'Grocery',
                        'descriptor'  => [
                            'name' => $b['title'] ?? '',
                        ],
                        'quantity' => [
                            'count' => $b['@ondc/org/item_quantity']['count'] ?? $cartItem['quantity'],
                        ],
                        'price' => [
                            'currency' => $b['price']['currency'] ?? $currency,
                            'value'    => $b['price']['value'] ?? '0',
                        ],
                    ];
                }
            }
 
            $payload = [
                'context' => [
                    'domain'         => data_get($onSelect, 'context.domain', 'ONDC:RET10'),
                    'action'         => 'init',
                    'country'        => 'IND',
                    'core_version'   => '1.2.0',
                    'bap_id'         => env('ONDC_BAP_ID', 'retail-buyer.softtrust.org'),
                    'bap_uri'        => env('ONDC_BAP_URI', 'https://retail-buyer.softtrust.org/'),
                    'bpp_id'         => $quoteData['bpp_id'],
                    'bpp_uri'        => $quoteData['bpp_uri'],
                    'transaction_id' => $transactionId,
                    'message_id'     => $messageId,
                    'timestamp'      => $timestamp,
                    'ttl'            => 'PT30S',
                    'city'           => data_get($onSelect, 'context.city', 'std:080'),
                ],
                'message' => [
                    'order' => [
                        'provider' => [
                            'id'        => $quoteData['provider_id'],
                            'locations' => [['id' => $locationId]],
                        ],
                        'items' => [
                            [
                                'id'             => $quoteData['item_id'],
                                'quantity'       => ['count' => $cartItem['quantity']],
                                'location_id'    => $locationId,
                                'fulfillment_id' => $fulfillmentId,
                            ],
                        ],
                        'billing' => [
                            'name'       => $billing['name'],
                            'phone'      => $billing['phone'],
                            'email'      => $billing['email'],
                            'address'    => [
                                'name'      => $billing['name'],
                                'building'  => $billing['street'] ?? '',
                                'locality'  => $billing['street'] ?? '',
                                'city'      => $billing['city'],
                                'state'     => $billing['state'],
                                'country'   => 'IND',
                                'area_code' => $billing['postcode'],
                            ],
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ],
                        'fulfillments' => [
                            [
                                'id'       => $fulfillmentId,
                                'type'     => $fulfillType,
                                'tracking' => false,
                                '@ondc/org/TAT'           => $tat,
                                '@ondc/org/category'      => $category,
                                '@ondc/org/provider_name' => $providerName,
                                'end' => [
                                    'contact' => [
                                        'email' => $billing['email'],
                                        'phone' => $billing['phone'],
                                    ],
                                    'location' => [
                                        'gps' => '21.1458,79.0882',
                                        'address' => [
                                            'name'      => $billing['name'],
                                            'building'  => $billing['street'] ?? '',
                                            'locality'  => $billing['street'] ?? '',
                                            'city'      => $billing['city'],
                                            'state'     => $billing['state'],
                                            'country'   => 'IND',
                                            'area_code' => $billing['postcode'],
                                        ],
                                    ],
                                ],
                                'tags' => [
                                    [
                                        'code' => 'linked_order',
                                        'list' => [
                                            [
                                                'code'  => 'id',
                                                'value' => $transactionId,
                                            ],
                                        ],
                                    ],
                                ],
                                '@ondc/org/linked_order' => [
                                    'items'    => $linkedOrderItems,
                                    'provider' => [
                                        'id'        => $quoteData['provider_id'],
                                        'locations' => [['id' => $locationId]],
                                    ],
                                    'order' => [
                                        'id'         => $transactionId,
                                        'created_at' => $timestamp,
                                        'updated_at' => $timestamp,
                                    ],
                                ],
                            ],
                        ],
                        'payment' => [
                            '@ondc/org/buyer_app_finder_fee_type'   => $finderFeeType,
                            '@ondc/org/buyer_app_finder_fee_amount' => $finderFeeAmount,
                            '@ondc/org/settlement_details'          => [
                                [
                                    'settlement_counterparty'   => 'buyer-app',
                                    'settlement_phase'          => 'sale-amount',
                                    'settlement_type'           => 'upi',
                                    'upi_address'               => env('ONDC_UPI_ADDRESS', 'buyer@upi'),
                                    'settlement_bank_account_no'=> env('ONDC_BANK_ACCOUNT', ''),
                                    'settlement_ifsc_code'      => env('ONDC_IFSC_CODE', ''),
                                    'beneficiary_name'          => env('ONDC_BENEFICIARY_NAME', 'Buyer App'),
                                    'bank_name'                 => env('ONDC_BANK_NAME', ''),
                                    'branch_name'               => env('ONDC_BRANCH_NAME', ''),
                                ],
                            ],
                        ],
                    ],
                ],
            ];
 
            $switchUrl = rtrim(env('ONDC_SWITCH_URL', 'https://retail-buyer.softtrust.org'), '/') . '/init';
 
            Log::info('[ONDC Init] Sending /init', [
                'transaction_id'   => $transactionId,
                'item_id'          => $quoteData['item_id'],
                'bpp_id'           => $quoteData['bpp_id'],
                'fulfillment_id'   => $fulfillmentId,
                'finder_fee_type'  => $finderFeeType,
                'finder_fee_amount'=> $finderFeeAmount,
            ]);
 
            // Log init sent
            DB::table('ondc_flow_log')->insert([
                'transaction_id'     => $transactionId,
                'action'             => 'init',
                'status'             => 'sent',
                'bagisto_product_id' => $cartItem['ondc_item_row_id'],
                'payload'            => json_encode($payload),
                'created_at'         => now(),
            ]);
 
            try {
                $response = Http::withHeaders([
                    'Content-Type'   => 'application/json',
                    'X-Internal-Key' => env('ONDC_BUYER_INTERNAL_KEY', 'RB7LyU6/R9X0MD4Ntr7pY0khxyxTJ/uNYWVTWO/RYWY='),
                ])->post($switchUrl, $payload);
 
                Log::info('[ONDC Init] ACK received', [
                    'transaction_id' => $transactionId,
                    'status'         => $response->status(),
                    'ack'            => $response->json(),
                ]);
 
                // Poll for on_init
                $onInit = $this->pollService->poll(
                    transactionId: $transactionId,
                    action:        'on_init',
                    messageId:     $messageId,
                    maxAttempts:   6,
                    retryDelayMs:  2000
                );
 
                if (! $onInit) {
                    Log::warning('[ONDC Init] on_init polling timed out', [
                        'transaction_id' => $transactionId,
                    ]);
 
                    DB::table('ondc_flow_log')->insert([
                        'transaction_id'     => $transactionId,
                        'action'             => 'on_init',
                        'status'             => 'timeout',
                        'bagisto_product_id' => $cartItem['ondc_item_row_id'],
                        'payload'            => null,
                        'created_at'         => now(),
                    ]);
 
                } else {
                    $responses = $onInit['response'] ?? [$onInit];
                    $firstResp = $responses[0] ?? [];
                    $payment   = data_get($firstResp, 'message.order.payment');
                    $quote     = data_get($firstResp, 'message.order.quote');
 
                    Log::info('[ONDC Init] on_init received', [
                        'transaction_id' => $transactionId,
                        'quote'          => $quote,
                        'payment'        => $payment,
                    ]);
 
                    // Store for confirm step
                    Cache::put('ondc_init_' . $cartItem['ondc_item_row_id'], [
                        'transaction_id' => $transactionId,
                        'bpp_id'         => $quoteData['bpp_id'],
                        'bpp_uri'        => $quoteData['bpp_uri'],
                        'on_init'        => $firstResp,
                        'payment'        => $payment,
                        'quote'          => $quote,
                        'payload'        => $payload,
                    ], now()->addHours(1));
 
                    DB::table('ondc_flow_log')->insert([
                        'transaction_id'     => $transactionId,
                        'action'             => 'on_init',
                        'status'             => 'received',
                        'bagisto_product_id' => $cartItem['ondc_item_row_id'],
                        'payload'            => json_encode($firstResp),
                        'created_at'         => now(),
                    ]);
                }
 
            } catch (\Exception $e) {
                Log::error('[ONDC Init] Failed', [
                    'transaction_id' => $transactionId,
                    'error'          => $e->getMessage(),
                ]);
            }
        }
    }
}
 

 