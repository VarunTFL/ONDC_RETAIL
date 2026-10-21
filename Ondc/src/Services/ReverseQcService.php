<?php

namespace Webkul\Ondc\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webkul\Ondc\Models\OndcReturnRequest;
use Webkul\Ondc\Models\OndcReverseQcEvent;
use Webkul\Ondc\Models\OndcReverseQcResult;

/**
 * ONDC Reverse QC flow:
 *
 *   Buyer initiates return → seller approves → reverse logistics picks item up
 *   → item arrives at seller → reverse QC performed → PASS or FAIL
 *   → if PASS: refund to buyer; if FAIL: item returned to buyer.
 *
 * State machine:
 *
 *   REQUESTED → APPROVED → PICKUP_INITIATED → QC_IN_PROGRESS → QC_PASSED → REFUND_PROCESSED → COMPLETED
 *                       ↘ REJECTED                          ↘ QC_FAILED → CLOSED
 */
class ReverseQcService
{
    public const RETURN_REASON_CODES = [
        '001' => 'Buyer does not want product anymore',
        '002' => 'Product available at lower than order price',
        '003' => 'Product quality issue',
        '004' => 'Delivery delayed',
        '005' => 'Product damaged or defective',
        '006' => 'Product not as per description',
        '007' => 'Wrong product delivered',
        '008' => 'Item not delivered (full)',
    ];

    /**
     * Step 1 — Buyer initiates a return request
     */
    public function initiateReturn(array $params): OndcReturnRequest
    {
        return DB::transaction(function () use ($params) {
            $reasonId = $params['reason_id'];
            if (! array_key_exists($reasonId, self::RETURN_REASON_CODES)) {
                throw new \InvalidArgumentException("Invalid return reason: $reasonId");
            }

            $returnRequest = OndcReturnRequest::create([
                'ondc_order_id'       => $params['ondc_order_id'],
                'ondc_transaction_id' => $params['transaction_id'],
                'order_id'            => $params['order_id'] ?? null,
                'return_request_id'   => $params['return_request_id'] ?? (string) Str::uuid(),
                'item_id'             => $params['item_id'],
                'parent_item_id'      => $params['parent_item_id'] ?? null,
                'item_quantity'       => (int) ($params['item_quantity'] ?? 1),
                'reason_id'           => $reasonId,
                'reason_desc'         => $params['reason_desc'] ?? null,
                'images'              => $params['images'] ?? [],
                'ttl_approval'        => $params['ttl_approval'] ?? 'PT24H',
                'ttl_reverseqc'       => $params['ttl_reverseqc'] ?? 'P3D',
                'status'              => OndcReturnRequest::STATUS_REQUESTED,
                'refund_amount'       => $params['refund_amount'] ?? null,
                'request_payload'     => $params['payload'] ?? null,
            ]);

            $this->logEvent($returnRequest, OndcReverseQcEvent::TYPE_REQUESTED, null, OndcReturnRequest::STATUS_REQUESTED, $params);

            Log::info('[ONDC] Return requested', [
                'return_request_id' => $returnRequest->return_request_id,
                'item_id'           => $returnRequest->item_id,
                'reason'            => $reasonId,
            ]);

            return $returnRequest->fresh();
        });
    }

    /**
     * Step 2 — Seller approves the return → triggers reverse logistics
     */
    public function approveReturn(OndcReturnRequest $rr, array $params = []): OndcReturnRequest
    {
        if (! $rr->canApprove()) {
            throw new \LogicException("Cannot approve in status {$rr->status}");
        }

        return DB::transaction(function () use ($rr, $params) {
            $previous = $rr->status;

            $rr->update([
                'status'      => OndcReturnRequest::STATUS_APPROVED,
                'approved_by' => $params['approved_by'] ?? config('ondc.bpp_id'),
                'approved_at' => now(),
            ]);

            $this->logEvent($rr, OndcReverseQcEvent::TYPE_APPROVED, $previous, OndcReturnRequest::STATUS_APPROVED, $params);

            // Auto-create QC record
            OndcReverseQcResult::create([
                'return_request_id' => $rr->id,
                'qc_status'         => OndcReverseQcResult::QC_INITIATED,
                'qc_initiated_at'   => now(),
            ]);

            Log::info('[ONDC] Return approved', ['return_request_id' => $rr->return_request_id]);

            return $rr->fresh(['qcResult']);
        });
    }

    /**
     * Seller rejects the return
     */
    public function rejectReturn(OndcReturnRequest $rr, array $params): OndcReturnRequest
    {
        if (! $rr->canApprove()) {
            throw new \LogicException("Cannot reject in status {$rr->status}");
        }

        return DB::transaction(function () use ($rr, $params) {
            $previous = $rr->status;

            $rr->update([
                'status'           => OndcReturnRequest::STATUS_REJECTED,
                'rejected_at'      => now(),
                'rejection_reason' => $params['reason'] ?? 'No reason given',
            ]);

            $this->logEvent($rr, OndcReverseQcEvent::TYPE_REJECTED, $previous, OndcReturnRequest::STATUS_REJECTED, $params);

            return $rr->fresh();
        });
    }

    /**
     * Step 3 — Mark pickup done (reverse logistics has picked up the item from buyer)
     */
    public function markPickedUp(OndcReturnRequest $rr, array $params): OndcReturnRequest
    {
        if ($rr->status !== OndcReturnRequest::STATUS_APPROVED) {
            throw new \LogicException("Cannot mark pickup in status {$rr->status}");
        }

        return DB::transaction(function () use ($rr, $params) {
            $previous = $rr->status;

            $rr->update(['status' => OndcReturnRequest::STATUS_PICKUP_INITIATED]);

            // update QC result with pickup proof
            $qc = $rr->qcResult ?? OndcReverseQcResult::create([
                'return_request_id' => $rr->id,
                'qc_status'         => OndcReverseQcResult::QC_INITIATED,
            ]);
            $qc->update([
                'qc_status'         => OndcReverseQcResult::QC_PICKUP_DONE,
                'pickup_proof_code' => $params['pickup_proof_code'] ?? null,
            ]);

            $this->logEvent($rr, OndcReverseQcEvent::TYPE_PICKED_UP, $previous, OndcReturnRequest::STATUS_PICKUP_INITIATED, $params);

            return $rr->fresh(['qcResult']);
        });
    }

    /**
     * Step 4 — QC inspection started at seller location
     */
    public function startQc(OndcReturnRequest $rr, array $params): OndcReturnRequest
    {
        if (! in_array($rr->status, [
            OndcReturnRequest::STATUS_APPROVED,
            OndcReturnRequest::STATUS_PICKUP_INITIATED,
        ], true)) {
            throw new \LogicException("Cannot start QC in status {$rr->status}");
        }

        return DB::transaction(function () use ($rr, $params) {
            $previous = $rr->status;

            $rr->update(['status' => OndcReturnRequest::STATUS_QC_IN_PROGRESS]);

            $qc = $rr->qcResult ?? OndcReverseQcResult::create(['return_request_id' => $rr->id]);
            $qc->update([
                'qc_status'      => OndcReverseQcResult::QC_IN_PROGRESS,
                'inspector'      => $params['inspector'] ?? null,
                'qc_initiated_at' => $qc->qc_initiated_at ?? now(),
            ]);

            $this->logEvent($rr, OndcReverseQcEvent::TYPE_QC_STARTED, $previous, OndcReturnRequest::STATUS_QC_IN_PROGRESS, $params);

            return $rr->fresh(['qcResult']);
        });
    }

    /**
     * Step 5 — QC PASS: item is acceptable, refund buyer, item flows to liquidation
     */
    public function recordQcPass(OndcReturnRequest $rr, array $params): OndcReturnRequest
    {
        return DB::transaction(function () use ($rr, $params) {
            $previous = $rr->status;
            $approvedRefund = (float) ($params['approved_refund'] ?? $rr->refund_amount ?? 0);

            $rr->update([
                'status'        => OndcReturnRequest::STATUS_QC_PASSED,
                'refund_amount' => $approvedRefund,
            ]);

            $qc = $rr->qcResult ?? OndcReverseQcResult::create(['return_request_id' => $rr->id]);
            $qc->update([
                'qc_status'           => OndcReverseQcResult::QC_PASSED,
                'qc_completed_at'     => now(),
                'qc_notes'            => $params['notes'] ?? null,
                'approved_refund'     => $approvedRefund,
                'evidence_urls'       => $params['evidence_urls'] ?? [],
                'checklist'           => $params['checklist'] ?? null,
                'delivery_proof_code' => $params['delivery_proof_code'] ?? null,
            ]);

            $this->logEvent($rr, OndcReverseQcEvent::TYPE_QC_PASSED, $previous, OndcReturnRequest::STATUS_QC_PASSED, $params);

            Log::info('[ONDC] Reverse QC PASSED', [
                'return_request_id' => $rr->return_request_id,
                'refund'            => $approvedRefund,
            ]);

            return $rr->fresh(['qcResult']);
        });
    }

    /**
     * Step 5 (alt) — QC FAIL: item rejected, no refund, item returned to buyer
     */
    public function recordQcFail(OndcReturnRequest $rr, array $params): OndcReturnRequest
    {
        return DB::transaction(function () use ($rr, $params) {
            $previous = $rr->status;

            $rr->update([
                'status'           => OndcReturnRequest::STATUS_QC_FAILED,
                'rejection_reason' => $params['failure_reason'] ?? 'QC failed',
            ]);

            $qc = $rr->qcResult ?? OndcReverseQcResult::create(['return_request_id' => $rr->id]);
            $qc->update([
                'qc_status'       => OndcReverseQcResult::QC_FAILED,
                'qc_completed_at' => now(),
                'failure_reason'  => $params['failure_reason'] ?? null,
                'qc_notes'        => $params['notes'] ?? null,
                'evidence_urls'   => $params['evidence_urls'] ?? [],
                'checklist'       => $params['checklist'] ?? null,
            ]);

            $this->logEvent($rr, OndcReverseQcEvent::TYPE_QC_FAILED, $previous, OndcReturnRequest::STATUS_QC_FAILED, $params);

            Log::info('[ONDC] Reverse QC FAILED', [
                'return_request_id' => $rr->return_request_id,
                'reason'            => $params['failure_reason'] ?? null,
            ]);

            return $rr->fresh(['qcResult']);
        });
    }

    /**
     * Step 6 — refund processed → mark COMPLETED
     */
    public function markRefundProcessed(OndcReturnRequest $rr, array $params): OndcReturnRequest
    {
        if ($rr->status !== OndcReturnRequest::STATUS_QC_PASSED) {
            throw new \LogicException("Cannot process refund in status {$rr->status}");
        }

        return DB::transaction(function () use ($rr, $params) {
            $previous = $rr->status;

            $rr->update(['status' => OndcReturnRequest::STATUS_REFUND_PROCESSED]);
            $this->logEvent($rr, OndcReverseQcEvent::TYPE_REFUNDED, $previous, OndcReturnRequest::STATUS_REFUND_PROCESSED, $params);

            // Then close out
            $rr->update(['status' => OndcReturnRequest::STATUS_COMPLETED]);
            $this->logEvent($rr, OndcReverseQcEvent::TYPE_CLOSED, OndcReturnRequest::STATUS_REFUND_PROCESSED, OndcReturnRequest::STATUS_COMPLETED, $params);

            return $rr->fresh();
        });
    }

    // Add to ReverseQcService — maps internal status to the ONDC fulfillment.state.descriptor.code
    // used in on_update, per the Return_reverseQc / Liquidation sequence diagrams.
    public function ondcStateCodeFor(OndcReturnRequest $rr): string
    {
        return match ($rr->status) {
            OndcReturnRequest::STATUS_REQUESTED         => 'Return_Initiated',
            OndcReturnRequest::STATUS_APPROVED          => 'Return_Approved',
            OndcReturnRequest::STATUS_PICKUP_INITIATED  => 'Return_Picked',
            OndcReturnRequest::STATUS_QC_IN_PROGRESS    => 'Return_Picked',
            OndcReturnRequest::STATUS_QC_PASSED,
            OndcReturnRequest::STATUS_REFUND_PROCESSED,
            OndcReturnRequest::STATUS_COMPLETED         => 'Return_Delivered',
            OndcReturnRequest::STATUS_QC_FAILED,
            OndcReturnRequest::STATUS_REJECTED          => 'Cancelled',
            default                                      => 'Return_Initiated',
        };
    }

    /**
     * Build the on_update payload for ANY stage of the return, using the
     * stage-appropriate fulfillment state code. This builds the FULL order
     * object (original fulfillments, billing, quote, payment with settlement
     * legs) as required by the spec — not just the bare Return fulfillment.
     *
     * $orderCtx    — context fields (domain, bap_id, bap_uri, etc.)
     * $originalOrder — the stored order snapshot from ondc_confirm_payloads
     *                  (decoded message_order: items, billing, quote,
     *                  provider, fulfillments, payment)
     */
    public function buildOnUpdateForStage(OndcReturnRequest $rr, array $orderCtx, array $originalOrder = []): array
{
    $stateCode = $this->ondcStateCodeFor($rr);
    $isTerminal = in_array($rr->status, [
        OndcReturnRequest::STATUS_QC_PASSED,
        OndcReturnRequest::STATUS_REFUND_PROCESSED,
        OndcReturnRequest::STATUS_COMPLETED,
    ], true);

    // Pull buyer's delivery address (pickup point for the return) and the
    // seller/provider's address (drop-off point) from the original order,
    // so the Return fulfillment's start/end blocks aren't empty.
    $deliveryFulfillment = collect($originalOrder['fulfillments'] ?? [])
        ->firstWhere('type', 'Delivery');

    $buyerLocation  = $deliveryFulfillment['end']['location'] ?? [];
    $buyerContact   = $deliveryFulfillment['end']['contact'] ?? [];
    $sellerLocation = $deliveryFulfillment['start']['location'] ?? [];
    $sellerContact  = $deliveryFulfillment['start']['contact'] ?? [];

    $returnFulfillment = [
        'id'                        => $rr->fulfillment_id ?? 'RET-' . $rr->return_request_id,
        'type'                      => 'Return',                       // required — was missing
        '@ondc/org/provider_name'   => $rr->logistics_provider_name ?? 'Reverse Logistics',
        'state'                     => ['descriptor' => ['code' => $stateCode]],
        'start' => [
            'location' => $buyerLocation,
            'contact'  => $buyerContact,
            'time'     => ['timestamp' => optional($rr->approved_at)->toIso8601ZuluString() ?? gmdate('Y-m-d\TH:i:s.v\Z')],
        ],
        'end' => [
            'location' => $sellerLocation,
            'contact'  => $sellerContact,
            'time'     => ['timestamp' => gmdate('Y-m-d\TH:i:s.v\Z')],
        ],
        'tags' => array_values(array_filter([
            [
                'code' => 'return_request',
                'list' => array_values(array_filter([
                    ['code' => 'item_id',        'value' => $rr->item_id],
                    ['code' => 'parent_item_id', 'value' => $rr->parent_item_id ?? ''],
                    ['code' => 'item_quantity',  'value' => (string) $rr->item_quantity],
                    ['code' => 'reason_id',      'value' => $rr->reason_id],
                    ['code' => 'reason_desc',    'value' => $rr->reason_desc ?? ''],
                    empty($rr->images) ? null : ['code' => 'images', 'value' => implode(',', (array) $rr->images)],
                    ['code' => 'ttl_approval',   'value' => $rr->ttl_approval],
                    ['code' => 'ttl_reverseqc',  'value' => $rr->ttl_reverseqc],
                    ['code' => 'initiated_by',   'value' => $orderCtx['bap_id'] ?? ''],
                ])),
            ],
            $rr->refund_amount !== null ? [
                'code' => 'quote_trail',
                'list' => [
                    ['code' => 'type',     'value' => 'item'],
                    ['code' => 'id',       'value' => $rr->item_id],
                    ['code' => 'currency', 'value' => $originalOrder['quote']['price']['currency'] ?? 'INR'],
                    ['code' => 'value',    'value' => '-' . number_format((float) $rr->refund_amount, 2, '.', '')],
                ],
            ] : null,
        ])),
    ];

        // Keep original (non-Return) fulfillments from the order as-is —
        // the spec example shows the Delivery fulfillment still present
        // alongside the Return fulfillment in every on_update for this order.
        $otherFulfillments = collect($originalOrder['fulfillments'] ?? [])
            ->reject(fn ($f) => ($f['type'] ?? '') === 'Return')
            ->values()
            ->all();

        $payment = $originalOrder['payment'] ?? [];
        if ($isTerminal && $rr->refund_amount !== null) {
            // Append/replace the buyer-refund settlement leg once the return
            // is actually completed — matches the spec example's second
            // settlement_details entry (settlement_counterparty: buyer).
            $settlements = collect($payment['@ondc/org/settlement_details'] ?? [])
                ->reject(fn ($s) => ($s['settlement_counterparty'] ?? '') === 'buyer')
                ->values()
                ->push([
                    'settlement_counterparty' => 'buyer',
                    'settlement_phase'        => 'refund',
                    'settlement_type'         => 'upi',
                    'settlement_amount'       => number_format((float) $rr->refund_amount, 2, '.', ''),
                    'settlement_timestamp'    => gmdate('Y-m-d\TH:i:s.v\Z'),
                ])
                ->all();

            $payment['@ondc/org/settlement_details'] = $settlements;
        }

        return [
            'context' => array_merge($orderCtx, [
                'action'     => 'on_update',
                'message_id' => (string) \Illuminate\Support\Str::uuid(),
                'timestamp'  => gmdate('Y-m-d\TH:i:s.v\Z'),
                'ttl'        => 'PT30S',
            ]),
            'message' => [
                'order' => [
                    'id'           => $rr->ondc_order_id,
                    'state'        => $isTerminal ? 'Completed' : ($originalOrder['state'] ?? 'In-progress'),
                    'provider'     => $originalOrder['provider'] ?? [],
                    'items'        => $originalOrder['items'] ?? [],
                    'billing'      => $originalOrder['billing'] ?? [],
                    'quote'        => $originalOrder['quote'] ?? [],
                    'fulfillments' => array_merge($otherFulfillments, [$returnFulfillment]),
                    'payment'      => $payment,
                    'created_at'   => $originalOrder['created_at'] ?? gmdate('Y-m-d\TH:i:s.v\Z'),
                    'updated_at'   => gmdate('Y-m-d\TH:i:s.v\Z'),
                ],
            ],
        ];
    }

    /**
     * Build /update payload for return request (BAP → BPP)
     */
    public function buildReturnRequestUpdatePayload(OndcReturnRequest $rr): array
    {
        return [
            'context' => [
                'domain'         => 'ONDC:RET10',
                'country'        => 'IND',
                'city'           => 'std:080',
                'action'         => 'update',
                'core_version'   => '1.2.0',
                'bap_id'         => config('ondc.bap_id'),
                'bap_uri'        => config('ondc.bap_uri'),
                'bpp_id'         => config('ondc.bpp_id'),
                'bpp_uri'        => config('ondc.bpp_uri'),
                'transaction_id' => $rr->ondc_transaction_id,
                'message_id'     => (string) Str::uuid(),
                'timestamp'      => gmdate('Y-m-d\TH:i:s.v\Z'), 
                'ttl'            => 'PT30S',
            ],
            'message' => [
                'update_target' => 'item',
                'order' => [
                    'id'           => $rr->ondc_order_id,
                    'fulfillments' => [[
                        'id'   => $rr->fulfillment_id ?? ('RET-' . $rr->return_request_id),
                        'type' => 'Return',
                        'tags' => [[
                            'code' => 'return_request',
                            'list' => array_values(array_filter([
                                ['code' => 'id',             'value' => $rr->return_request_id],
                                ['code' => 'item_id',        'value' => $rr->item_id],
                                ['code' => 'parent_item_id', 'value' => $rr->parent_item_id ?? ''],
                                ['code' => 'item_quantity',  'value' => (string) $rr->item_quantity],
                                ['code' => 'reason_id',      'value' => $rr->reason_id],
                                ['code' => 'reason_desc',    'value' => $rr->reason_desc ?? ''],
                                empty($rr->images) ? null : ['code' => 'images', 'value' => implode(',', (array) $rr->images)],
                                ['code' => 'ttl_approval',   'value' => $rr->ttl_approval],
                                ['code' => 'ttl_reverseqc',  'value' => $rr->ttl_reverseqc],
                            ])),
                        ]],
                    ]],
                ],
            ],
        ];
    }

    protected function logEvent(
        OndcReturnRequest $rr,
        string $type,
        ?string $prev,
        string $next,
        array $params
    ): OndcReverseQcEvent {
        return OndcReverseQcEvent::create([
            'return_request_id' => $rr->id,
            'event_type'        => $type,
            'previous_status'   => $prev,
            'new_status'        => $next,
            'triggered_by'      => $params['triggered_by'] ?? null,
            'payload'           => $params['payload'] ?? null,
            'notes'             => $params['notes'] ?? null,
        ]);
    }
}
