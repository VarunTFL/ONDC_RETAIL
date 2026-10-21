<?php

namespace Webkul\Ondc\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Ondc\Models\OndcCancelledItem;
use Webkul\Ondc\Models\OndcLiquidationEvent;
use Webkul\Ondc\Models\OndcOrderCancellation;

/**
 * Liquidation: after a cancel or RTO, the seller decides how to dispose of
 * the returned/rejected items. Choices:
 *   - RESTOCKED: item put back into inventory
 *   - LIQUIDATED: sold at discount, auction, B2B bulk, etc.
 *   - DISPOSED: damaged / expired / written off
 *
 * An interim state (INTERIM_LIQUIDATING) is allowed while the liquidation
 * channel is arranging the deal. Full ONDC /update flow uses return_request
 * tags; here we model it via disposition tracking on cancelled items.
 */
class LiquidationService
{
    /**
     * Record a liquidation request for a whole cancellation.
     */
    public function requestLiquidation(OndcOrderCancellation $cancellation, array $params): OndcOrderCancellation
    {
        return DB::transaction(function () use ($cancellation, $params) {
            $channel = $params['channel'] ?? 'DISCOUNT_SALE';
            $value   = (float) ($params['value'] ?? 0);

            $cancellation->update([
                'disposition_type'     => OndcOrderCancellation::DISP_INTERIM,
                'liquidation_value'    => $value,
                'liquidation_channel'  => $channel,
                'liquidation_notes'    => $params['notes'] ?? null,
            ]);

            // Mark all cancelled items as INTERIM
            $cancellation->cancelledItems()->update([
                'disposition'         => OndcCancelledItem::DISPOSITION_INTERIM,
                'liquidation_channel' => $channel,
            ]);

            $this->logEvent(
                $cancellation,
                null,
                OndcLiquidationEvent::TYPE_REQUESTED,
                OndcOrderCancellation::DISP_INTERIM,
                $channel,
                $value,
                $params
            );

            Log::info('[ONDC] Liquidation requested', [
                'cancellation_id' => $cancellation->id,
                'channel'         => $channel,
                'value'           => $value,
            ]);

            return $cancellation->fresh(['cancelledItems', 'liquidationEvents']);
        });
    }

    /**
     * Record interim update while liquidation is in progress.
     */
    public function recordInterimStatus(OndcOrderCancellation $cancellation, array $params): OndcOrderCancellation
    {
        return DB::transaction(function () use ($cancellation, $params) {
            $this->logEvent(
                $cancellation,
                null,
                OndcLiquidationEvent::TYPE_INTERIM,
                OndcOrderCancellation::DISP_INTERIM,
                $cancellation->liquidation_channel,
                (float) ($params['value'] ?? 0),
                $params
            );

            Log::info('[ONDC] Liquidation interim update', [
                'cancellation_id' => $cancellation->id,
                'notes'           => $params['notes'] ?? null,
            ]);

            return $cancellation->fresh('liquidationEvents');
        });
    }

    /**
     * Complete liquidation with final disposition for the cancellation.
     */
    public function completeLiquidation(OndcOrderCancellation $cancellation, array $params): OndcOrderCancellation
    {
        return DB::transaction(function () use ($cancellation, $params) {
            $disposition = $params['disposition'] ?? OndcOrderCancellation::DISP_LIQUIDATED;
            $channel     = $params['channel'] ?? $cancellation->liquidation_channel ?? 'DISCOUNT_SALE';
            $value       = (float) ($params['value'] ?? $cancellation->liquidation_value ?? 0);

            if (! in_array($disposition, [
                OndcOrderCancellation::DISP_RESTOCKED,
                OndcOrderCancellation::DISP_LIQUIDATED,
                OndcOrderCancellation::DISP_DISPOSED,
                OndcOrderCancellation::DISP_PARTIAL_LIQUIDATED,
            ], true)) {
                throw new \InvalidArgumentException("Invalid disposition: $disposition");
            }

            $cancellation->update([
                'disposition_type'    => $disposition,
                'liquidation_value'   => $value,
                'liquidation_channel' => $channel,
                'liquidation_notes'   => $params['notes'] ?? $cancellation->liquidation_notes,
                'liquidated_at'       => now(),
            ]);

            // Update all pending items to the new disposition
            $itemDisposition = match ($disposition) {
                OndcOrderCancellation::DISP_RESTOCKED  => OndcCancelledItem::DISPOSITION_RESTOCKED,
                OndcOrderCancellation::DISP_LIQUIDATED => OndcCancelledItem::DISPOSITION_LIQUIDATED,
                OndcOrderCancellation::DISP_DISPOSED   => OndcCancelledItem::DISPOSITION_DISPOSED,
                default                                 => OndcCancelledItem::DISPOSITION_LIQUIDATED,
            };
            $cancellation->cancelledItems()
                ->whereIn('disposition', [
                    OndcCancelledItem::DISPOSITION_PENDING,
                    OndcCancelledItem::DISPOSITION_INTERIM,
                ])
                ->update([
                    'disposition'  => $itemDisposition,
                    'disposed_at'  => now(),
                ]);

            $this->logEvent(
                $cancellation,
                null,
                OndcLiquidationEvent::TYPE_COMPLETED,
                $disposition,
                $channel,
                $value,
                $params
            );

            Log::info('[ONDC] Liquidation completed', [
                'cancellation_id' => $cancellation->id,
                'disposition'     => $disposition,
                'value'           => $value,
            ]);

            return $cancellation->fresh(['cancelledItems', 'liquidationEvents']);
        });
    }

    /**
     * Set disposition on a specific cancelled item (for partial liquidation).
     */
    public function setItemDisposition(OndcCancelledItem $item, array $params): OndcCancelledItem
    {
        return DB::transaction(function () use ($item, $params) {
            $disposition = $params['disposition'] ?? OndcCancelledItem::DISPOSITION_LIQUIDATED;

            if (! in_array($disposition, [
                OndcCancelledItem::DISPOSITION_PENDING,
                OndcCancelledItem::DISPOSITION_INTERIM,
                OndcCancelledItem::DISPOSITION_RESTOCKED,
                OndcCancelledItem::DISPOSITION_LIQUIDATED,
                OndcCancelledItem::DISPOSITION_DISPOSED,
            ], true)) {
                throw new \InvalidArgumentException("Invalid disposition: $disposition");
            }

            $item->update([
                'disposition'         => $disposition,
                'liquidation_value'   => $params['liquidation_value'] ?? $item->liquidation_value,
                'liquidation_channel' => $params['channel'] ?? $item->liquidation_channel,
                'notes'               => $params['notes'] ?? $item->notes,
                'disposed_at'         => in_array($disposition, [
                    OndcCancelledItem::DISPOSITION_LIQUIDATED,
                    OndcCancelledItem::DISPOSITION_RESTOCKED,
                    OndcCancelledItem::DISPOSITION_DISPOSED,
                ]) ? now() : $item->disposed_at,
            ]);

            $this->logEvent(
                $item->cancellation,
                $item->id,
                OndcLiquidationEvent::TYPE_COMPLETED,
                $disposition,
                $item->liquidation_channel,
                (float) ($item->liquidation_value ?? 0),
                $params
            );

            // Update parent cancellation overall disposition
            $this->recalculateParentDisposition($item->cancellation);

            return $item->fresh();
        });
    }

    /**
     * Recalculate the overall disposition based on item-level dispositions.
     */
    protected function recalculateParentDisposition(OndcOrderCancellation $cancellation): void
    {
        $items = $cancellation->cancelledItems()->get();

        $dispositions = $items->pluck('disposition')->unique()->values()->all();
        $pending      = in_array(OndcCancelledItem::DISPOSITION_PENDING, $dispositions)
                     || in_array(OndcCancelledItem::DISPOSITION_INTERIM, $dispositions);

        if ($pending) {
            return; // keep current state
        }

        $overall = match (true) {
            count($dispositions) === 1 && $dispositions[0] === OndcCancelledItem::DISPOSITION_RESTOCKED  => OndcOrderCancellation::DISP_RESTOCKED,
            count($dispositions) === 1 && $dispositions[0] === OndcCancelledItem::DISPOSITION_LIQUIDATED => OndcOrderCancellation::DISP_LIQUIDATED,
            count($dispositions) === 1 && $dispositions[0] === OndcCancelledItem::DISPOSITION_DISPOSED   => OndcOrderCancellation::DISP_DISPOSED,
            default => OndcOrderCancellation::DISP_PARTIAL_LIQUIDATED,
        };

        $cancellation->update([
            'disposition_type' => $overall,
            'liquidated_at'    => $cancellation->liquidated_at ?? now(),
        ]);
    }

    protected function logEvent(
        OndcOrderCancellation $cancellation,
        ?int $cancelledItemId,
        string $type,
        string $disposition,
        ?string $channel,
        ?float $value,
        array $params
    ): OndcLiquidationEvent {
        return OndcLiquidationEvent::create([
            'cancellation_id'    => $cancellation->id,
            'cancelled_item_id'  => $cancelledItemId,
            'event_type'         => $type,
            'disposition'        => $disposition,
            'channel'            => $channel,
            'value'              => $value,
            'triggered_by'       => $params['triggered_by'] ?? $cancellation->cancelled_by,
            'payload'            => $params['payload'] ?? null,
            'notes'              => $params['notes'] ?? null,
        ]);
    }
}
