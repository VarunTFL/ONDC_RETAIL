<?php

namespace Webkul\Ondc\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webkul\Ondc\Models\OndcCancellationReason;
use Webkul\Ondc\Models\OndcOrderCancellation;
use Webkul\Ondc\Models\OndcRtoEvent;
use Webkul\Sales\Repositories\OrderRepository;

/**
 * Seller-side cancellation + RTO (Return-To-Origin) state machine.
 *
 * Flow:
 *   1. Seller decides to cancel (e.g. item OOS, merchant rejection, buyer not found at delivery).
 *   2. If pre-pickup → simple Cancelled (no RTO).
 *   3. If post-pickup or in-transit → RTO is triggered:
 *        Cancelled (forward fulfillment) + RTO fulfillment created with state RTO-Initiated.
 *   4. Driver returns the package to seller location → RTO-Delivered.
 *   5. Item disposed (sold to another buyer / put back to stock / discarded) → RTO-Disposed.
 *
 * The BPP pushes /on_cancel (unsolicited) to the BAP for each transition,
 * and may also push /on_status with the new RTO state.
 */
class SellerCancellationService
{
    /** Reason codes that ALWAYS trigger RTO */
    public const RTO_TRIGGERING_REASONS = ['003', '006', '007', '009', '010', '011', '012', '013', '014', '015','017'];

    /** Fulfillment states where RTO is required if cancellation happens */
    public const RTO_REQUIRED_STATES = ['Order-picked-up', 'Out-for-delivery'];

    public function __construct(
        protected OrderRepository $orderRepository,
        protected CancellationService $cancellationService,
    ) {}

    // ──────────────────────────────────────────────────────────
    //  1. Initiate seller cancellation
    // ──────────────────────────────────────────────────────────
    public function initiateSellerCancel(array $params): OndcOrderCancellation
    {
        return DB::transaction(function () use ($params) {
            $reason = OndcCancellationReason::find($params['cancellation_reason_id']);
            if (! $reason) {
                throw new \InvalidArgumentException("Invalid reason code: {$params['cancellation_reason_id']}");
            }

            // Verify seller can use this code
            $allowed = ['SNP', 'LSP', 'LBNP', 'LSP/SNP', 'SNP/LSP'];
            $canUse  = collect($allowed)->contains(fn ($c) => stripos($reason->who_can_use, $c) !== false);
            if (! $canUse) {
                throw new \InvalidArgumentException("Reason {$reason->code} cannot be used by seller (allowed: {$reason->who_can_use})");
            }

            $preCancelState = $params['precancel_fulfillment_state'] ?? 'Pending';
            $triggersRto    = $this->shouldTriggerRto($reason->code, $preCancelState);

            $cancellation = OndcOrderCancellation::create([
                'ondc_order_id'              => $params['ondc_order_id'],
                'ondc_transaction_id'        => $params['transaction_id'],
                'order_id'                   => $params['order_id'] ?? null,
                'cancellation_reason_id'     => $reason->code,
                'cancelled_by'               => $params['cancelled_by'] ?? config('ondc.bpp_id'),
                'initiated_by'               => 'SELLER',
                'cancel_type'                => $params['cancel_type'] ?? 'FULL',
                'fulfillment_id'             => $params['fulfillment_id'] ?? null,
                'status'                     => OndcOrderCancellation::STATUS_INITIATED,
                'precancel_fulfillment_state' => $preCancelState,
                'precancel_state_updated_at' => $params['precancel_state_updated_at'] ?? now(),
                'seller_note'                => $params['seller_note'] ?? null,
                'triggers_rto'               => $triggersRto,
                'retry_count'                => $params['retry_count'] ?? ($triggersRto ? 1 : 0),
            ]);

            // If RTO triggered, immediately spin up an RTO fulfillment
            if ($triggersRto) {
                $this->initiateRto($cancellation, $params);
            } else {
                // No RTO — just mark cancelled
                $cancellation->update(['status' => OndcOrderCancellation::STATUS_CANCELLED]);

                if ($cancellation->order_id) {
                    try {
                        $this->orderRepository->cancel($cancellation->order_id);
                    } catch (\Exception $e) {
                        Log::warning('[ONDC] Could not cancel internal order', [
                            'order_id' => $cancellation->order_id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            }

            Log::info('[ONDC] Seller cancellation initiated', [
                'cancellation_id' => $cancellation->id,
                'reason'          => $reason->code,
                'rto'             => $triggersRto,
            ]);

            return $cancellation->fresh(['rtoEvents', 'reason']);
        });
    }

    // ──────────────────────────────────────────────────────────
    //  2. RTO state transitions
    // ──────────────────────────────────────────────────────────
    public function initiateRto(OndcOrderCancellation $cancellation, array $params = []): OndcOrderCancellation
    {
        $rtoFulfillmentId = $params['rto_fulfillment_id'] ?? (string) Str::uuid();

        $cancellation->update([
            'triggers_rto'       => true,
            'rto_status'         => OndcOrderCancellation::RTO_INITIATED,
            'rto_fulfillment_id' => $rtoFulfillmentId,
            'rto_initiated_at'   => now(),
            'status'             => OndcOrderCancellation::STATUS_PROCESSING,
        ]);

        $this->logRtoEvent($cancellation, OndcRtoEvent::TYPE_INITIATED, null, $params);

        Log::info('[ONDC] RTO initiated', [
            'cancellation_id'    => $cancellation->id,
            'rto_fulfillment_id' => $rtoFulfillmentId,
        ]);

        return $cancellation->fresh();
    }

    public function markRtoDelivered(OndcOrderCancellation $cancellation, array $params = []): OndcOrderCancellation
    {
        if ($cancellation->rto_status !== OndcOrderCancellation::RTO_INITIATED) {
            throw new \LogicException("Cannot mark RTO-Delivered: current state is {$cancellation->rto_status}");
        }

        $previous = $cancellation->rto_status;

        $cancellation->update([
            'rto_status'        => OndcOrderCancellation::RTO_DELIVERED,
            'rto_delivered_at'  => now(),
        ]);

        $this->logRtoEvent($cancellation, OndcRtoEvent::TYPE_DELIVERED, $previous, $params);

        Log::info('[ONDC] RTO delivered (back to seller)', ['cancellation_id' => $cancellation->id]);

        return $cancellation->fresh();
    }

    public function markRtoDisposed(OndcOrderCancellation $cancellation, array $params = []): OndcOrderCancellation
    {
        if (! in_array($cancellation->rto_status, [
            OndcOrderCancellation::RTO_INITIATED,
            OndcOrderCancellation::RTO_DELIVERED,
        ], true)) {
            throw new \LogicException("Cannot mark RTO-Disposed: current state is {$cancellation->rto_status}");
        }

        $previous = $cancellation->rto_status;

        $cancellation->update([
            'rto_status'      => OndcOrderCancellation::RTO_DISPOSED,
            'rto_disposed_at' => now(),
            'status'          => OndcOrderCancellation::STATUS_CANCELLED,
        ]);

        $this->logRtoEvent($cancellation, OndcRtoEvent::TYPE_DISPOSED, $previous, $params);
    //   temporary
        // Now safe to cancel the internal Bagisto order
        // if ($cancellation->order_id) {
        //     try {
        //         $this->orderRepository->cancel($cancellation->order_id);
        //     } catch (\Exception $e) {
        //         Log::warning('[ONDC] Could not cancel internal order', [
        //             'order_id' => $cancellation->order_id,
        //             'error'    => $e->getMessage(),
        //         ]);
        //     }
        // }
        if ($cancellation->order_id) {
    try {
        DB::table('orders')
            ->where('id', $cancellation->order_id)
            ->update(['status' => 'canceled', 'updated_at' => now()]);
    } catch (\Exception $e) {
        Log::warning('[ONDC] Could not cancel internal order', [
            'order_id' => $cancellation->order_id,
            'error'    => $e->getMessage(),
        ]);
    }
}


        Log::info('[ONDC] RTO disposed — cancellation closed', ['cancellation_id' => $cancellation->id]);

        return $cancellation->fresh();
    }

    // ──────────────────────────────────────────────────────────
    //  3. Build unsolicited /on_cancel payload (BPP → BAP push)
    // ──────────────────────────────────────────────────────────
    public function buildUnsolicitedOnCancel(OndcOrderCancellation $cancellation, array $orderData): array
    {
        $context = [
            'domain'         => $orderData['domain'] ?? 'ONDC:RET10',
            'country'        => $orderData['country'] ?? 'IND',
            'city'           => $orderData['city'] ?? 'std:080',
            'action'         => 'on_cancel',
            'core_version'   => $orderData['core_version'] ?? '1.2.0',
            'bap_id'         => $orderData['bap_id'] ?? '',
            'bap_uri'        => $orderData['bap_uri'] ?? '',
            'bpp_id'         => config('ondc.bpp_id'),
            'bpp_uri'        => config('ondc.bpp_uri'),
            'transaction_id' => $cancellation->ondc_transaction_id,
            'message_id'     => (string) Str::uuid(),
            'timestamp'      => gmdate('Y-m-d\TH:i:s.v\Z'),
            'ttl'            => 'PT30S',
        ];

        $forwardFulfillmentId = $cancellation->fulfillment_id ?: ($orderData['fulfillment_id'] ?? (string) Str::uuid());
        $rtoFulfillmentId     = $cancellation->rto_fulfillment_id ?: (string) Str::uuid();

        $forwardFulfillment = $this->findForwardFulfillment($orderData['fulfillments'] ?? [], $forwardFulfillmentId);
        $forwardFulfillment['id'] = $forwardFulfillment['id'] ?? $forwardFulfillmentId;
        $forwardFulfillment['type'] = $forwardFulfillment['type'] ?? 'Delivery';
        $forwardFulfillment['state'] = ['descriptor' => ['code' => 'Cancelled']];

        $existingTags = array_values(array_filter(
            $forwardFulfillment['tags'] ?? [],
            fn ($tag) => ! in_array($tag['code'] ?? '', ['cancel_request', 'precancel_state'], true)
        ));

        $forwardFulfillment['tags'] = array_merge($existingTags, [
            [
                'code' => 'cancel_request',
                'list' => array_values(array_filter([
                    ['code' => 'retry_count',  'value' => (string) $cancellation->retry_count],
                    $cancellation->triggers_rto
                        ? ['code' => 'rto_id', 'value' => $rtoFulfillmentId]
                        : null,
                    ['code' => 'reason_id',    'value' => $cancellation->cancellation_reason_id],
                    ['code' => 'initiated_by', 'value' => $cancellation->cancelled_by],
                ])),
            ],
            [
                'code' => 'precancel_state',
                'list' => [
                    ['code' => 'fulfillment_state', 'value' => $cancellation->precancel_fulfillment_state ?? 'Pending'],
                    ['code' => 'updated_at', 'value' => gmdate('Y-m-d\TH:i:s.v\Z', strtotime((string) ($cancellation->precancel_state_updated_at ?? now())))],
                ],
            ],
        ]);

        $fulfillments = [$forwardFulfillment];

        // RTO fulfillment with current state
        if ($cancellation->triggers_rto) {
            $fulfillments[] = [
                'id'    => $rtoFulfillmentId,
                'type'  => 'RTO',
                'state' => ['descriptor' => ['code' => $cancellation->rto_status ?? OndcOrderCancellation::RTO_INITIATED]],
                'tags'  => $this->buildQuoteTrailTags($cancellation),
            ];
        }

        // Items: zero out quantity on cancelled fulfillment, move qty to RTO if any
        $items = [];
        foreach ($orderData['items'] ?? [] as $item) {
            $items[] = [
                'id'             => $item['id'] ?? '',
                'quantity'       => ['count' => 0],
                'fulfillment_id' => $forwardFulfillmentId,
            ];
            if ($cancellation->triggers_rto) {
                $items[] = [
                    'id'             => $item['id'] ?? '',
                    'quantity'       => ['count' => $item['quantity']['count'] ?? 1],
                    'fulfillment_id' => $rtoFulfillmentId,
                ];
            }
        }

        return [
            'context' => $context,
            'message' => [
                'order' => [
                    'state'        => 'Cancelled',
                    'id'           => $cancellation->ondc_order_id,
                    'items'        => $items,
                    'provider'     => $orderData['provider'] ?? [],
                    'billing'      => $orderData['billing'] ?? [],
                    'fulfillments' => $fulfillments,
                    'quote'        => $orderData['quote'] ?? $this->buildFallbackQuote($orderData),
                    'payment'      => $orderData['payment'] ?? $this->buildFallbackPayment($orderData, $cancellation),
                    'cancellation' => [
                        'cancelled_by' => $cancellation->cancelled_by,
                        'reason'       => [
                            'id' => $cancellation->cancellation_reason_id,
                        ],
                    ],
                    'created_at'   => gmdate('Y-m-d\TH:i:s.v\Z'),
                    'updated_at'   => gmdate('Y-m-d\TH:i:s.v\Z'),

                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────
    public function shouldTriggerRto(string $reasonCode, string $fulfillmentState): bool
    {
        // RTO is only meaningful once the order has left the seller
        if (! in_array($fulfillmentState, self::RTO_REQUIRED_STATES, true)) {
            return false;
        }
        return in_array($reasonCode, self::RTO_TRIGGERING_REASONS, true);
    }

    protected function logRtoEvent(
        OndcOrderCancellation $cancellation,
        string $eventType,
        ?string $previousState,
        array $params
    ): OndcRtoEvent {
        return OndcRtoEvent::create([
            'cancellation_id'    => $cancellation->id,
            'rto_fulfillment_id' => $cancellation->rto_fulfillment_id,
            'event_type'         => $eventType,
            'previous_state'     => $previousState,
            'new_state'          => $eventType,
            'triggered_by'       => $params['triggered_by'] ?? $cancellation->cancelled_by,
            'payload'            => $params['payload'] ?? null,
            'notes'              => $params['notes'] ?? null,
        ]);
    }

    protected function buildQuoteTrailTags(OndcOrderCancellation $cancellation): array
    {
        $tags = [];
        foreach ($cancellation->quoteTrails as $trail) {
            $tags[] = [
                'code' => 'quote_trail',
                'list' => [
                    ['code' => 'type',     'value' => $trail->type],
                    ['code' => 'id',       'value' => $trail->ref_id ?? ''],
                    ['code' => 'currency', 'value' => $trail->currency],
                    ['code' => 'value',    'value' => number_format($trail->value, 2, '.', '')],
                ],
            ];
        }
        return $tags;
    }

    protected function findForwardFulfillment(array $fulfillments, string $fulfillmentId): array
    {
        foreach ($fulfillments as $fulfillment) {
            if (($fulfillment['id'] ?? '') === $fulfillmentId) {
                return $fulfillment;
            }
        }

        return $fulfillments[0] ?? [];
    }

    protected function buildFallbackQuote(array $orderData): array
    {
        $currency = $orderData['currency'] ?? 'INR';
        $value = $orderData['order_total'] ?? '0.00';

        return [
            'price' => [
                'currency' => $currency,
                'value'    => number_format((float) $value, 2, '.', ''),
            ],
            'breakup' => [],
        ];
    }

    protected function buildFallbackPayment(array $orderData, OndcOrderCancellation $cancellation): array
    {
        $quote = $orderData['quote'] ?? $this->buildFallbackQuote($orderData);

        return [
            'params' => [
                'amount'         => $quote['price']['value'] ?? '0.00',
                'currency'       => $quote['price']['currency'] ?? 'INR',
                'transaction_id' => $cancellation->ondc_transaction_id,
            ],
            'status'       => 'PAID',
            'type'         => 'ON-ORDER',
            'collected_by' => 'BAP',
        ];
    }

    /**
     * List orders eligible for seller cancellation (any non-completed/non-cancelled order).
     */
    public function getRtoEligibleOrders(): array
    {
        return OndcOrderCancellation::where('initiated_by', 'SELLER')
            ->where('triggers_rto', true)
            ->whereNotIn('rto_status', [OndcOrderCancellation::RTO_DISPOSED])
            ->orderBy('rto_initiated_at', 'desc')
            ->get()
            ->toArray();
    }
}
