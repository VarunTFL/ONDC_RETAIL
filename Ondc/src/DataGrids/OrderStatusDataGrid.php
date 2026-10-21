<?php

namespace Webkul\Ondc\DataGrids;

use Webkul\DataGrid\DataGrid;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeFamilyRepository;
use Illuminate\Support\Facades\Storage;

class OrderStatusDataGrid extends DataGrid
{
    protected $primaryColumn = 'order_item_id';

    public function __construct(protected AttributeFamilyRepository $attributeFamilyRepository) {}

    public function prepareQueryBuilder()
    {
        // Latest return request per order — used to drive the Approve/Reject/
        // Picked/QC-Pass/QC-Fail buttons. We only need the single most recent
        // return per order on this grid (one row per order_item already), so
        // grab its id/status/item via a correlated subquery rather than a
        // join that could duplicate rows if an order has multiple returns.
        $latestReturnSub = DB::table('ondc_return_requests as rr1')
            ->selectRaw('MAX(rr1.id)')
            ->whereColumn('rr1.order_id', 'orders.id');

        $queryBuilder = DB::table('orders')
            ->leftJoin('order_payment', 'order_payment.order_id', '=', 'orders.id')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            // 1. MUST be leftJoin so NULL product_id for F&B does not drop the row
            ->leftJoin('product_flat', 'product_flat.product_id', '=', 'order_items.product_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('product_images', 'product_images.product_id', '=', 'order_items.product_id')
            ->leftJoin('addresses', function ($join) {
                $join->on('addresses.order_id', '=', 'orders.id')
                     ->whereIn('addresses.address_type', ['order_billing', 'billing']);
            })
            // Latest return request for this order, if any
            ->leftJoin('ondc_return_requests', function ($join) use ($latestReturnSub) {
                $join->on('ondc_return_requests.order_id', '=', 'orders.id')
                     ->where('ondc_return_requests.id', '=', $latestReturnSub);
            })
            ->select(
                'order_items.id as order_item_id',
                'order_items.order_id',
                'orders.increment_id',
                'order_items.product_id',
                // Fallbacks for SKU and Name when product_id is NULL
                DB::raw("COALESCE(products.sku, order_items.sku, '') as sku"),
                DB::raw("COALESCE(product_flat.name, order_items.name, order_items.name, 'F&B Item') as name"),
                'product_images.path as base_image',
                'orders.status AS order_status',
                'order_items.qty_ordered as quantity',
                'order_items.total as price',
                'order_payment.method',
                'order_payment.additional AS payment_details',
                'addresses.first_name',
                'addresses.email',
                'addresses.phone',
                DB::raw("CONCAT_WS(', ', addresses.address, addresses.city, addresses.state, addresses.country, addresses.postcode) AS address"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(order_payment.additional, '$.status')) AS payment_status"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(order_payment.additional, '$.ondc_transaction_id')) AS ondc_txn_id"),
                DB::raw("EXISTS(SELECT 1 FROM ondc_order_cancellations WHERE ondc_order_cancellations.order_id = orders.id AND ondc_order_cancellations.triggers_rto = 1 AND ondc_order_cancellations.rto_status = 'RTO-Initiated') AS has_active_rto"),
                // Return request fields for the Approve/Reject/Picked/QC buttons
                'ondc_return_requests.id as return_request_db_id',
                'ondc_return_requests.return_request_id as return_request_id',
                'ondc_return_requests.status as return_status',
                'ondc_return_requests.item_id as return_item_id'
            )
            // 2. Sort descending so newest confirmed orders appear on Page 1
            ->orderBy('orders.id', 'desc');

        $this->addFilter('increment_id', 'orders.increment_id');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index' => 'increment_id',
            'label' => 'Order ID',
            'type' => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'name',
            'label' => 'Name',
            'type' => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'sku',
            'label' => 'SKU',
            'type' => 'string',
            'filterable' => true,
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'quantity',
            'label' => 'Quantity',
            'type' => 'integer',
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'base_image',
            'label' => trans('admin::app.catalog.products.index.datagrid.image'),
            'type' => 'string',
            'exportable' => false,
            'closure' => function ($row) {
                if (! $row->base_image) {
                    return;
                }

                return Storage::url($row->base_image);
            },
        ]);

        $this->addColumn([
            'index' => 'price',
            'label' => 'Price',
            'type' => 'decimal',
            'filterable' => true,
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'order_status',
            'label' => 'Order Status',
            'type' => 'string',
            'filterable' => true,
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'method',
            'label' => 'Payment Method',
            'type' => 'string',
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'payment_status',
            'label' => 'Payment Status',
            'type' => 'string',
            'filterable' => true,
            'closure' => function ($row) {
                return $row->payment_status ?? 'Not Paid';
            },
        ]);

        $this->addColumn([
            'index' => 'ondc_txn_id',
            'label' => 'ONDC Transaction ID',
            'type' => 'string',
            'searchable' => true,
        ]);

        $this->addColumn([
            'index' => 'address',
            'label' => 'Customer Address',
            'type' => 'string',
            'searchable' => true,
        ]);

        // ── Return request columns (drive Approve/Reject/Picked/QC buttons) ──
        $this->addColumn([
            'index' => 'return_status',
            'label' => 'Return Status',
            'type' => 'string',
            'filterable' => true,
            'closure' => function ($row) {
                return $row->return_status ?? '';
            },
        ]);

        $this->addColumn([
            'index' => 'return_request_id',
            'label' => 'Return Request ID',
            'type' => 'string',
            'exportable' => false,
            'closure' => function ($row) {
                return $row->return_request_id ?? '';
            },
        ]);
    }

    public function prepareActions()
    {
        $this->addAction([
            'title'  => 'Packed',
            'method' => 'POST',
            'url'    => function ($row) {
                return route('admin.ondc.orders.packed', $row->order_id);
            },
        ]);

        $this->addAction([
            'title'  => 'Agent Assign',
            'method' => 'POST',
            'url'    => function ($row) {
                return route('admin.ondc.orders.agent-assign', $row->order_id);
            },
        ]);

        $this->addAction([
            'title'  => 'Picked',
            'method' => 'POST',
            'url'    => function ($row) {
                return route('admin.ondc.orders.picked', $row->order_id);
            },
        ]);

        $this->addAction([
            'title'  => 'Out for Delivery',
            'method' => 'POST',
            'url'    => function ($row) {
                return route('admin.ondc.orders.out-for-delivery', $row->order_id);
            },
        ]);

        $this->addAction([
            'title'  => 'Order Delivered',
            'method' => 'POST',
            'url'    => function ($row) {
                return route('admin.ondc.orders.delivered', $row->order_id);
            },
        ]);

        // temporary
        // In prepareActions() — replace the RTO Delivered action with:
        $this->addAction([
            'title'  => 'RTO Delivered',
            'method' => 'POST',
            'url'    => function ($row) {
                if (! $row->has_active_rto) return null;
                return route('admin.ondc.orders.rto-delivered', $row->order_id);
            },
        ]);

        $this->addAction([
            'title'  => 'Cancel Order',
            'method' => 'POST',
            'url'    => function ($row) {
                return route('admin.ondc.orders.cancel', $row->order_id);
            },
        ]);

        $this->addAction([
            'title'  => 'Partial Cancel',
            'method' => 'POST',
            'url'    => function ($row) {
                return route('admin.ondc.orders.cancel.partial', $row->order_id);
            },
        ]);

        $this->addAction([
            'title'  => 'Cancel Data',
            'method' => 'GET',
            'url'    => function ($row) {
                return route('admin.ondc.orders.cancel-data', $row->order_id);
            },
        ]);

        // ── Return / Reverse-QC actions ──
        // Each URL is only built if a return request actually exists for
        // this row's order (return_request_db_id is non-null); otherwise
        // null is returned and the row's `actions` array simply won't
        // contain a usable URL for that title — the Blade view's `v-if`
        // on `record.return_status` is what actually keeps the button
        // from rendering, this null is just a safety net.
        $this->addAction([
            'title'  => 'Approve Return',
            'method' => 'POST',
            'url'    => function ($row) {
                if (! $row->return_request_db_id) return null;
                return route('admin.ondc.returns.approve', $row->return_request_db_id);
            },
        ]);

        $this->addAction([
            'title'  => 'Reject Return',
            'method' => 'POST',
            'url'    => function ($row) {
                if (! $row->return_request_db_id) return null;
                return route('admin.ondc.returns.reject', $row->return_request_db_id);
            },
        ]);

        $this->addAction([
            'title'  => 'Return Picked',
            'method' => 'POST',
            'url'    => function ($row) {
                if (! $row->return_request_db_id) return null;
                return route('admin.ondc.returns.picked', $row->return_request_db_id);
            },
        ]);

        $this->addAction([
            'title'  => 'QC Pass',
            'method' => 'POST',
            'url'    => function ($row) {
                if (! $row->return_request_db_id) return null;
                return route('admin.ondc.returns.qc-pass', $row->return_request_db_id);
            },
        ]);

        $this->addAction([
            'title'  => 'QC Fail',
            'method' => 'POST',
            'url'    => function ($row) {
                if (! $row->return_request_db_id) return null;
                return route('admin.ondc.returns.qc-fail', $row->return_request_db_id);
            },
        ]);
    }
}