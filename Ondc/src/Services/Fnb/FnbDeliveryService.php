<?php

namespace Webkul\Ondc\Services\Fnb;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\OndcConfirmPayload;
use Webkul\Ondc\Models\OndcFnbCatalog;
use Webkul\Ondc\Models\OndcFnbItem;
use Webkul\Ondc\Models\OndcFnbOrder;
use Webkul\Ondc\Http\Controllers\ApiController;

/**
 * F&B Delivery Flow (ONDC:RET11) — flow-2
 *
 * Implements the full select → init → confirm → status (Pending → Packed → Picked → OFD → Delivered) flow
 * with item-level customizations (parent_item_id + tags).
 */
class FnbDeliveryService
{
    public const DOMAIN = 'ONDC:RET11';

    public function __construct(
        protected FnbOutOfStockService $oosService,
    ) {}

    // ──────────────────────────────────────────
    //  /select → /on_select with quote computation
    // ──────────────────────────────────────────
    public function processSelect(array $payload): array
    {
        $context = $payload['context'];
        $orderItems = $payload['message']['order']['items'] ?? [];
        $providerId = $payload['message']['order']['provider']['id'] ?? null;

        // Check OOS first
        $oosResult = $this->oosService->checkOutOfStock($orderItems);
        if ($oosResult['has_oos']) {
            return $this->oosService->buildOnSelectOutOfStock($context, $oosResult, $orderItems);
        }

        $catalog = OndcFnbCatalog::where('provider_id', $providerId)->first();
        if (! $catalog) {
            throw new \InvalidArgumentException("Provider $providerId not found");
        }

        // Build quote
        $quote = $this->buildQuote($orderItems, $catalog);

        $onSelectContext = array_merge($context, [
            'action'     => 'on_select',
            'message_id' => $context['message_id'],
            'timestamp'  => gmdate('Y-m-d\TH:i:s.v\Z', strtotime($context['timestamp'] ?? 'now') + 1),
            'bpp_id'     => config('services.ondc.bpp_id'),
            'bpp_uri'    => config('services.ondc.bpp_uri'),
        ]);

        $onSelectPayload = [
            'context' => $onSelectContext,
            'message' => [
                'order' => [
                    'provider' => [
                        'id'        => $catalog->provider_id,
                        'locations' => [['id' => $catalog->provider_id]],
                    ],
                    'items' => $this->mapItemsForResponse(
                        $orderItems,
                        $catalog
                    ),
                    'fulfillments' => [[
                        'id'                      => $catalog->provider_id,
                        'type'                    => 'Delivery',
                        '@ondc/org/category'      => 'Home Delivery',
                        '@ondc/org/provider_name' => $catalog->name,
                        '@ondc/org/TAT'           => $catalog->preparation_time,
                        'tracking'                => false,
                        'state' => [
                            'descriptor' => [
                                'code' => 'Serviceable',
                            ],
                        ],
                    ]],
                    'quote' => $quote,
                ],
            ],
        ];

        Log::info('[ONDC F&B] on_select payload built', [
            'transaction_id' => $onSelectContext['transaction_id'] ?? null,
            'message_id'     => $onSelectContext['message_id'] ?? null,
            'action'         => $onSelectContext['action'] ?? null,
        ]);

        return $onSelectPayload;
    }

    // ──────────────────────────────────────────
    //  /init → /on_init
    // ──────────────────────────────────────────
    public function processInit(array $payload): array
    {
        $context = $payload['context'];
        $order   = $payload['message']['order'];
        $providerId = $order['provider']['id'] ?? null;

        $catalog = OndcFnbCatalog::where('provider_id', $providerId)->first();
        if (! $catalog) throw new \InvalidArgumentException("Provider $providerId not found");

        $quote = $this->buildQuote($order['items'], $catalog);

        $inboundTimestamp  = $context['timestamp'] ?? gmdate('Y-m-d\TH:i:s.v\Z');
        $outboundTimestamp = gmdate('Y-m-d\TH:i:s.v\Z', strtotime($inboundTimestamp) + 1);

        $onInitContext = array_merge($context, [
            'action'     => 'on_init',
            'message_id' => $context['message_id'],
            'timestamp'  => $outboundTimestamp,
            'bpp_id'     => config('services.ondc.bpp_id'),
            'bpp_uri'    => config('services.ondc.bpp_uri'),
        ]);

        $fulfillments = array_map(function ($f) use ($catalog) {
            $f['tracking'] = $f['tracking'] ?? false;
            $f['@ondc/org/provider_name'] = $f['@ondc/org/provider_name'] ?? $catalog->name;
            return $f;
        }, $order['fulfillments'] ?? [[
            'id'   => $catalog->provider_id,
            'type' => 'Delivery',
        ]]);

        return [
            'context' => $onInitContext,
            'message' => [
                'order' => [
                    'provider'           => $order['provider'],
                    'provider_location'  => ['id' => $catalog->provider_id],
                    'items'              => $order['items'],
                    'billing'            => $order['billing'] ?? [],
                    'fulfillments'       => $fulfillments,
                    'quote'              => $quote,
                    'payment'            => array_merge($order['payment'] ?? [], [
                        'type'                                  => 'ON-FULFILLMENT',
                        'collected_by'                          => 'BAP',
                        'status'                                => 'NOT-PAID',
                        'params'                                => ['amount' => $quote['price']['value'], 'currency' => 'INR'],
                        '@ondc/org/buyer_app_finder_fee_type'   => 'percent',
                        '@ondc/org/buyer_app_finder_fee_amount' => '3',
                        '@ondc/org/settlement_details' => [[
                            'settlement_counterparty'    => 'seller-app',
                            'settlement_phase'           => 'sale-amount',
                            'settlement_type'            => 'upi',
                            'settlement_bank_account_no' => env('ONDC_BANK_ACCOUNT', '0000000000'),
                            'settlement_ifsc_code'       => env('ONDC_IFSC_CODE', 'SBIN0000000'),
                            'beneficiary_name'           => env('ONDC_BENEFICIARY_NAME', 'Store'),
                            'bank_name'                  => env('ONDC_BANK_NAME', 'State Bank of India'),
                            'branch_name'                => env('ONDC_BRANCH_NAME', 'Main Branch'),
                        ]],
                    ]),
                    'tags'               => [
                        [
                            'code' => 'bpp_terms',
                            'list' => [
                                ['code' => 'np_type', 'value' => 'MSN'],
                                ['code' => 'tax_number', 'value' => env('ONDC_SELLER_GST', 'default_gst')],
                                ['code' => 'provider_tax_number', 'value' => env('ONDC_SELLER_PAN', 'default_pan')],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────
    //  /confirm → /on_confirm — persist order
    // ──────────────────────────────────────────
    public function processConfirm(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $context = $payload['context'];
            $order   = $payload['message']['order'];
            $providerId = $order['provider']['id'] ?? null;

            $catalog = OndcFnbCatalog::where('provider_id', $providerId)->first();
            if (! $catalog) {
                throw new \InvalidArgumentException("Provider $providerId not found");
            }

            $orderId = 'FNB-' . now()->format('Y-m-d') . '-' . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);

            $fnbOrder = OndcFnbOrder::create([
                'ondc_order_id'       => $orderId,
                'ondc_transaction_id' => $context['transaction_id'],
                'catalog_id'          => $catalog->id,
                'items'               => $order['items'],
                'billing'             => $order['billing'] ?? [],
                'fulfillments'        => $order['fulfillments'] ?? [],
                'quote'               => $order['quote'] ?? $this->buildQuote($order['items'], $catalog),
                'payment'             => $order['payment'] ?? [],
                'state'               => 'Created',
                'fulfillment_state'   => 'Pending',
                'confirmed_at'        => now(),
            ]);

            $inboundTimestamp  = $context['timestamp'] ?? gmdate('Y-m-d\TH:i:s.v\Z');
            $outboundTimestamp = gmdate('Y-m-d\TH:i:s.v\Z', strtotime($inboundTimestamp) + 1);

            $onConfirmContext = array_merge($context, [
                'action'     => 'on_confirm',
                'message_id' => $context['message_id'],
                'timestamp'  => $outboundTimestamp,
                'bpp_id'     => config('services.ondc.bpp_id'),
                'bpp_uri'    => config('services.ondc.bpp_uri'),
            ]);

            $createdAt = $order['created_at'] ?? $outboundTimestamp;
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $createdAt)) {
                $createdAt = str_replace('Z', '.000Z', $createdAt);
            }

            $payment = $fnbOrder->payment ?? [];
            if (!isset($payment['params']['transaction_id'])) {
                $payment['params']['transaction_id'] = $context['transaction_id'];
            }

            $fulfillments = array_map(function ($f) use ($catalog) {
                $f['@ondc/org/provider_name'] = $f['@ondc/org/provider_name'] ?? $catalog->name;
                $f['state'] = $f['state'] ?? ['descriptor' => ['code' => 'Pending']];
                $f['tracking'] = false; // Strictly false at confirm
                
                if (!isset($f['start'])) {
                    $f['start'] = [
                        'location' => [
                            'id' => $catalog->provider_id,
                            'descriptor' => ['name' => $catalog->name],
                            'gps' => config('ondc.store.gps', '21.145800,79.088200'), 
                            'address' => [
                                'locality'  => config('ondc.store.address.locality', 'Store Locality'),
                                'city'      => config('ondc.store.address.city', 'Nagpur'),
                                'area_code' => config('ondc.store.address.area_code', '440001'),
                                'state'     => config('ondc.store.address.state', 'Maharashtra')
                            ]
                        ],
                        'contact' => [
                            'phone' => config('ondc.store.phone', '9999999999'),
                            'email' => config('ondc.store.email', 'store@example.com')
                        ]
                    ];
                }
                return $f;
            }, $order['fulfillments'] ?? []);

            $messageOrder = [
                'id'           => $orderId,
                'state'        => 'Created',
                'provider'     => $order['provider'],
                'items'        => $order['items'],
                'billing'      => $order['billing'] ?? [],
                'fulfillments' => $fulfillments,
                'quote'        => $fnbOrder->quote,
                'payment'      => $payment,
                'created_at'   => $createdAt,
                'updated_at'   => $outboundTimestamp,
                'tags'         => array_merge($order['tags'] ?? [], [
                    [
                        'code' => 'bpp_terms',
                        'list' => [
                            ['code' => 'np_type', 'value' => 'MSN'],
                            ['code' => 'tax_number', 'value' => env('ONDC_SELLER_GST', 'default_gst')],
                            ['code' => 'provider_tax_number', 'value' => env('ONDC_SELLER_PAN', 'default_pan')],
                        ],
                    ]
                ]),
            ];

            OndcConfirmPayload::updateOrCreate(
                ['transaction_id' => $context['transaction_id']],
                [
                    'id'               => (string) Str::uuid(),
                    'increment_id'     => $context['transaction_id'],
                    'context'          => $onConfirmContext,
                    'message_order'    => $messageOrder,
                    'bap_id'           => $context['bap_id'],
                    'bap_uri'          => $context['bap_uri'],
                    'domain'           => $context['domain'],
                    'bagisto_order_id' => null,
                ]
            );

            foreach ($order['items'] as $line) {
                $item = OndcFnbItem::where('ondc_item_id', $line['id'])->first();
                if ($item) {
                    $newCount = max(0, $item->available_count - ($line['quantity']['count'] ?? 0));
                    $item->update(['available_count' => $newCount]);
                }
            }

            return [
                'context' => $onConfirmContext,
                'message' => ['order' => $messageOrder],
            ];
        });
    }

    // ──────────────────────────────────────────
    //  Build unsolicited on_status from stored F&B order
    // ──────────────────────────────────────────
    public function buildOnStatus(OndcFnbOrder $order, ?string $messageId = null): array
    {
        $outboundTimestamp = gmdate('Y-m-d\TH:i:s.v\Z');
        $createdAt = $order->created_at ? $order->created_at->format('Y-m-d\TH:i:s.v\Z') : $outboundTimestamp;

        $confirmPayload = OndcConfirmPayload::where('transaction_id', $order->ondc_transaction_id)->first();
        $savedContext = $confirmPayload ? $confirmPayload->context : [];
        if (is_string($savedContext)) {
            $savedContext = json_decode($savedContext, true) ?? [];
        }

        $context = [
            'domain'         => self::DOMAIN,
            'action'         => 'on_status',
            'country'        => $savedContext['country'] ?? 'IND',
            'city'           => $savedContext['city'] ?? 'std:080',
            'core_version'   => $savedContext['core_version'] ?? '1.2.0',
            'bap_id'         => $savedContext['bap_id'] ?? config('services.ondc.bap_id'),
            'bap_uri'        => $savedContext['bap_uri'] ?? config('services.ondc.bap_uri'),
            'bpp_id'         => config('services.ondc.bpp_id'),
            'bpp_uri'        => config('services.ondc.bpp_uri'),
            'transaction_id' => $order->ondc_transaction_id,
            'message_id'     => $messageId ?: (string) Str::uuid(),
            'timestamp'      => $outboundTimestamp,
            'ttl'            => 'PT30S',
        ];

        $statusCode = $order->fulfillment_state;
        $isTrackingActive = in_array($statusCode, ['Order-picked-up', 'Out-for-delivery'], true);

        $bppUri = rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/');
        $trackingBase = rtrim(env('ONDC_TRACKING_URL') ?: ($bppUri . '/tracking'), '/');

        $fulfillments = $order->fulfillments ?? [];
        $fulfillments = collect($fulfillments)->map(function ($f) use ($statusCode, $outboundTimestamp, $isTrackingActive, $trackingBase) {
            $f['state']      = ['descriptor' => ['code' => $statusCode]];
            $f['tracking']   = $isTrackingActive;
            $f['updated_at'] = $outboundTimestamp;
            $f['updated_by'] = config('services.ondc.bpp_id');

            if (in_array($statusCode, ['Order-picked-up', 'Out-for-delivery'], true)) {
                $f['start']['time']['timestamp'] = $outboundTimestamp;
            }
            if ($statusCode === 'Order-delivered') {
                $f['end']['time']['timestamp'] = $outboundTimestamp;
            }

            $tags = collect($f['tags'] ?? [])
                ->reject(fn($t) => ($t['code'] ?? '') === 'tracking')
                ->values()
                ->all();

            if ($isTrackingActive) {
                $tags[] = [
                    'code' => 'tracking',
                    'list' => [
                        ['code' => 'gps_enabled', 'value' => 'yes'],
                        ['code' => 'url_enabled', 'value' => 'yes'],
                        ['code' => 'url',         'value' => $trackingBase . '/' . ($f['id'] ?? 'F1')],
                    ],
                ];
            }
            $f['tags'] = $tags;

            return $f;
        })->values()->all();

        return [
            'context' => $context,
            'message' => [
                'order' => [
                    'id'           => $order->ondc_order_id,
                    'state'        => $order->state,
                    'provider'     => ['id' => $order->catalog->provider_id ?? 'P1'],
                    'items'        => $order->items,
                    'billing'      => $order->billing,
                    'fulfillments' => $fulfillments,
                    'quote'        => $order->quote,
                    'payment'      => $order->payment,
                    'created_at'   => $createdAt,
                    'updated_at'   => $outboundTimestamp,
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────
    //  Advance status via Seller Admin Button & Dispatch to Network
    // ──────────────────────────────────────────
    public function updateFnbOrderStatus(string|int $orderId, string $ondcStatus): OndcFnbOrder
    {
        $order = OndcFnbOrder::where('id', $orderId)
            ->orWhere('ondc_order_id', $orderId)
            ->firstOrFail();

        $orderState = match ($ondcStatus) {
            'Pending'          => 'Created',
            'Packed'           => 'Accepted',
            'Order-delivered'  => 'Completed',
            default            => 'In-progress',
        };

        $update = [
            'fulfillment_state' => $ondcStatus,
            'state'             => $orderState,
        ];

        $update += match ($ondcStatus) {
            'Packed'           => ['packed_at' => now()],
            'Order-picked-up'  => ['picked_at' => now()],
            'Out-for-delivery' => ['out_for_delivery_at' => now()],
            'Order-delivered'  => ['delivered_at' => now()],
            default            => [],
        };

        $order->update($update);

        $txnId = $order->ondc_transaction_id;

        // Synchronize tracking cache gates
        if (in_array($ondcStatus, ['Order-picked-up', 'Out-for-delivery'], true)) {
            Cache::put("ondc:picked_up:{$txnId}", true, now()->addHours(2));
            Cache::put("ondc:tracking_enabled:{$txnId}", true, now()->addHours(2));
        }
        if ($ondcStatus === 'Order-delivered') {
            Cache::forget("ondc:tracking_enabled:{$txnId}");
        }

        // Dispatch unsolicited on_status to BAP
        $confirmPayload = OndcConfirmPayload::where('transaction_id', $txnId)->first();
        if ($confirmPayload) {
            $context = is_array($confirmPayload->context) ? $confirmPayload->context : json_decode($confirmPayload->context, true);
            app(ApiController::class)->sendOnStatus(
                $txnId,
                $order->ondc_order_id,
                $ondcStatus,
                $confirmPayload->bap_uri,
                $confirmPayload->bap_id,
                $context
            );
        }

        Log::info('[ONDC F&B] Status advanced via button and dispatched', [
            'order_id' => $order->ondc_order_id,
            'status'   => $ondcStatus,
        ]);

        return $order->fresh();
    }

    // ──────────────────────────────────────────
    //  Quote computation (sums items + customizations + delivery + tax)
    // ──────────────────────────────────────────
    protected function buildQuote(array $orderItems, OndcFnbCatalog $catalog): array
    {
        $breakup = [];
        $subtotal = 0;

        foreach ($orderItems as $line) {
            $item = OndcFnbItem::where('ondc_item_id', $line['id'])->first();
            if (! $item) continue;

            $count    = (int) ($line['quantity']['count'] ?? 1);
            $lineCost = (float) $item->price * $count;
            $subtotal += $lineCost;

            $breakup[] = [
                '@ondc/org/item_id'        => $item->ondc_item_id,
                '@ondc/org/item_quantity'  => ['count' => $count],
                'title'                    => $item->name,
                '@ondc/org/title_type'     => 'item',
                'price'                    => ['currency' => 'INR', 'value' => number_format($lineCost, 2, '.', '')],
                'item' => [
                    'price' => ['currency' => 'INR', 'value' => number_format((float) $item->price, 2, '.', '')],
                ],
            ];
        }

        $deliveryCharge = 25.00;
        $breakup[] = [
            '@ondc/org/item_id'    => $catalog->provider_id,
            'title'                => 'Delivery Charges',
            '@ondc/org/title_type' => 'delivery',
            'price'                => ['currency' => 'INR', 'value' => number_format($deliveryCharge, 2, '.', '')],
        ];

        $packing = 5.00;
        $breakup[] = [
            '@ondc/org/item_id'    => $catalog->provider_id,
            'title'                => 'Packing Charges',
            '@ondc/org/title_type' => 'packing',
            'price'                => ['currency' => 'INR', 'value' => number_format($packing, 2, '.', '')],
        ];

        $tax = round($subtotal * 0.05, 2);
        $breakup[] = [
            '@ondc/org/item_id'    => $catalog->provider_id,
            'title'                => 'GST',
            '@ondc/org/title_type' => 'tax',
            'price'                => ['currency' => 'INR', 'value' => number_format($tax, 2, '.', '')],
        ];

        $total = $subtotal + $deliveryCharge + $packing + $tax;

        return [
            'price'   => ['currency' => 'INR', 'value' => number_format($total, 2, '.', '')],
            'breakup' => $breakup,
            'ttl'     => 'P1D',
        ];
    }

    protected function mapItemsForResponse(array $items, OndcFnbCatalog $catalog): array
    {
        return collect($items)->map(function ($i) use ($catalog) {
            $itemId = $i['id'];
            $tags = $i['tags'] ?? [];
            $type = 'item';

            foreach ($tags as $tag) {
                if (($tag['code'] ?? null) === 'type') {
                    foreach ($tag['list'] ?? [] as $listItem) {
                        if (($listItem['code'] ?? null) === 'type') {
                            $type = $listItem['value'] ?? 'item';
                            break 2;
                        }
                    }
                }
            }

            if (! in_array($type, ['item', 'customization'], true)) {
                throw new \InvalidArgumentException("Invalid item type '$type' for item $itemId");
            }

            $parentItemId = $i['parent_item_id'] ?? null;
            if ($type === 'customization' && empty($parentItemId)) {
                throw new \InvalidArgumentException("Customization $itemId must have a parent_item_id");
            }

            if ($type === 'item' && empty($parentItemId)) {
                $parentItemId = $itemId;
            }

            $tags = array_values(array_filter(
                $tags,
                fn ($tag) => ($tag['code'] ?? null) !== 'type'
            ));

            $tags[] = [
                'code' => 'type',
                'list' => [
                    ['code' => 'type', 'value' => $type],
                ],
            ];

            return [
                'fulfillment_id' => $catalog->provider_id,
                'id'             => $itemId,
                'parent_item_id' => $parentItemId,
                'tags'           => $tags,
            ];
        })->values()->all();
    }
}