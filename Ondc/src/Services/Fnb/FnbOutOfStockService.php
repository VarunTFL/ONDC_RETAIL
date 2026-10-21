<?php

namespace Webkul\Ondc\Services\Fnb;

use Illuminate\Support\Facades\Log;
use Webkul\Ondc\Models\OndcFnbItem;

/**
 * F&B Out-of-Stock Flow (ONDC:RET11)
 */
class FnbOutOfStockService
{
    /**
     * Check whether requested items are available in stock.
     */
    public function checkOutOfStock(array $orderItems): array
    {
        $itemIds = collect($orderItems)
            ->pluck('id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $items = OndcFnbItem::whereIn('ondc_item_id', $itemIds)
            ->get()
            ->keyBy('ondc_item_id');

        $oosItems = [];
        $available = [];

        foreach ($orderItems as $line) {
            $itemId = $line['id'] ?? null;

            if (! $itemId) {
                continue;
            }

            $item = $items->get($itemId);

            if (! $item) {
                $oosItems[] = [
                    'id'     => $itemId,
                    'reason' => 'item_not_found',
                ];

                continue;
            }

            $requestedQty = (int) ($line['quantity']['count'] ?? 1);

            if ($item->isOutOfStock()) {
                $oosItems[] = [
                    'id'     => $itemId,
                    'reason' => 'out_of_stock',
                    'item'   => $item,
                ];

                continue;
            }

            if ((int) $item->available_count < $requestedQty) {
                $oosItems[] = [
                    'id'              => $itemId,
                    'reason'          => 'insufficient_stock',
                    'available_count' => $item->available_count,
                    'requested_count' => $requestedQty,
                    'item'             => $item,
                ];

                continue;
            }

            $available[] = $line;
        }

        return [
            'has_oos'   => count($oosItems) > 0,
            'oos_items' => $oosItems,
            'available' => $available,
        ];
    }

    /**
     * Build on_select payload for an out-of-stock scenario.
     */
    public function buildOnSelectOutOfStock(
        array $context,
        array $oosResult,
        array $orderItems
    ): array {
        /*
         * These IDs must match the IDs used in your ONDC catalog.
         * Configure them in config/ondc.php if they differ.
         */
        $providerId = config('ondc.fnb.provider_id', 'P1');
        $locationId = config('ondc.fnb.location_id', 'L1');
        $fulfillmentId = config('ondc.fnb.fulfillment_id', 'F1');

        $oosIds = collect($oosResult['oos_items'] ?? [])
            ->pluck('id')
            ->all();

        $catalogItems = OndcFnbItem::whereIn(
            'ondc_item_id',
            collect($orderItems)->pluck('id')->filter()->unique()->all()
        )->get()->keyBy('ondc_item_id');

        $itemsResponse = collect($orderItems)
            ->map(function ($line) use (
                $oosIds,
                $catalogItems,
                $fulfillmentId
            ) {
                $itemId = $line['id'] ?? null;

                if (! $itemId) {
                    return null;
                }

                $catalogItem = $catalogItems->get($itemId);

                /*
                 * Preserve the type tag from the incoming /select request.
                 * If it is missing, derive it from the catalog record.
                 */
                $tags = $line['tags'] ?? [];

                $type = $this->getItemType($tags, $catalogItem);

                $tags = $this->ensureTypeTag($tags, $type);

                /*
                 * Preserve parent_item_id from the incoming /select item.
                 * If it is absent, use the item's own ID as a fallback for
                 * a root item. For customizations, the actual parent item
                 * ID must be supplied by the incoming request.
                 */
                $parentItemId = $line['parent_item_id'] ?? null;

                if ($parentItemId === null && $type === 'item') {
                    $parentItemId = $itemId;
                }

                $entry = [
                    'fulfillment_id' => $fulfillmentId,
                    'id'             => $itemId,
                    'parent_item_id' => $parentItemId,
                    'tags'            => $tags,
                ];

                if (in_array($itemId, $oosIds, true)) {
                    $entry['@ondc/org/item_status'] = 'out_of_stock';
                }

                return $entry;
            })
            ->filter()
            ->values()
            ->all();

        $onSelectContext = array_merge($context, [
            'action'     => 'on_select',
            'message_id' => $context['message_id'] ?? null,
            'timestamp'  => now()->toIso8601ZuluString(),
            'bpp_id'     => config('ondc.bpp_id'),
            'bpp_uri'    => config('ondc.bpp_uri'),
        ]);

        Log::info('[ONDC F&B] OOS detected on /select', [
            'transaction_id' => $context['transaction_id'] ?? null,
            'oos_count'      => count($oosIds),
            'oos_item_ids'   => $oosIds,
            'response_items' => $itemsResponse,
        ]);

        return [
            'context' => $onSelectContext,

            'message' => [
                'order' => [
                    'provider' => [
                        'id'        => $providerId,
                        'locations' => [
                            ['id' => $locationId],
                        ],
                    ],

                    'items' => $itemsResponse,

                    'fulfillments' => [[
                        'id'   => $fulfillmentId,
                        'type' => 'Delivery',
                    ]],

                    'quote' => $this->buildPartialQuote(
                        $oosResult['available'] ?? [],
                        $providerId
                    ),
                ],
            ],

            'error' => [
                'type'    => 'DOMAIN-ERROR',
                'code'    => '40002',
                'message' => 'One or more selected items are out of stock',
            ],
        ];
    }

    /**
     * Determine item type from the request tags or catalog.
     */
    protected function getItemType(array $tags, $catalogItem): string
    {
        foreach ($tags as $tag) {
            if (($tag['code'] ?? null) !== 'type') {
                continue;
            }

            foreach (($tag['list'] ?? []) as $listItem) {
                if (($listItem['code'] ?? null) === 'type') {
                    $value = $listItem['value'] ?? null;

                    if (in_array($value, ['item', 'customization'], true)) {
                        return $value;
                    }
                }
            }
        }

        if (
            $catalogItem
            && ($catalogItem->type ?? null) === OndcFnbItem::TYPE_CUSTOMIZATION
        ) {
            return 'customization';
        }

        return 'item';
    }

    /**
     * Ensure a valid type tag exists.
     */
    protected function ensureTypeTag(array $tags, string $type): array
    {
        $found = false;

        foreach ($tags as &$tag) {
            if (($tag['code'] ?? null) !== 'type') {
                continue;
            }

            $found = true;
            $tag['list'] = [[
                'code'  => 'type',
                'value' => $type,
            ]];
        }

        unset($tag);

        if (! $found) {
            $tags[] = [
                'code' => 'type',
                'list' => [[
                    'code'  => 'type',
                    'value' => $type,
                ]],
            ];
        }

        return array_values($tags);
    }

    /**
     * Build quote for available items only.
     */
    protected function buildPartialQuote(
        array $availableItems,
        string $providerId
    ): array {
        $breakup = [];
        $subtotal = 0;

        foreach ($availableItems as $line) {
            $itemId = $line['id'] ?? null;

            if (! $itemId) {
                continue;
            }

            $item = OndcFnbItem::where('ondc_item_id', $itemId)->first();

            if (! $item) {
                continue;
            }

            $count = (int) ($line['quantity']['count'] ?? 1);
            $lineCost = (float) $item->price * $count;

            $subtotal += $lineCost;

            $breakup[] = [
                '@ondc/org/item_id'       => $item->ondc_item_id,
                '@ondc/org/item_quantity' => [
                    'count' => $count,
                ],
                'title'                => $item->name,
                '@ondc/org/title_type' => 'item',
                'price' => [
                    'currency' => 'INR',
                    'value'    => number_format($lineCost, 2, '.', ''),
                ],
            ];
        }

        return [
            'price' => [
                'currency' => 'INR',
                'value'    => number_format($subtotal, 2, '.', ''),
            ],
            'breakup' => $breakup,
            'ttl'     => 'P1D',
        ];
    }

    /**
     * Suggest alternative available items.
     */
    public function suggestAlternatives(string $itemId, int $limit = 3): array
    {
        $item = OndcFnbItem::where('ondc_item_id', $itemId)->first();

        if (! $item) {
            return [];
        }

        return OndcFnbItem::where('catalog_id', $item->catalog_id)
            ->where('id', '<>', $item->id)
            ->where('type', OndcFnbItem::TYPE_ITEM)
            ->where('is_available', true)
            ->where('available_count', '>', 0)
            ->where('veg_status', $item->veg_status)
            ->orderByRaw('ABS(price - ?) ASC', [$item->price])
            ->limit($limit)
            ->get()
            ->toArray();
    }
}