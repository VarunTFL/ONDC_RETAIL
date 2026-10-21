<?php

namespace Webkul\Ondc\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Webkul\Ondc\Models\OndcFnbCatalog;
use Webkul\Ondc\Models\OndcFnbItem;
use Webkul\Ondc\Models\OndcFnbOrder;
use Webkul\Ondc\Services\Fnb\FnbCancellationAdapter;
use Webkul\Ondc\Services\Fnb\FnbDeliveryService;
use Webkul\Ondc\Services\Fnb\FnbDiscoveryService;
use Webkul\Ondc\Services\Fnb\FnbOutOfStockService;

class FnbController extends Controller
{
    public function __construct(
        protected FnbDiscoveryService $discovery,
        protected FnbDeliveryService $delivery,
        protected FnbOutOfStockService $oos,
        protected FnbCancellationAdapter $cancel,
    ) {}

    // ════════════════════════════════════════════════
    //  CATALOG MANAGEMENT (admin / setup)
    // ════════════════════════════════════════════════

    public function listCatalogs(): JsonResponse
    {
        $catalogs = OndcFnbCatalog::with('items')->where('is_active', true)->get();
        return response()->json(['success' => true, 'data' => $catalogs]);
    }

    public function showCatalog(int $id): JsonResponse
    {
        $catalog = OndcFnbCatalog::with(['items', 'customizationGroups'])->find($id);
        if (! $catalog) return response()->json(['success' => false, 'error' => 'Not found'], 404);
        return response()->json(['success' => true, 'data' => $catalog]);
    }

    public function createCatalog(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider_id' => 'required|string|unique:ondc_fnb_catalogs,provider_id',
            'name'        => 'required|string',
            'cuisine'     => 'nullable|string',
            'is_veg_only' => 'nullable|boolean',
            'fssai_license' => 'nullable|string',
            'gps'         => 'nullable|string',
            'address'     => 'nullable|array',
            'phone'       => 'nullable|string',
            'preparation_time' => 'nullable|string',
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        $catalog = OndcFnbCatalog::create($request->all());
        return response()->json(['success' => true, 'data' => $catalog], 201);
    }

    public function addItem(int $catalogId, Request $request): JsonResponse
    {
        $catalog = OndcFnbCatalog::find($catalogId);
        if (! $catalog) return response()->json(['success' => false, 'error' => 'Catalog not found'], 404);

        $validator = Validator::make($request->all(), [
            'ondc_item_id'  => 'required|string|unique:ondc_fnb_items,ondc_item_id',
            'name'          => 'required|string',
            'price'         => 'required|numeric|min:0',
            'type'          => 'nullable|in:item,customization',
            'parent_item_id' => 'nullable|string',
            'veg_status'    => 'nullable|in:veg,non-veg,egg',
            'available_count' => 'nullable|integer|min:0',
            'description'   => 'nullable|string',
            'image'         => 'nullable|string',
            'preparation_time' => 'nullable|string',
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        $item = OndcFnbItem::create(array_merge($request->all(), ['catalog_id' => $catalogId]));
        return response()->json(['success' => true, 'data' => $item], 201);
    }

    public function updateItemAvailability(int $itemId, Request $request): JsonResponse
    {
        $item = OndcFnbItem::find($itemId);
        if (! $item) return response()->json(['success' => false, 'error' => 'Item not found'], 404);

        $action = $request->input('action', 'restock');
        if ($action === 'mark_oos') {
            $item->markOutOfStock($request->input('triggered_by', 'admin'), $request->input('notes', ''));
        } else {
            $count = (int) $request->input('count', 99);
            $item->restock($count, $request->input('triggered_by', 'admin'));
        }

        return response()->json(['success' => true, 'data' => $item->fresh()]);
    }

    // ════════════════════════════════════════════════
    //  FLOW 1: DISCOVERY
    // ════════════════════════════════════════════════

    public function search(Request $request): JsonResponse
    {
        $validation = $this->discovery->validateSearch($request->all());
        if (! $validation['valid']) {
            return response()->json([
                'message' => ['ack' => ['status' => 'NACK']],
                'error'   => ['code' => '30009', 'message' => $validation['error']],
            ], 400);
        }

        $onSearch = $this->discovery->processSearchAndBuildOnSearch($request->all());

        return response()->json([
            'message' => ['ack' => ['status' => 'ACK']],
            'on_search_payload' => $onSearch,
        ]);
    }

    public function buildSearch(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->discovery->buildSearchPayload($request->all()),
        ]);
    }

    // ════════════════════════════════════════════════
    //  FLOW 2: DELIVERY (select → init → confirm → status)
    // ════════════════════════════════════════════════

    public function select(Request $request): JsonResponse
    {
        try {
            $onSelect = $this->delivery->processSelect($request->all());
            return response()->json([
                'message' => ['ack' => ['status' => 'ACK']],
                'on_select_payload' => $onSelect,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => ['ack' => ['status' => 'NACK']],
                'error'   => ['code' => '30009', 'message' => $e->getMessage()],
            ], 400);
        }
    }

    public function init(Request $request): JsonResponse
    {
        try {
            $onInit = $this->delivery->processInit($request->all());
            return response()->json([
                'message' => ['ack' => ['status' => 'ACK']],
                'on_init_payload' => $onInit,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => ['ack' => ['status' => 'NACK']],
                'error'   => ['code' => '30009', 'message' => $e->getMessage()],
            ], 400);
        }
    }

    public function confirm(Request $request): JsonResponse
    {
        try {
            $onConfirm = $this->delivery->processConfirm($request->all());
            return response()->json([
                'message' => ['ack' => ['status' => 'ACK']],
                'on_confirm_payload' => $onConfirm,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => ['ack' => ['status' => 'NACK']],
                'error'   => ['code' => '30009', 'message' => $e->getMessage()],
            ], 400);
        }
    }

    public function advanceStatus(string $orderId, Request $request): JsonResponse
    {
        try {
            $order = $this->delivery->advanceFulfillment($orderId, $request->all());
            return response()->json([
                'success' => true,
                'data'    => $order,
                'on_status_payload' => $this->delivery->buildOnStatus($order),
            ]);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Order not found'], 404);
        }
    }

    public function getOrder(string $orderId): JsonResponse
    {
        $order = OndcFnbOrder::where('ondc_order_id', $orderId)->with('catalog')->first();
        if (! $order) return response()->json(['success' => false, 'error' => 'Not found'], 404);
        return response()->json(['success' => true, 'data' => $order]);
    }

    // ════════════════════════════════════════════════
    //  FLOW 3: OUT OF STOCK
    // ════════════════════════════════════════════════

    public function checkOutOfStock(Request $request): JsonResponse
    {
        $items = $request->input('items', []);
        if (empty($items)) return response()->json(['success' => false, 'error' => 'items required'], 422);

        $result = $this->oos->checkOutOfStock($items);
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function suggestAlternatives(string $itemId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->oos->suggestAlternatives($itemId),
        ]);
    }

    // ════════════════════════════════════════════════
    //  FLOW 4: BUYER CANCEL
    // ════════════════════════════════════════════════

    public function buyerCancel(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ondc_order_id'          => 'required|string',
            'transaction_id'         => 'required|string',
            'cancellation_reason_id' => 'required|string|exists:ondc_cancellation_reasons,code',
            'bpp_id'                 => 'required|string',
            'bpp_uri'                => 'required|string',
            'buyer_note'             => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        try {
            $cancellation = $this->cancel->initiateBuyerCancel($request->all());
            return response()->json([
                'success' => true,
                'data'    => $cancellation,
                'cancel_payload' => $cancellation->cancel_request_payload,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function buyerReasons(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->cancel->getApplicableReasons('buyer')]);
    }

    // ════════════════════════════════════════════════
    //  FLOW 5: SELLER CANCEL + RTO
    // ════════════════════════════════════════════════

    public function sellerCancel(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ondc_order_id'              => 'required|string',
            'transaction_id'             => 'required|string',
            'cancellation_reason_id'     => 'required|string',
            'precancel_fulfillment_state' => 'nullable|string',
            'seller_note'                => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        try {
            $cancellation = $this->cancel->initiateSellerCancel($request->all());
            return response()->json([
                'success' => true,
                'data'    => $cancellation,
                'rto'     => [
                    'triggered'      => $cancellation->triggers_rto,
                    'status'         => $cancellation->rto_status,
                    'fulfillment_id' => $cancellation->rto_fulfillment_id,
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function sellerReasons(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->cancel->getApplicableReasons('seller')]);
    }

    // ════════════════════════════════════════════════
    //  FLOW 6: PART CANCELLATION
    // ════════════════════════════════════════════════

    public function partCancel(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ondc_order_id'          => 'required|string',
            'transaction_id'         => 'required|string',
            'cancellation_reason_id' => 'required|string',
            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'required|string',
            'items.*.original_quantity'  => 'required|integer|min:1',
            'items.*.cancelled_quantity' => 'required|integer|min:1',
            'items.*.item_price'         => 'required|numeric|min:0',
            'items.*.fulfillment_id'     => 'nullable|string',
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        try {
            $cancellation = $this->cancel->initiatePartCancel($request->all());
            return response()->json(['success' => true, 'data' => $cancellation], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ════════════════════════════════════════════════
    //  FLOW 7: LIQUIDATION (perishable F&B = usually DISPOSED)
    // ════════════════════════════════════════════════

    public function liquidation(int $cancellationId, Request $request): JsonResponse
    {
        $cancellation = \Webkul\Ondc\Models\OndcOrderCancellation::find($cancellationId);
        if (! $cancellation) return response()->json(['success' => false, 'error' => 'Not found'], 404);

        $validator = Validator::make($request->all(), [
            'disposition' => 'nullable|in:RESTOCKED,LIQUIDATED,DISPOSED,PARTIAL_LIQUIDATED',
            'channel'     => 'nullable|in:AUCTION,DISCOUNT_SALE,WRITE_OFF,B2B_BULK',
            'value'       => 'nullable|numeric|min:0',
            'notes'       => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        try {
            $updated = $this->cancel->completeLiquidation($cancellation, $request->all());
            return response()->json(['success' => true, 'data' => $updated]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
