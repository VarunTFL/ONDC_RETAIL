<?php

namespace Webkul\Ondc\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webkul\Ondc\Models\OndcCancellationReason;
use Webkul\Ondc\Models\OndcCancelledItem;
use Webkul\Ondc\Models\OndcOrderCancellation;

/**
 * Partial order cancellation — seller cancels only some items from an order,
 * the rest continues through the normal fulfillment flow.
 *
 * ONDC uses the /update + on_update action for this (not /cancel),
 * with the cancelled items moved to a new "Cancel"-type fulfillment.
 *
 * Reason codes applicable for part cancel: 002, 012
 */
class PartialCancellationService
{
    public function __construct() {}

    /**
     * Initiate a partial cancellation for specific items.
     *
     * @param array $params  [
     *   'ondc_order_id' => string,
     *   'transaction_id' => string,
     *   'cancellation_reason_id' => string,
     *   'cancelled_by' => string (optional, defaults to BPP id),
     *   'initiated_by' => string ('SELLER' | 'BUYER'),
     *   'items' => [ ['item_id', 'fulfillment_id', 'original_quantity', 'cancelled_quantity', 'item_price'], ... ],
     *   'precancel_fulfillment_state' => string,
     *   'seller_note' => string,
     * ]
     */
    public function initiatePartialCancel(array $params): OndcOrderCancellation
    {
        return DB::transaction(function () use ($params) {
            // Validate reason is applicable for part-cancel
            $reason = OndcCancellationReason::find($params['cancellation_reason_id']);
            if (! $reason) {
                throw new \InvalidArgumentException("Invalid reason code: {$params['cancellation_reason_id']}");
            }
            if (! $reason->applicable_for_part_cancel) {
                throw new \InvalidArgumentException(
                    "Reason {$reason->code} is not applicable for part cancel. Use codes: 002, 012"
                );
            }

            $items = $params['items'] ?? [];
            if (empty($items)) {
                throw new \InvalidArgumentException('At least one item is required for partial cancel');
            }

            // Validate each item
            foreach ($items as $i => $it) {
                if (empty($it['item_id']) || ! isset($it['cancelled_quantity'])) {
                    throw new \InvalidArgumentException("Item #$i missing item_id or cancelled_quantity");
                }
                if ((int) $it['cancelled_quantity'] < 1) {
                    throw new \InvalidArgumentException("Item {$it['item_id']}: cancelled_quantity must be >= 1");
                }
                if ((int) $it['cancelled_quantity'] > (int) ($it['original_quantity'] ?? 0)) {
                    throw new \InvalidArgumentException(
                        "Item {$it['item_id']}: cannot cancel more than ordered quantity"
                    );
                }
            }

            $cancellation = OndcOrderCancellation::create([
                'ondc_order_id'              => $params['ondc_order_id'],
                'ondc_transaction_id'        => $params['transaction_id'],
                'order_id'                   => $params['order_id'] ?? null,
                'cancellation_reason_id'     => $reason->code,
                'cancelled_by'               => $params['cancelled_by'] ?? config('ondc.bpp_id'),
                'initiated_by'               => $params['initiated_by'] ?? 'SELLER',
                'cancel_type'                => 'PARTIAL',
                'status'                     => OndcOrderCancellation::STATUS_PROCESSING,
                'precancel_fulfillment_state' => $params['precancel_fulfillment_state'] ?? 'Pending',
                'precancel_state_updated_at' => now(),
                'seller_note'                => $params['seller_note'] ?? null,
                'buyer_note'                 => $params['buyer_note'] ?? null,
                'triggers_rto'               => false,
            ]);

            // Create per-item records
            foreach ($items as $it) {
                $price  = (float) ($it['item_price'] ?? 0);
                $qty    = (int) $it['cancelled_quantity'];
                $refund = $price * $qty;

                OndcCancelledItem::create([
                    'cancellation_id'    => $cancellation->id,
                    'item_id'            => $it['item_id'],
                    'fulfillment_id'     => $it['fulfillment_id'] ?? null,
                    'original_quantity'  => (int) ($it['original_quantity'] ?? $qty),
                    'cancelled_quantity' => $qty,
                    'remaining_quantity' => max(0, (int) ($it['original_quantity'] ?? $qty) - $qty),
                    'item_price'         => $price,
                    'refund_amount'      => $refund,
                    'disposition'        => OndcCancelledItem::DISPOSITION_PENDING,
                ]);
            }

            Log::info('[ONDC] Partial cancellation initiated', [
                'cancellation_id' => $cancellation->id,
                'items'           => count($items),
                'reason'          => $reason->code,
            ]);

            return $cancellation->fresh(['cancelledItems', 'reason']);
        });
    }

    /**
     * Build the ONDC /update or /on_update payload for partial cancel.
     */
    public function buildOnUpdatePartialCancelPayload(OndcOrderCancellation $cancellation, array $orderData): array
    {
        $context = [
            'domain'         => $orderData['domain'] ?? 'ONDC:RET10',
            'country'        => 'IND',
            'city'           => $orderData['city'] ?? 'std:080',
            'action'         => 'on_update',
            'core_version'   => '1.2.0',
            'bap_id'         => $orderData['bap_id'] ?? '',
            'bap_uri'        => $orderData['bap_uri'] ?? '',
            'bpp_id'         => config('ondc.bpp_id'),
            'bpp_uri'        => config('ondc.bpp_uri'),
            'transaction_id' => $cancellation->ondc_transaction_id,
            'message_id'     => (string) Str::uuid(),
            'timestamp'      => gmdate('Y-m-d\TH:i:s.v\Z'),
            'ttl'            => 'PT30S',
        ];

        $cancelFulfillmentId = 'C1-' . Str::random(8);

        // Split items: reduced qty on forward fulfillment + new cancel fulfillment
        $items = [];
        $totalRefund = 0;
        foreach ($cancellation->cancelledItems as $ci) {
            if ($ci->remaining_quantity > 0) {
                $items[] = [
                    'id'             => $ci->item_id,
                    'quantity'       => ['count' => $ci->remaining_quantity],
                    'fulfillment_id' => $ci->fulfillment_id,
                ];
            }
            $items[] = [
                'id'             => $ci->item_id,
                'quantity'       => ['count' => $ci->cancelled_quantity],
                'fulfillment_id' => $cancelFulfillmentId,
            ];
            $totalRefund += (float) $ci->refund_amount;
        }

        $fulfillments = $orderData['fulfillments'] ?? [];

        // Append the Cancel-type fulfillment
        $fulfillments[] = [
            'id'    => $cancelFulfillmentId,
            'type'  => 'Cancel',
            'state' => ['descriptor' => ['code' => 'Cancelled']],
            'tags'  => [[
                'code' => 'cancel_request',
                'list' => [
                    ['code' => 'reason_id',    'value' => $cancellation->cancellation_reason_id],
                    ['code' => 'initiated_by', 'value' => $cancellation->cancelled_by],
                ],
            ], [
                'code' => 'quote_trail',
                'list' => [
                    ['code' => 'type',     'value' => 'item'],
                    ['code' => 'id',       'value' => $cancellation->cancelledItems->first()->item_id ?? ''],
                    ['code' => 'currency', 'value' => 'INR'],
                    ['code' => 'value',    'value' => '-' . number_format($totalRefund, 2, '.', '')],
                ],
            ]],
        ];

        return [
            'context' => $context,
            'message' => [
                'order' => [
                    'id'           => $cancellation->ondc_order_id,
                    'state'        => $orderData['state'] ?? 'Created',
                    'items'        => $items,
                    'provider'     => $orderData['provider'] ?? [],
                    'billing'      => $orderData['billing'] ?? [],
                    'fulfillments' => $fulfillments,
                    'quote'        => $this->recomputeQuote($cancellation, $orderData),
                ],
            ],
        ];
    }

    /**
     * Recompute the order quote after removing partially cancelled items.
     */
    protected function recomputeQuote(OndcOrderCancellation $cancellation, array $orderData): array
    {
        $originalQuote = $orderData['quote'] ?? ['price' => ['currency' => 'INR', 'value' => '0.00'], 'breakup' => []];
        $deduction     = 0;
        foreach ($cancellation->cancelledItems as $ci) {
            $deduction += (float) $ci->refund_amount;
        }

        $originalTotal = (float) ($originalQuote['price']['value'] ?? 0);
        $newTotal      = max(0, $originalTotal - $deduction);

        return [
            'price'   => [
                'currency' => 'INR',
                'value'    => number_format($newTotal, 2, '.', ''),
            ],
            'breakup' => $originalQuote['breakup'] ?? [],
            'ttl'     => 'P1D',
        ];
    }

    public function listCancelledItems(OndcOrderCancellation $cancellation)
    {
        return $cancellation->cancelledItems()->get();
    }
}
