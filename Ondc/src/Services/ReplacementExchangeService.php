<?php

namespace Webkul\Ondc\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webkul\Ondc\Models\OndcReplacementEvent;
use Webkul\Ondc\Models\OndcReplacementFulfillment;
use Webkul\Ondc\Models\OndcReturnRequest;

/**
 * ONDC Retail 1.2.5 Replacement & Exchange flows.
 *
 * Both flows extend the standard return + reverse QC flow:
 *   1. Buyer initiates return with replace=yes OR exchange=yes tag
 *   2. Seller approves → reverse logistics → QC
 *   3. On QC pass:
 *        - Replacement: send same item back to buyer
 *        - Exchange: send a different item (with specs); collect/refund price difference
 *   4. Forward fulfillment progresses: Pending → Packed → Picked → OFD → Delivered
 *
 * Reference: examples/B2C/replacement-flow/13_update_reverse_qc.yaml
 *            examples/B2C/exchange-flow/14_update_reverse_qc.yaml
 */
class ReplacementExchangeService
{
    public function __construct(
        protected ReverseQcService $reverseQcService,
    ) {}

    // ──────────────────────────────────────────
    //  1. Buyer initiates replacement
    // ──────────────────────────────────────────
    public function initiateReplacement(array $params): OndcReturnRequest
    {
        $params['flow_type'] = OndcReturnRequest::FLOW_REPLACEMENT;
        $params['replacement_item_id'] = $params['replacement_item_id'] ?? $params['item_id'];

        // Use the existing reverse QC service to create the return request,
        // then enrich with replacement fields
        $rr = $this->reverseQcService->initiateReturn($params);

        $rr->update([
            'flow_type'           => OndcReturnRequest::FLOW_REPLACEMENT,
            'replacement_item_id' => $params['replacement_item_id'],
            'settlement_status'   => OndcReturnRequest::SETTLE_NOT_REQUIRED,
        ]);

        Log::info('[ONDC] Replacement initiated', [
            'return_request_id' => $rr->return_request_id,
            'item_id'           => $rr->item_id,
        ]);

        return $rr->fresh();
    }

    // ──────────────────────────────────────────
    //  2. Buyer initiates exchange
    // ──────────────────────────────────────────
    public function initiateExchange(array $params): OndcReturnRequest
    {
        $params['flow_type'] = OndcReturnRequest::FLOW_EXCHANGE;

        if (empty($params['exchange_item_id'])) {
            throw new \InvalidArgumentException('exchange_item_id required for exchange flow');
        }

        $rr = $this->reverseQcService->initiateReturn($params);

        // Compute settlement status from price difference
        $diff = (float) ($params['exchange_price_difference'] ?? 0);
        $settlement = match (true) {
            $diff > 0  => OndcReturnRequest::SETTLE_PENDING,        // buyer owes more
            $diff < 0  => OndcReturnRequest::SETTLE_PENDING,        // seller refunds difference
            default    => OndcReturnRequest::SETTLE_NOT_REQUIRED,
        };

        $rr->update([
            'flow_type'                 => OndcReturnRequest::FLOW_EXCHANGE,
            'exchange_item_id'          => $params['exchange_item_id'],
            'exchange_item_specs'       => $params['exchange_item_specs'] ?? [],
            'condition_id'              => $params['condition_id'] ?? null,
            'condition_desc'            => $params['condition_desc'] ?? null,
            'exchange_price_difference' => $diff,
            'settlement_status'         => $settlement,
        ]);

        Log::info('[ONDC] Exchange initiated', [
            'return_request_id' => $rr->return_request_id,
            'from_item'         => $rr->item_id,
            'to_item'           => $rr->exchange_item_id,
            'price_diff'        => $diff,
        ]);

        return $rr->fresh();
    }

    // ──────────────────────────────────────────
    //  3. After QC pass: create the replacement/exchange forward fulfillment
    // ──────────────────────────────────────────
    public function createForwardFulfillment(OndcReturnRequest $rr, array $params = []): OndcReplacementFulfillment
    {
        if (! in_array($rr->flow_type, [OndcReturnRequest::FLOW_REPLACEMENT, OndcReturnRequest::FLOW_EXCHANGE], true)) {
            throw new \LogicException("Cannot create forward fulfillment for {$rr->flow_type} flow");
        }
        if ($rr->status !== OndcReturnRequest::STATUS_QC_PASSED) {
            throw new \LogicException("Cannot start replacement/exchange in status {$rr->status} — QC must pass first");
        }
        if ($rr->replacementFulfillment) {
            throw new \LogicException('Replacement/exchange fulfillment already exists');
        }

        return DB::transaction(function () use ($rr, $params) {
            $itemId = $rr->isExchange() ? $rr->exchange_item_id : $rr->replacement_item_id;

            $rf = OndcReplacementFulfillment::create([
                'return_request_id'          => $rr->id,
                'replacement_fulfillment_id' => 'RF-' . Str::random(10),
                'flow_type'                  => $rr->flow_type,
                'item_id'                    => $itemId,
                'quantity'                   => $rr->item_quantity,
                'state'                      => OndcReplacementFulfillment::STATE_PENDING,
                'shipping_address'           => $params['shipping_address'] ?? null,
                'exchange_specs'             => $rr->isExchange() ? $rr->exchange_item_specs : null,
            ]);

            $this->logEvent($rf, OndcReplacementEvent::TYPE_STARTED, null, OndcReplacementFulfillment::STATE_PENDING, $params);

            Log::info('[ONDC] Forward fulfillment created', [
                'rf_id'       => $rf->replacement_fulfillment_id,
                'flow_type'   => $rr->flow_type,
                'item_id'     => $itemId,
            ]);

            return $rf->fresh();
        });
    }

    // ──────────────────────────────────────────
    //  4. Advance forward fulfillment state machine
    // ──────────────────────────────────────────
    public function advanceFulfillment(OndcReplacementFulfillment $rf, array $params = []): OndcReplacementFulfillment
    {
        $next = $rf->nextState();
        if (! $next) {
            throw new \LogicException("Fulfillment already in terminal state {$rf->state}");
        }

        return DB::transaction(function () use ($rf, $next, $params) {
            $previous = $rf->state;
            $update   = ['state' => $next];

            // Record timestamp for the new state
            $update += match ($next) {
                OndcReplacementFulfillment::STATE_PACKED        => ['packed_at' => now()],
                OndcReplacementFulfillment::STATE_PICKED        => ['picked_at' => now()],
                OndcReplacementFulfillment::STATE_OUT_FOR_DELIV => ['out_for_delivery_at' => now()],
                OndcReplacementFulfillment::STATE_DELIVERED     => ['delivered_at' => now()],
                default                                          => [],
            };

            // Optional agent details on Packed/OFD
            if (! empty($params['agent_name']))    $update['agent_name']    = $params['agent_name'];
            if (! empty($params['agent_phone']))   $update['agent_phone']   = $params['agent_phone'];
            if (! empty($params['tracking_url']))  $update['tracking_url']  = $params['tracking_url'];

            $rf->update($update);

            $type = match ($next) {
                OndcReplacementFulfillment::STATE_PACKED        => OndcReplacementEvent::TYPE_PACKED,
                OndcReplacementFulfillment::STATE_PICKED        => OndcReplacementEvent::TYPE_PICKED,
                OndcReplacementFulfillment::STATE_OUT_FOR_DELIV => OndcReplacementEvent::TYPE_OUT_FOR_DELIVERY,
                OndcReplacementFulfillment::STATE_DELIVERED     => OndcReplacementEvent::TYPE_DELIVERED,
                default                                          => OndcReplacementEvent::TYPE_PACKED,
            };

            $this->logEvent($rf, $type, $previous, $next, $params);

            // If just delivered → mark return request as COMPLETED
            if ($next === OndcReplacementFulfillment::STATE_DELIVERED) {
                $rf->returnRequest->update(['status' => OndcReturnRequest::STATUS_COMPLETED]);
            }

            return $rf->fresh();
        });
    }

    public function cancelFulfillment(OndcReplacementFulfillment $rf, array $params = []): OndcReplacementFulfillment
    {
        if ($rf->isComplete()) {
            throw new \LogicException("Cannot cancel — already in {$rf->state}");
        }

        return DB::transaction(function () use ($rf, $params) {
            $previous = $rf->state;
            $rf->update(['state' => OndcReplacementFulfillment::STATE_CANCELLED]);
            $this->logEvent($rf, OndcReplacementEvent::TYPE_CANCELLED, $previous, OndcReplacementFulfillment::STATE_CANCELLED, $params);
            return $rf->fresh();
        });
    }

    // ──────────────────────────────────────────
    //  5. Exchange settlement (price difference handling)
    // ──────────────────────────────────────────
    public function recordSettlement(OndcReturnRequest $rr, array $params): OndcReturnRequest
    {
        if (! $rr->isExchange()) {
            throw new \LogicException('Settlement only applies to exchange flow');
        }

        $newStatus = $params['settlement_status'] ?? OndcReturnRequest::SETTLE_COLLECTED;

        if (! in_array($newStatus, [
            OndcReturnRequest::SETTLE_PENDING,
            OndcReturnRequest::SETTLE_COLLECTED,
            OndcReturnRequest::SETTLE_REFUNDED,
            OndcReturnRequest::SETTLE_NOT_REQUIRED,
        ], true)) {
            throw new \InvalidArgumentException("Invalid settlement_status: $newStatus");
        }

        $rr->update(['settlement_status' => $newStatus]);

        // Log against the replacement fulfillment if exists
        if ($rf = $rr->replacementFulfillment) {
            $this->logEvent(
                $rf,
                OndcReplacementEvent::TYPE_PAYMENT_COLLECTED,
                $rf->state,
                $rf->state,
                $params
            );
        }

        Log::info('[ONDC] Exchange settlement recorded', [
            'return_request_id' => $rr->return_request_id,
            'status'            => $newStatus,
            'amount'            => $rr->exchange_price_difference,
        ]);

        return $rr->fresh();
    }

    // ──────────────────────────────────────────
    //  6. Build /update payload with replace=yes or exchange=yes
    // ──────────────────────────────────────────
    public function buildReplacementOrExchangePayload(OndcReturnRequest $rr): array
    {
        // Start from the standard return-request payload
        $payload = $this->reverseQcService->buildReturnRequestUpdatePayload($rr);

        $tags = $payload['message']['order']['fulfillments'][0]['tags'];

        if ($rr->isReplacement()) {
            // Append { code: replace, value: yes } inside the return_request list
            // (Pramaan validator requires list-format on every tag)
            $list = $tags[0]['list'];
            $list[] = ['code' => 'replace', 'value' => 'yes'];
            $tags[0]['list'] = array_values($list);
        } elseif ($rr->isExchange()) {
            // Append exchange + spec items to the existing return_request list
            $list = $tags[0]['list'];
            $list[] = ['code' => 'exchange', 'value' => 'yes'];

            $specs = $rr->exchange_item_specs ?? [];
            foreach (['brand', 'model', 'ram_unit', 'ram', 'storage_unit', 'storage'] as $key) {
                if (! empty($specs[$key])) {
                    $list[] = ['code' => $key, 'value' => (string) $specs[$key]];
                }
            }
            if ($rr->condition_id) {
                $list[] = ['code' => 'condition_id',   'value' => $rr->condition_id];
            }
            if ($rr->condition_desc) {
                $list[] = ['code' => 'condition_desc', 'value' => $rr->condition_desc];
            }
            $tags[0]['list'] = array_values($list);
        }

        $payload['message']['order']['fulfillments'][0]['tags'] = $tags;
        return $payload;
    }

    // ──────────────────────────────────────────
    //  7. Combined helper: do replacement/exchange end-to-end
    // ──────────────────────────────────────────
    public function autoStartForwardOnQcPass(OndcReturnRequest $rr, array $params = []): ?OndcReplacementFulfillment
    {
        if ($rr->status !== OndcReturnRequest::STATUS_QC_PASSED) return null;
        if (! in_array($rr->flow_type, [OndcReturnRequest::FLOW_REPLACEMENT, OndcReturnRequest::FLOW_EXCHANGE], true)) return null;
        if ($rr->replacementFulfillment) return $rr->replacementFulfillment;

        return $this->createForwardFulfillment($rr, $params);
    }

    // ──────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────
    protected function logEvent(
        OndcReplacementFulfillment $rf,
        string $type,
        ?string $previousState,
        string $newState,
        array $params
    ): OndcReplacementEvent {
        return OndcReplacementEvent::create([
            'replacement_fulfillment_id' => $rf->id,
            'event_type'                 => $type,
            'previous_state'             => $previousState,
            'new_state'                  => $newState,
            'triggered_by'               => $params['triggered_by'] ?? null,
            'payload'                    => $params['payload'] ?? null,
            'notes'                      => $params['notes'] ?? null,
        ]);
    }
}
