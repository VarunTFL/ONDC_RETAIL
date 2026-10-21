<?php
 
namespace Webkul\Ondc\Services\Buyer;
 
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
 
class OndcConfirmService
{
    public function __construct(
        protected OndcSwitchPollService $pollService
    ) {}
 
    public function confirm($ondcRow, array $initData, $order): void
    {
        $transactionId = $initData['transaction_id'];
        $messageId     = (string) Str::uuid();
        $timestamp     = now()->utc()->toIso8601ZuluString();
 
        $onInit      = $initData['on_init'] ?? [];
        $initOrder   = data_get($onInit, 'message.order', []);
        $initPayload = $initData['payload'] ?? [];
        $initContext = $initData['payload']['context'] ?? [];
 
        $payload = [
            'context' => [
                'domain'         => $initContext['domain']       ?? data_get($onInit, 'context.domain', 'ONDC:RET10'),
                'action'         => 'confirm',
                'country'        => 'IND',
                'core_version'   => '1.2.0',
                'bap_id'         => env('ONDC_BAP_ID', 'retail-buyer.softtrust.org'),
                'bap_uri'        => env('ONDC_BAP_URI', 'https://retail-buyer.softtrust.org/'),
                'bpp_id'         => $initData['bpp_id'],
                'bpp_uri'        => $initData['bpp_uri'],
                'transaction_id' => $transactionId,
                'message_id'     => $messageId,
                'timestamp'      => $timestamp,
                'ttl'            => 'PT30S',
                'city'           => $initContext['city'] ?? data_get($onInit, 'context.city', 'std:080'),
            ],
            'message' => [
                'order' => [
                    'id'           => (string) $order->id,
                    'state'        => 'Created',
                    'provider'     => $initOrder['provider']     ?? ['id' => $ondcRow->provider_id],
                    'items'        => $initOrder['items']        ?? [],
                    'billing'      => $initOrder['billing']      ?? [],
                    'fulfillments' => $initOrder['fulfillments'] ?? [],
                    'quote'        => $initOrder['quote']        ?? $initData['quote'] ?? [],
                    'payment'      => array_merge(
                        $initOrder['payment'] ?? $initData['payment'] ?? [],
                        [
                            'paid_amount' => (string) $order->grand_total,
                            'status'      => 'PAID',
                            'type'        => 'POST-FULFILLMENT',
                        ]
                    ),
                    'created_at'   => $timestamp,
                    'updated_at'   => $timestamp,
                ],
            ],
        ];
 
        $switchUrl = rtrim(env('ONDC_SWITCH_URL', 'https://retail-buyer.softtrust.org'), '/') . '/confirm';
 
        Log::info('[ONDC Confirm] Sending /confirm', [
            'transaction_id' => $transactionId,
            'order_id'       => $order->id,
            'bpp_id'         => $initData['bpp_id'],
        ]);
 
        // Log confirm sent
        DB::table('ondc_flow_log')->insert([
            'transaction_id'     => $transactionId,
            'action'             => 'confirm',
            'status'             => 'sent',
            'bagisto_product_id' => $ondcRow->id,
            'payload'            => json_encode($payload),
            'created_at'         => now(),
        ]);
 
        try {
            $response = Http::withHeaders([
                'Content-Type'   => 'application/json',
                'X-Internal-Key' => env('ONDC_BUYER_INTERNAL_KEY', 'RB7LyU6/R9X0MD4Ntr7pY0khxyxTJ/uNYWVTWO/RYWY='),
            ])->post($switchUrl, $payload);
 
            Log::info('[ONDC Confirm] ACK received', [
                'transaction_id' => $transactionId,
                'status'         => $response->status(),
                'ack'            => $response->json(),
            ]);
 
            // Poll for on_confirm
            $onConfirm = $this->pollService->poll(
                transactionId: $transactionId,
                action:        'on_confirm',
                messageId:     $messageId,
                maxAttempts:   6,
                retryDelayMs:  2000
            );
 
            if (! $onConfirm) {
                Log::warning('[ONDC Confirm] on_confirm polling timed out', [
                    'transaction_id' => $transactionId,
                ]);
 
                DB::table('ondc_flow_log')->insert([
                    'transaction_id'     => $transactionId,
                    'action'             => 'on_confirm',
                    'status'             => 'timeout',
                    'bagisto_product_id' => $ondcRow->id,
                    'payload'            => null,
                    'created_at'         => now(),
                ]);
 
            } else {
                $responses = $onConfirm['response'] ?? [$onConfirm];
                $firstResp = $responses[0] ?? [];
 
                Log::info('[ONDC Confirm] on_confirm received', [
                    'transaction_id' => $transactionId,
                    'order_id'       => $order->id,
                    'state'          => data_get($firstResp, 'message.order.state'),
                    'ondc_order_id'  => data_get($firstResp, 'message.order.id'),
                ]);
 
                DB::table('ondc_flow_log')->insert([
                    'transaction_id'     => $transactionId,
                    'action'             => 'on_confirm',
                    'status'             => 'received',
                    'bagisto_product_id' => $ondcRow->id,
                    'payload'            => json_encode($firstResp),
                    'created_at'         => now(),
                ]);
 
                // Store confirmed order for future status tracking
                Cache::put('ondc_confirm_' . $order->id, [
                    'transaction_id' => $transactionId,
                    'on_confirm'     => $firstResp,
                    'bpp_id'         => $initData['bpp_id'],
                    'bpp_uri'        => $initData['bpp_uri'],
                    'ondc_order_id'  => data_get($firstResp, 'message.order.id'),
                ], now()->addDays(7));
 
                // Save ONDC order ID against Bagisto order in DB
                DB::table('orders')
                    ->where('id', $order->id)
                    ->update([
                        'ondc_order_id'  => data_get($firstResp, 'message.order.id'),
                        'updated_at'     => now(),
                    ]);
            }
 
        } catch (\Exception $e) {
            Log::error('[ONDC Confirm] Failed', [
                'transaction_id' => $transactionId,
                'order_id'       => $order->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}