<?php

namespace Webkul\Ondc\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webkul\Ondc\Models\OndcOrderCancellation;
use Webkul\Ondc\Models\OndcCancelQuoteTrail;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Ondc\Helpers\OndcHelper;

class CancellationService
{
    public function __construct(
        protected OrderRepository $orderRepository,
    ) {}

    public function handleCancelRequest(array $cancelData): void
    {
        $context   = $cancelData['context'] ?? [];
        $txnId     = $context['transaction_id'] ?? null;
        $messageId = $context['message_id'] ?? null;
        $bapUri    = rtrim($context['bap_uri'] ?? '', '/');
        $bapId     = $context['bap_id'] ?? null;

        if (!$txnId || !$messageId) {
            return;
        }

        // 1. Mark order state as Cancelled in cache
        Cache::put("ondc:order_state:{$txnId}", 'Cancelled', now()->addHours(2));

        // 2. Replay cached on_cancel if this is a repeated or retry /cancel hit
        $cachedOnCancel = Cache::get("ondc:cached_on_cancel:{$txnId}");
        if ($cachedOnCancel) {
            Log::info('[ONDC][CANCEL] Replaying cached on_cancel for retry hit', [
                'txn_id'     => $txnId,
                'message_id' => $messageId,
            ]);

            $cachedOnCancel['context']['message_id'] = $messageId;
            $cachedOnCancel['context']['timestamp']  = gmdate('Y-m-d\TH:i:s.v\Z');

            $this->dispatchOnCancelHttp($cachedOnCancel, $txnId, $bapUri, $bapId);
            return;
        }

        // 3. First execution: lookup confirmed order snapshot
        $confirmPayloadRow = DB::table('ondc_confirm_payloads')
            ->where('transaction_id', $txnId)
            ->first();

        if (!$confirmPayloadRow) {
            Log::warning('[ONDC][CANCEL] Received cancel before on_confirm saved. Deferring...', [
                'txn_id' => $txnId,
            ]);
            Cache::put("ondc:deferred_cancel:{$txnId}", $cancelData, now()->addMinutes(5));
            return;
        }

        $orderData = json_decode($confirmPayloadRow->message_order, true) ?? [];

        // 4. Record the cancellation
        $cancellation = $this->initiateBuyerCancel([
            'ondc_order_id'               => $orderData['id'] ?? $txnId,
            'transaction_id'              => $txnId,
            'cancellation_reason_id'      => data_get($cancelData, 'message.cancellation_reason_id', '051'),
            'precancel_fulfillment_state' => data_get($orderData, 'fulfillments.0.state.descriptor.code', 'Pending'),
            'bap_id'                      => $bapId,
        ]);

        // 5. Build full on_cancel payload
        $onCancelPayload = $this->buildOnCancelResponse($cancellation, $context, $orderData);

        // 6. Cache the built payload for retries
        Cache::put("ondc:cached_on_cancel:{$txnId}", $onCancelPayload, now()->addHours(1));

        // 7. Dispatch HTTP callback
        $this->dispatchOnCancelHttp($onCancelPayload, $txnId, $bapUri, $bapId);
    }

    public function initiateBuyerCancel(array $params): OndcOrderCancellation
    {
        return DB::transaction(function () use ($params) {
            $reasonCode     = (string) $params['cancellation_reason_id'];
            $preCancelState = $params['precancel_fulfillment_state'] ?? 'Pending';

            return OndcOrderCancellation::create([
                'ondc_order_id'               => $params['ondc_order_id'],
                'ondc_transaction_id'         => $params['transaction_id'],
                'order_id'                    => $params['order_id'] ?? null,
                'cancellation_reason_id'      => $reasonCode,
                'cancelled_by'                => $params['cancelled_by'] ?? ($params['bap_id'] ?? 'workbench.ondc.tech'),
                'initiated_by'                => 'BUYER',
                'cancel_type'                 => $params['cancel_type'] ?? 'FULL',
                'fulfillment_id'              => $params['fulfillment_id'] ?? null,
                'status'                      => OndcOrderCancellation::STATUS_INITIATED,
                'precancel_fulfillment_state' => $preCancelState,
                'precancel_state_updated_at'  => $params['precancel_state_updated_at'] ?? now(),
                'buyer_note'                  => $params['buyer_note'] ?? null,
            ]);
        });
    }

    public function buildOnCancelResponse(
        OndcOrderCancellation $cancellation,
        array $inboundContext,
        array $orderData
    ): array {
        $outboundTimestamp = gmdate('Y-m-d\TH:i:s.v\Z');

        $context = array_merge($inboundContext, [
            'action'    => 'on_cancel',
            'timestamp' => $outboundTimestamp,
        ]);

        $bapId          = $inboundContext['bap_id'] ?? 'workbench.ondc.tech';
        $reasonId       = (string) $cancellation->cancellation_reason_id;
        $precancelState = $cancellation->precancel_fulfillment_state ?? 'Pending';

        $fulfillments          = $orderData['fulfillments'] ?? [];
        $originalFulfillmentId = $fulfillments[0]['id'] ?? 'F1';

        // 1. Mark delivery fulfillment as Cancelled
        foreach ($fulfillments as &$fulfillment) {
            $originalFulfillmentId = $fulfillment['id'] ?? 'F1';

            $fulfillment['state'] = [
                'descriptor' => [
                    'code' => 'Cancelled',
                ],
            ];
            $fulfillment['tracking'] = false;

            $fulfillment['tags'] = [
                [
                    'code' => 'cancel_request',
                    'list' => [
                        ['code' => 'reason_id', 'value' => $reasonId],
                        ['code' => 'initiated_by', 'value' => $bapId],
                    ],
                ],
                [
                    'code' => 'precancel_state',
                    'list' => [
                        ['code' => 'fulfillment_state', 'value' => $precancelState],
                        ['code' => 'updated_at', 'value' => $outboundTimestamp],
                    ],
                ],
            ];
        }
        unset($fulfillment);

        // 2. Build Cancel fulfillment node with quote_trail tags
        $cancelFulfillmentId = (string) Str::uuid();
        $quoteTrailTags      = $this->buildQuoteTrailTags($orderData, $originalFulfillmentId);

        $cancelFulfillment = [
            'id'    => $cancelFulfillmentId,
            'type'  => 'Cancel',
            'state' => [
                'descriptor' => [
                    'code' => 'Cancelled',
                ],
            ],
            'tags'  => $quoteTrailTags,
        ];

        $fulfillments[] = $cancelFulfillment;

        // 3. Build ordered items: count 0 on delivery, count N on cancel fulfillment
        $cleanItems = [];
        foreach ($orderData['items'] ?? [] as $item) {
            $itemId    = (string) $item['id'];
            $origCount = (int) data_get($item, 'quantity.count', 1);

            $cleanItems[] = [
                'id'             => $itemId,
                'quantity'       => ['count' => 0],
                'fulfillment_id' => (string) ($item['fulfillment_id'] ?? $originalFulfillmentId),
            ];

            $cleanItems[] = [
                'id'             => $itemId,
                'quantity'       => ['count' => $origCount],
                'fulfillment_id' => $cancelFulfillmentId,
            ];
        }

        // 4. Cancelled quote (zeroed values with preserved item prices)
        $cancelledQuote = $this->buildCancelledQuote($orderData, $originalFulfillmentId);

        // Strict assertion: preserve original message.order.created_at
        $orderCreatedAt = $orderData['created_at'] ?? $outboundTimestamp;

        return [
            'context' => $context,
            'message' => [
                'order' => [
                    'id'           => $cancellation->ondc_order_id,
                    'state'        => 'Cancelled',
                    'items'        => $cleanItems,
                    'provider'     => $orderData['provider'] ?? ['id' => 'P1'],
                    'billing'      => $orderData['billing'] ?? [],
                    'fulfillments' => $fulfillments,
                    'quote'        => $cancelledQuote,
                    'payment'      => $orderData['payment'] ?? [],
                    'created_at'   => $orderCreatedAt,
                    'updated_at'   => $outboundTimestamp,
                    'cancellation' => [
                        'cancelled_by' => $bapId,
                        'reason'       => [
                            'id' => $reasonId,
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function dispatchOnCancelHttp(array $payload, string $txnId, string $bapUri, ?string $bapId): void
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $bppUri      = rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/');

        $endpoint = [
            'url'     => $bppUri . '/on_cancel',
            'type'    => 'bpp_internal',
            'headers' => [
                'Accept'         => 'application/json',
                'Content-Type'   => 'application/json',
                'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                'bap_uri'        => $bapUri,
                'bpp_uri'        => $bppUri,
            ],
        ];

        app(\Webkul\Ondc\Http\Controllers\ApiController::class)->sendToEndpoint(
            $endpoint,
            $payloadJson,
            $txnId,
            'cancel',
            $payload,
            $bapUri,
            $bapId
        );
    }

    protected function buildQuoteTrailTags(array $orderData, string $fulfillmentId): array
    {
        $tags = [];
        $quoteBreakup = $orderData['quote']['breakup'] ?? [];

        foreach ($quoteBreakup as $entry) {
            $type  = $entry['@ondc/org/title_type'] ?? 'item';
            $val   = (float) ($entry['price']['value'] ?? 0.0);
            $refId = ($type === 'item') ? ($entry['@ondc/org/item_id'] ?? '') : $fulfillmentId;

            $tags[] = [
                'code' => 'quote_trail',
                'list' => [
                    ['code' => 'type',     'value' => $type],
                    ['code' => 'id',       'value' => (string) $refId],
                    ['code' => 'currency', 'value' => 'INR'],
                    ['code' => 'value',    'value' => '-' . number_format($val, 2, '.', '')],
                ],
            ];
        }

        return $tags;
    }

    protected function buildCancelledQuote(array $orderData, string $fulfillmentId): array
    {
        $originalBreakup = $orderData['quote']['breakup'] ?? [];
        $cancelledBreakup = [];

        foreach ($originalBreakup as $entry) {
            $type = $entry['@ondc/org/title_type'] ?? 'item';

            if ($type === 'item') {
                $cancelledBreakup[] = [
                    '@ondc/org/item_id'       => (string) $entry['@ondc/org/item_id'],
                    '@ondc/org/item_quantity' => ['count' => 0],
                    'title'                   => $entry['title'] ?? 'Item',
                    '@ondc/org/title_type'    => 'item',
                    'price' => [
                        'currency' => 'INR',
                        'value'    => '0',
                    ],
                    'item' => [
                        'price' => [
                            'currency' => 'INR',
                            'value'    => number_format((float) ($entry['item']['price']['value'] ?? $entry['price']['value'] ?? 0), 2, '.', ''),
                        ],
                    ],
                ];
            } else {
                $cancelledBreakup[] = [
                    '@ondc/org/item_id'    => (string) $fulfillmentId,
                    'title'                => $entry['title'] ?? ($type === 'delivery' ? 'Delivery charges' : 'Convenience Fee'),
                    '@ondc/org/title_type' => $type,
                    'price' => [
                        'currency' => 'INR',
                        'value'    => '0',
                    ],
                ];
            }
        }

        return [
            'price' => [
                'currency' => 'INR',
                'value'    => '0',
            ],
            'breakup' => $cancelledBreakup,
            'ttl'     => 'P1D',
        ];
    }

    protected function normalizeOndcTimestamp(?string $timestamp): string
    {
        if (empty($timestamp)) {
            return gmdate('Y-m-d\TH:i:s.v\Z');
        }

        try {
            return \Carbon\Carbon::parse($timestamp)->utc()->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Throwable $e) {
            return gmdate('Y-m-d\TH:i:s.v\Z');
        }
    }
}