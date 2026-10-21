<?php

namespace Webkul\Ondc\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webkul\Ondc\Models\OndcCancelledItem;
use Webkul\Ondc\Models\OndcOrderCancellation;
use Webkul\Ondc\Models\OndcReturnRequest;

/**
 * Orchestrates the full ONDC end-to-end flow:
 *
 *   Part Cancellation  →  Reverse QC  →  Liquidation
 *
 * For partial returns where the item flows back to the seller via reverse logistics,
 * undergoes QC, and (if accepted) gets liquidated.
 */
class CombinedFlowService
{
    public function __construct(
        protected PartialCancellationService $partialService,
        protected ReverseQcService $reverseQcService,
        protected LiquidationService $liquidationService,
    ) {}

    /**
     * Run the full chain: part cancel → return → QC → liquidation.
     *
     * Useful for testing the entire pipeline in one call (or use as a
     * higher-level orchestration to track linked records).
     */
    public function executeFullChain(array $params): array
    {
        return DB::transaction(function () use ($params) {
            // 1. Partial cancel (creates cancellation + cancelled items)
            $cancellation = $this->partialService->initiatePartialCancel($params);

            // 2. Create a return request linked to the cancellation
            $items = $cancellation->cancelledItems;
            $firstItem = $items->first();

            $returnRequest = $this->reverseQcService->initiateReturn([
                'ondc_order_id'   => $cancellation->ondc_order_id,
                'transaction_id'  => $cancellation->ondc_transaction_id,
                'order_id'        => $cancellation->order_id,
                'item_id'         => $firstItem->item_id,
                'item_quantity'   => $firstItem->cancelled_quantity,
                'reason_id'       => $params['return_reason_id'] ?? '003',
                'reason_desc'     => $params['return_reason_desc'] ?? 'Quality issue',
                'images'          => $params['evidence_images'] ?? [],
                'refund_amount'   => $firstItem->refund_amount,
            ]);

            // Link return request to cancellation
            $returnRequest->update(['cancellation_id' => $cancellation->id]);

            return [
                'cancellation_id'   => $cancellation->id,
                'return_request_id' => $returnRequest->id,
                'cancelled_items'   => $items->count(),
                'next_step'         => 'approve_return',
            ];
        });
    }

    /**
     * Once QC passes, automatically trigger liquidation flow on linked cancellation.
     */
    public function onQcPassedTriggerLiquidation(OndcReturnRequest $rr, array $params = []): ?OndcOrderCancellation
    {
        if ($rr->status !== OndcReturnRequest::STATUS_QC_PASSED) {
            return null;
        }

        if (! $rr->cancellation_id) {
            Log::info('[ONDC] QC passed but no linked cancellation to liquidate', [
                'return_request_id' => $rr->return_request_id,
            ]);
            return null;
        }

        $cancellation = OndcOrderCancellation::find($rr->cancellation_id);
        if (! $cancellation) {
            return null;
        }

        // Auto-trigger liquidation request with default channel
        $channel = $params['channel'] ?? 'DISCOUNT_SALE';
        $value   = (float) ($rr->refund_amount ?? 0) * 0.7; // 70% of refund as recoverable estimate

        $cancellation = $this->liquidationService->requestLiquidation($cancellation, [
            'channel' => $channel,
            'value'   => $value,
            'notes'   => "Auto-triggered after reverse QC pass for return {$rr->return_request_id}",
            'triggered_by' => $params['triggered_by'] ?? config('ondc.bpp_id'),
        ]);

        Log::info('[ONDC] Liquidation auto-triggered after QC pass', [
            'cancellation_id'   => $cancellation->id,
            'return_request_id' => $rr->return_request_id,
        ]);

        return $cancellation;
    }

    /**
     * Get the unified status of a combined flow given a return request.
     */
    public function getCombinedStatus(OndcReturnRequest $rr): array
    {
        $cancellation = $rr->cancellation_id ? OndcOrderCancellation::with(['cancelledItems', 'liquidationEvents'])->find($rr->cancellation_id) : null;
        $qc = $rr->qcResult;

        $stages = [
            'part_cancel' => [
                'status'    => $cancellation ? $cancellation->status : 'NOT_STARTED',
                'cancel_id' => $cancellation?->id,
                'items'     => $cancellation?->cancelledItems?->count() ?? 0,
            ],
            'reverse_qc' => [
                'status'             => $rr->status,
                'qc_status'          => $qc?->qc_status,
                'qc_initiated_at'    => $qc?->qc_initiated_at,
                'qc_completed_at'    => $qc?->qc_completed_at,
                'inspector'          => $qc?->inspector,
                'failure_reason'     => $qc?->failure_reason,
                'approved_refund'    => $qc?->approved_refund,
            ],
            'liquidation' => [
                'status'         => $cancellation?->disposition_type ?? 'NOT_STARTED',
                'channel'        => $cancellation?->liquidation_channel,
                'value'          => $cancellation?->liquidation_value,
                'liquidated_at'  => $cancellation?->liquidated_at,
                'events_logged'  => $cancellation?->liquidationEvents?->count() ?? 0,
            ],
        ];

        $overall = $this->computeOverallStatus($rr, $cancellation);

        return [
            'overall_status'    => $overall,
            'return_request_id' => $rr->return_request_id,
            'ondc_order_id'     => $rr->ondc_order_id,
            'transaction_id'    => $rr->ondc_transaction_id,
            'stages'            => $stages,
            'updated_at'        => $rr->updated_at,
        ];
    }

    protected function computeOverallStatus(OndcReturnRequest $rr, ?OndcOrderCancellation $cancellation): string
    {
        if ($rr->status === OndcReturnRequest::STATUS_QC_FAILED) return 'QC_FAILED';
        if ($rr->status === OndcReturnRequest::STATUS_REJECTED)  return 'RETURN_REJECTED';

        if ($cancellation?->disposition_type === OndcOrderCancellation::DISP_LIQUIDATED) return 'LIQUIDATED';
        if ($cancellation?->disposition_type === OndcOrderCancellation::DISP_RESTOCKED)  return 'RESTOCKED';
        if ($cancellation?->disposition_type === OndcOrderCancellation::DISP_DISPOSED)   return 'DISPOSED';
        if ($cancellation?->disposition_type === OndcOrderCancellation::DISP_INTERIM)    return 'AWAITING_LIQUIDATION';

        if ($rr->status === OndcReturnRequest::STATUS_QC_PASSED)        return 'QC_PASSED';
        if ($rr->status === OndcReturnRequest::STATUS_QC_IN_PROGRESS)   return 'QC_IN_PROGRESS';
        if ($rr->status === OndcReturnRequest::STATUS_PICKUP_INITIATED) return 'PICKUP_DONE';
        if ($rr->status === OndcReturnRequest::STATUS_APPROVED)         return 'AWAITING_PICKUP';
        return 'AWAITING_APPROVAL';
    }
}
