<?php

namespace Webkul\Ondc\Services\Fnb;

use Illuminate\Support\Facades\Log;
use Webkul\Ondc\Models\OndcFnbOrder;
use Webkul\Ondc\Models\OndcOrderCancellation;
use Webkul\Ondc\Services\CancellationService;
use Webkul\Ondc\Services\LiquidationService;
use Webkul\Ondc\Services\PartialCancellationService;
use Webkul\Ondc\Services\SellerCancellationService;

/**
 * Adapter that wraps the existing cancellation services with F&B-specific
 * domain (ONDC:RET11) and reason mappings. Reuses the core state machines.
 */
class FnbCancellationAdapter
{
    public const DOMAIN = 'ONDC:RET11';

    /**
     * F&B-specific reason codes (subset of standard codes most relevant for food).
     */
    public const FNB_BUYER_REASONS = [
        '001' => 'Price changed',
        '003' => 'Product available at lower price',
        '006' => 'Order not received as per TAT (kitchen too slow)',
        '009' => 'Wrong item delivered',
        '010' => 'Buyer wants to modify address',
    ];

    public const FNB_SELLER_REASONS = [
        '002' => 'Item not available (out of ingredient)',
        '005' => 'Restaurant closed (merchant rejected)',
        '008' => 'Order not ready for pickup',
        '011' => 'Buyer not contactable',
        '012' => 'Buyer doesn\'t want order anymore',
        '013' => 'Buyer refused delivery',
        '015' => 'Buyer not at delivery location',
    ];

    public function __construct(
        protected CancellationService $cancellationService,
        protected SellerCancellationService $sellerCancellationService,
        protected PartialCancellationService $partialCancellationService,
        protected LiquidationService $liquidationService,
    ) {}

    // ──────────────────────────────────────────
    //  Buyer-side cancel
    // ──────────────────────────────────────────
    public function initiateBuyerCancel(array $params): OndcOrderCancellation
    {
        $params['domain'] = self::DOMAIN;

        // Look up F&B order to get internal order id
        if (! empty($params['ondc_order_id'])) {
            $fnb = OndcFnbOrder::where('ondc_order_id', $params['ondc_order_id'])->first();
            if ($fnb) {
                $params['precancel_fulfillment_state'] = $params['precancel_fulfillment_state'] ?? $fnb->fulfillment_state;
            }
        }

        $cancellation = $this->cancellationService->initiateBuyerCancel($params);

        // Update F&B order state too
        if (isset($fnb)) {
            $fnb->update([
                'state'             => 'Cancelled',
                'fulfillment_state' => 'Cancelled',
            ]);
        }

        Log::info('[ONDC F&B] Buyer cancel initiated', [
            'cancel_id' => $cancellation->id,
            'reason'    => $cancellation->cancellation_reason_id,
        ]);

        return $cancellation;
    }

    // ──────────────────────────────────────────
    //  Seller-side cancel + RTO
    // ──────────────────────────────────────────
    public function initiateSellerCancel(array $params): OndcOrderCancellation
    {
        $params['domain'] = self::DOMAIN;

        if (! empty($params['ondc_order_id'])) {
            $fnb = OndcFnbOrder::where('ondc_order_id', $params['ondc_order_id'])->first();
            if ($fnb) {
                $params['precancel_fulfillment_state'] = $params['precancel_fulfillment_state'] ?? $fnb->fulfillment_state;
            }
        }

        $cancellation = $this->sellerCancellationService->initiateSellerCancel($params);

        if (isset($fnb)) {
            $fnb->update([
                'state'             => $cancellation->triggers_rto ? 'In-progress' : 'Cancelled',
                'fulfillment_state' => $cancellation->triggers_rto ? $fnb->fulfillment_state : 'Cancelled',
            ]);
        }

        Log::info('[ONDC F&B] Seller cancel initiated', [
            'cancel_id' => $cancellation->id,
            'rto'       => $cancellation->triggers_rto,
        ]);

        return $cancellation;
    }

    // ──────────────────────────────────────────
    //  Part cancel
    // ──────────────────────────────────────────
    public function initiatePartCancel(array $params): OndcOrderCancellation
    {
        $params['domain'] = self::DOMAIN;
        return $this->partialCancellationService->initiatePartialCancel($params);
    }

    // ──────────────────────────────────────────
    //  Liquidation (limited for F&B — perishable items usually DISPOSED)
    // ──────────────────────────────────────────
    public function completeLiquidation(OndcOrderCancellation $cancellation, array $params): OndcOrderCancellation
    {
        // F&B items are typically DISPOSED (perishable) rather than LIQUIDATED
        $params['disposition'] = $params['disposition'] ?? OndcOrderCancellation::DISP_DISPOSED;
        $params['channel']     = $params['channel'] ?? 'WRITE_OFF';

        return $this->liquidationService->completeLiquidation($cancellation, $params);
    }

    public function getApplicableReasons(string $for = 'buyer'): array
    {
        $codes = $for === 'seller' ? self::FNB_SELLER_REASONS : self::FNB_BUYER_REASONS;
        return collect($codes)->map(fn ($desc, $code) => ['code' => $code, 'reason' => $desc])->values()->all();
    }
}
