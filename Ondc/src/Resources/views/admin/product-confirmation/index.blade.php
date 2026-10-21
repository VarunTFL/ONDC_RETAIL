<x-admin::layouts>
    @push('styles')
    <style>
        @media (min-width: 768px) {
            .ondc-product-grid .md\:grid-cols-\[2fr_1fr_1fr_1fr\] {
                grid-template-columns: 1fr 1fr 1fr 1fr;
            }
        }
    </style>
    @endpush
    <x-slot:title>
        Product Confirmation
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">
            Product Confirmation
        </p>

        <div class="flex items-center gap-x-2.5">
            @if (bouncer()->hasPermission('catalog.products.create'))
            <v-create-product-button></v-create-product-button>
            @endif
        </div>
    </div>

    {!! view_render_event('bagisto.admin.catalog.products.list.before') !!}

    <div class="ondc-product-grid">
        <!-- Datagrid -->
        <x-admin::datagrid
            ref="datagrid"
            :src="route('admin.ondc.products.conformance.data')"
            :isMultiRow="true">
            <!-- Search Slot -->
            <template #search="{
                available,
                applied,
                search,
                getSearchedValues
            }">
                <div class="flex w-full items-center gap-x-2">
                    <div class="relative w-90">
                        <input
                            type="text"
                            ref="searchInput"
                            value=""
                            placeholder="Search ONDC Products..."
                            class="w-full border rounded-lg px-3 py-2 pr-10 focus:border-blue-500"
                            @keyup.enter="search({
                        columns: [{ index: 'all', value: [$event.target.value] }]
                    })">
                        <span
                            class="absolute right-2 top-1/2 -translate-y-1/2 cursor-pointer text-xl text-gray-500 hover:text-blue-600 icon-search"
                            @click="search({
                        columns: [{ index: 'all', value: [$refs.searchInput.value] }]
                    })">
                        </span>
                    </div>
                </div>
            </template>

            <!-- Datagrid Header -->
            @php
            $hasPermission = bouncer()->hasPermission('catalog.products.edit') || bouncer()->hasPermission('catalog.products.delete');
            @endphp

            <template #header="{
                isLoading,
                available,
                applied,
                selectAll,
                sort,
                performAction
            }">
                <template v-if="isLoading">
                    <x-admin::shimmer.datagrid.table.head :isMultiRow="true" />
                </template>

                <template v-else>
                    <div class="row grid gap-2 md:grid-cols-[2fr_1fr_1fr_1fr] grid-rows-1 items-center border-b px-4 py-2.5 dark:border-gray-800">
                        <div
                            class="flex select-none items-center gap-2.5"
                            v-for="(columnGroup, index) in [
                                ['name', 'increment_id', 'sku'], 
                                ['base_image', 'price', 'quantity', 'product_id'], 
                                ['address'],
                                ['actions']
                            ]">
                            <p class="text-gray-600 dark:text-gray-300">
                                <span class="[&>*]:after:content-['_/_']">
                                    <template v-for="column in columnGroup">
                                        <span
                                            class="after:content-['/'] last:after:content-['']"
                                            :class="{
                                                'font-medium text-gray-800 dark:text-white': applied.sort.column == column,
                                                'cursor-pointer hover:text-gray-800 dark:hover:text-white': available.columns.find(columnTemp => columnTemp.index === column)?.sortable,
                                            }"
                                            @click="
                                                available.columns.find(columnTemp => columnTemp.index === column)?.sortable ? sort(available.columns.find(columnTemp => columnTemp.index === column)): {}
                                            ">
                                            @{{ available.columns.find(columnTemp => columnTemp.index === column)?.label }}
                                        </span>
                                    </template>
                                </span>

                                <i
                                    class="align-text-bottom text-base text-gray-800 dark:text-white ltr:ml-1.5 rtl:mr-1.5"
                                    :class="[applied.sort.order === 'asc' ? 'icon-down-stat': 'icon-up-stat']"
                                    v-if="columnGroup.includes(applied.sort.column)">
                                </i>
                            </p>
                        </div>
                    </div>
                </template>
            </template>

            <!-- Datagrid Body -->
            <template #body="{
                isLoading,
                available,
                applied,
                selectAll,
                sort,
                performAction
            }">
                <template v-if="isLoading">
                    <x-admin::shimmer.datagrid.table.body :isMultiRow="true" />
                </template>

                <template v-else>
                    <div
                        class="row border-b px-1 py-2.5 transition-all hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950 sm:px-2 md:grid md:grid-cols-[2fr_1fr_1fr_1fr] md:grid-rows-1 md:gap-0"
                        v-for="record in available.records"
                    >
                        <!-- Mobile Layout -->
                        <div class="block space-y-3 md:hidden">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-start gap-2.5">
                                    <!-- Rest of mobile content (same as before) -->
                                    <div class="relative flex-shrink-0">
                                        <template v-if="record.base_image">
                                            <img
                                                class="h-12 w-12 rounded object-cover sm:h-16 sm:w-16"
                                                :src='record.base_image' />
                                            <span class="absolute -bottom-1 -right-1 rounded-full bg-darkPink px-1 text-xs font-bold leading-normal text-white">
                                                @{{ record.images_count }}
                                            </span>
                                        </template>
                                        <template v-else>
                                            <div class="relative h-12 w-12 rounded border border-dashed border-gray-300 dark:border-gray-800 dark:mix-blend-exclusion dark:invert sm:h-16 sm:w-16">
                                                <img src="{{ bagisto_asset('images/product-placeholders/front.svg')}}" class="h-full w-full object-cover">
                                                <p class="absolute bottom-0 w-full text-center text-[6px] font-semibold text-gray-400">
                                                    @lang('admin::app.catalog.products.index.datagrid.product-image')
                                                </p>
                                            </div>
                                        </template>
                                    </div>

                                    <div class="flex flex-col gap-0 flex-1">
                                        <p class="break-all text-sm font-semibold text-gray-800 dark:text-white sm:text-base">
                                            @{{ record.name }}
                                        </p>
                                        <p class="text-gray-600 dark:text-gray-300">
                                            Order Id: @{{ record.increment_id }}
                                        </p>
                                        <p class="text-gray-600 dark:text-gray-300">
                                            SKU: @{{ record.sku || "" }}
                                        </p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-0">
                                    <div class="flex flex-col items-start gap-2 px-1">
                                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                                            Order Status: <span class="uppercase font-medium">@{{ record.order_status }}</span>
                                        </p>

                                        <div class="flex flex-wrap gap-2.5">
                                            <a
                                                v-for="action in record.actions"
                                                :key="action.title"
                                                :href="action.url"
                                                class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium transition"
                                                :class="[
                                                    action.title === 'Packed' ? 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200 dark:bg-yellow-900/30 dark:text-yellow-300' : '',
                                                    action.title === 'Agent Assign' ? 'bg-blue-100 text-blue-700 hover:bg-blue-200 dark:bg-blue-900/30 dark:text-blue-300' : '',
                                                    action.title === 'Picked' ? 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200 dark:bg-yellow-900/30 dark:text-yellow-300' : '',
                                                    action.title === 'Out for Delivery' ? 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200 dark:bg-yellow-900/30 dark:text-yellow-300' : '',
                                                    action.title === 'Order Delivered' ? 'bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-300' : '',
                                                    action.title === 'RTO Delivered' ? 'bg-purple-100 text-purple-700 hover:bg-purple-200 dark:bg-purple-900/30 dark:text-purple-300' : ''
                                                ]"
                                            >
                                                @{{ action.title }}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Desktop Layout -->
                        <div class="hidden md:contents">
                            <!-- Product Information Column 1 -->
                            <div class="flex gap-0">
                                <div class="flex flex-col gap-1.5">
                                    <p class="break-all text-base font-semibold text-gray-800 dark:text-white">
                                        @{{ record.name }}
                                    </p>
                                    <p class="text-gray-600 dark:text-gray-300">
                                        Order Id: @{{ record.increment_id }}
                                    </p>
                                    <p class="text-gray-600 dark:text-gray-300">
                                        SKU: @{{ record.sku || "" }}
                                    </p>
                                </div>
                            </div>

                            <!-- Product Information Column 2 -->
                            <!-- Rest of desktop content (same as before) -->
                            <div class="flex gap-2">
                                <div class="relative">
                                    <template v-if="record.base_image">
                                        <img
                                            class="max-h-[65px] min-h-[65px] min-w-[65px] max-w-[65px] rounded"
                                            :src='record.base_image' />
                                        <span class="absolute bottom-px rounded-full bg-darkPink px-1.5 text-xs font-bold leading-normal text-white ltr:left-px rtl:right-px">
                                            @{{ record.images_count }}
                                        </span>
                                    </template>
                                    <template v-else>
                                        <div class="relative h-[60px] max-h-[60px] w-full max-w-[60px] rounded border border-dashed border-gray-300 dark:border-gray-800 dark:mix-blend-exclusion dark:invert">
                                            <img src="{{ bagisto_asset('images/product-placeholders/front.svg')}}">
                                            <p class="absolute bottom-1.5 w-full text-center text-[6px] font-semibold text-gray-400">
                                                @lang('admin::app.catalog.products.index.datagrid.product-image')
                                            </p>
                                        </div>
                                    </template>
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <p class="text-base font-semibold text-gray-800 dark:text-white">
                                        @{{ $admin.formatPrice(record.price) }}
                                    </p>
                                    <div v-if="['configurable', 'bundle', 'grouped', 'booking'].includes(record.type)">
                                        <p class="text-gray-600 dark:text-gray-300">
                                            <span class="text-red-600">N/A</span>
                                        </p>
                                    </div>
                                    <div v-else>
                                        <p class="text-gray-600 dark:text-gray-300" v-if="record.quantity > 0">
                                            <span class="text-green-600">
                                                Qty: @{{ record.quantity }}
                                            </span>
                                        </p>
                                        <p class="text-gray-600 dark:text-gray-300" v-else>
                                            <span class="text-red-600">
                                                @lang('admin::app.catalog.products.index.datagrid.out-of-stock')
                                            </span>
                                        </p>
                                        <p class="text-gray-600 dark:text-gray-300" v-if="record.payment_status">
                                            <span class="">
                                                Payment Status: @{{ record.payment_status }}
                                            </span>
                                        </p>
                                        <p class="text-gray-600 dark:text-gray-300" v-if="record.payment_status">
                                            <span class="uppercase font-medium">
                                                Order Status: @{{ record.order_status }}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Product Information Column 3 -->
                            <div class="flex gap-2">
                                <div class="flex flex-col gap-0.5">
                                    <p class="text-base font-semibold text-gray-800 dark:text-white" v-if="record.first_name">
                                        @{{ record.first_name ?? 'N/A' }}
                                    </p>
                                    <p class="text-gray-600 dark:text-gray-300" v-if="record.payment_status">
                                        <span>
                                            @{{ record.address ?? 'N/A' }}, <br>
                                            Phone: @{{ record.phone ?? 'N/A' }} <br>
                                            Email: @{{ record.email ?? 'N/A' }} <br>
                                        </span>
                                    </p>
                                </div>
                            </div>

                            <!-- Buttons Column -->
                            <div class="flex items-left gap-x-4">
                                <div class="flex flex-col items-start gap-2 px-1">
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                                        Order Status: <span class="uppercase font-medium">@{{ record.order_status }}</span>
                                    </p>

                                    <div class="flex flex-wrap gap-2.5">
                                        <!-- Packed -->
                                        <form
                                            v-if="record.order_status === 'Pending' && record.actions?.some(action => action.title === 'Packed')"
                                            :action="record.actions.find(action => action.title === 'Packed')?.url"
                                            method="POST"
                                            style="display:inline"
                                        >
                                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                            <button type="submit" class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-blue-100 text-blue-700 hover:bg-blue-200">
                                                Packed
                                            </button>
                                        </form>
                                        <!-- Agent Assign -->
                                        <form
                                            v-if="record.order_status === 'Packed' && record.actions?.some(action => action.title === 'Agent Assign')"
                                            :action="record.actions.find(action => action.title === 'Agent Assign')?.url"
                                            method="POST"
                                            style="display:inline"
                                        >
                                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                            <button type="submit" class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-blue-100 text-blue-700 hover:bg-blue-200">
                                                Agent Assign
                                            </button>
                                        </form>

                                        <!-- Picked -->
                                        <form
                                            v-if="record.order_status === 'Agent-assigned' && record.actions?.some(action => action.title === 'Picked')"
                                            :action="record.actions.find(action => action.title === 'Picked')?.url"
                                            method="POST"
                                            style="display:inline"
                                        >
                                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                            <button type="submit" class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-blue-100 text-blue-700 hover:bg-blue-200">
                                                Picked
                                            </button>
                                        </form>

                                        <!-- Out for Delivery -->
                                        <form
                                            v-if="record.order_status === 'Order-picked-up' && record.actions?.some(action => action.title === 'Out for Delivery')"
                                            :action="record.actions.find(action => action.title === 'Out for Delivery')?.url"
                                            method="POST"
                                            style="display:inline"
                                        >
                                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                            <button type="submit" class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-blue-100 text-blue-700 hover:bg-blue-200">
                                                Out for Delivery
                                            </button>
                                        </form>

                                        <!-- Order Delivered -->
                                     <!-- {{-- find this block: --}} -->
                                        <form
                                            v-if="record.order_status === 'Out-for-delivery' && record.actions?.some(action => action.title === 'Order Delivered')"
                                            :action="record.actions.find(action => action.title === 'Order Delivered')?.url"
                                            method="POST"
                                            style="display:inline"
                                        >
                                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                            <button type="submit" class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-blue-100 text-blue-700 hover:bg-blue-200">
                                                Order Delivered
                                            </button>

                                            <!-- {{-- ADD THE CLOSING </form> TAG HERE — it is currently missing --}} -->
                                        </form>

                                        <!-- {{-- RTO Delivered — add this entire block --}} -->
                                        <form
                                            v-if="record.order_status !== 'RTO-Delivered'"
                                            :action="record.actions.find(action => action.title === 'RTO Delivered')?.url"
                                            method="POST"
                                            style="display:inline"
                                        >
                                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                            <button type="submit" class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-red-100 text-red-700 hover:bg-red-200">
                                                RTO Delivered
                                            </button>
                                        </form>


                                        <!-- Cancel Order (full) -->
                                        <!-- NOTE: Vue 3 templates don't expose `window` (not in globals allowlist),
                                             so we serialize `record` to a data-attribute and use a plain HTML
                                             onclick handler, which runs in global scope and can see window.* -->
                                        <button
                                            v-if="!['canceled','Cancelled','Order-delivered','RTO-Delivered'].includes(record.order_status)"
                                            type="button"
                                            class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-red-100 text-red-700 hover:bg-red-200"
                                            :data-ondc-record="JSON.stringify(record)"
                                            onclick="window.openOndcCancelModal(JSON.parse(this.dataset.ondcRecord), 'full')"
                                        >
                                            Cancel Order
                                        </button>

                                        <!-- Partial Cancel -->
                                        <button
                                            v-if="!['canceled','Cancelled','Order-delivered','RTO-Delivered'].includes(record.order_status)"
                                            type="button"
                                            class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-orange-100 text-orange-700 hover:bg-orange-200"
                                            :data-ondc-record="JSON.stringify(record)"
                                            onclick="window.openOndcCancelModal(JSON.parse(this.dataset.ondcRecord), 'partial')"
                                        >
                                            Partial Cancel
                                        </button>



                                        <!-- Approve Return -->
<form
    v-if="record.return_status === 'REQUESTED'"
    :action="record.actions.find(action => action.title === 'Approve Return')?.url"
    method="POST"
    style="display:inline"
>
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
    <button type="submit" class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-green-100 text-green-700 hover:bg-green-200">
        Approve Return
    </button>
</form>

<!-- Reject Return -->
<form
    v-if="record.return_status === 'REQUESTED'"
    :action="record.actions.find(action => action.title === 'Reject Return')?.url"
    method="POST"
    style="display:inline"
>
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
    <button type="submit" class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-red-100 text-red-700 hover:bg-red-200">
        Reject
    </button>
</form>

<!-- Mark Picked (reverse logistics picked item up) -->
<form
    v-if="record.return_status === 'APPROVED'"
    :action="record.actions.find(action => action.title === 'Return Picked')?.url"
    method="POST"
    style="display:inline"
>
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
    <button type="submit" class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-yellow-100 text-yellow-700 hover:bg-yellow-200">
        Return Picked
    </button>
</form>

<!-- QC Pass -->
<form
    v-if="['APPROVED','PICKUP_INITIATED','QC_IN_PROGRESS'].includes(record.return_status)"
    :action="record.actions.find(action => action.title === 'QC Pass')?.url"
    method="POST"
    style="display:inline"
>
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
    <button type="submit" class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-green-100 text-green-700 hover:bg-green-200">
        QC Pass
    </button>
</form>

<!-- QC Fail -->
<form
    v-if="['APPROVED','PICKUP_INITIATED','QC_IN_PROGRESS'].includes(record.return_status)"
    :action="record.actions.find(action => action.title === 'QC Fail')?.url"
    method="POST"
    style="display:inline"
>
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
    <button type="submit" class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium bg-red-100 text-red-700 hover:bg-red-200">
        QC Fail
    </button>
</form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </template>
        </x-admin::datagrid>
    </div>

    {{-- ════════════════════════════════════════════════════════ --}}
    {{--   CANCEL MODAL — Full + Partial Cancellation             --}}
    {{-- ════════════════════════════════════════════════════════ --}}
    {{-- Inline styles below: Tailwind JIT doesn't scan Webkul package views,
         so utility classes like bg-white / max-w-3xl / bg-opacity-50 may be
         missing from the built CSS. Inline styles bypass that completely. --}}
    <div id="ondc-cancel-modal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.2); width:100%; max-width:768px; margin:0 16px; max-height:90vh; overflow-y:auto;">
            <div style="display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #e5e7eb; padding:16px 24px;">
                <h3 style="font-size:18px; font-weight:700; color:#1f2937; margin:0;" id="ondc-cancel-modal-title">Cancel Order</h3>
                <button type="button" style="background:none; border:none; color:#6b7280; cursor:pointer;" onclick="window.closeOndcCancelModal()">
                    <span style="font-size:24px; line-height:1;">&times;</span>
                </button>
            </div>

            <form id="ondc-cancel-form" method="POST" style="padding:24px;">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">

                <div id="ondc-cancel-loading" style="text-align:center; padding:32px 0; color:#6b7280;">Loading order details…</div>

                <div id="ondc-cancel-content" style="display:none;">
                    <div style="margin-bottom:16px; padding:12px; background:#f9fafb; border-radius:6px;">
                        <p style="font-size:14px; color:#4b5563; margin:0 0 4px 0;">Order ID: <strong id="ondc-cancel-incrementid" style="color:#111827;"></strong></p>
                        <p style="font-size:14px; color:#4b5563; margin:0;">Current Status: <strong id="ondc-cancel-currentstatus" style="color:#111827;"></strong></p>
                    </div>

                    {{-- Items table (only for partial cancel) --}}
                    <div id="ondc-cancel-items-section" style="display:none; margin-bottom:16px;">
                        <h4 style="font-weight:600; margin:0 0 8px 0; color:#1f2937;">Select items + quantities to cancel</h4>
                        <div style="overflow-x:auto;">
                            <table style="width:100%; margin-bottom:16px; border:1px solid #e5e7eb; font-size:14px; border-collapse:collapse;">
                                <thead style="background:#f3f4f6;">
                                    <tr>
                                        <th style="padding:8px; text-align:left; font-size:12px; font-weight:500;">Cancel</th>
                                        <th style="padding:8px; text-align:left; font-size:12px; font-weight:500;">Item</th>
                                        <th style="padding:8px; text-align:left; font-size:12px; font-weight:500;">SKU</th>
                                        <th style="padding:8px; text-align:left; font-size:12px; font-weight:500;">Ordered</th>
                                        <th style="padding:8px; text-align:left; font-size:12px; font-weight:500;">Cancelled</th>
                                        <th style="padding:8px; text-align:left; font-size:12px; font-weight:500;">Qty</th>
                                        <th style="padding:8px; text-align:left; font-size:12px; font-weight:500;">Refund (₹)</th>
                                    </tr>
                                </thead>
                                <tbody id="ondc-cancel-items-body"></tbody>
                            </table>
                        </div>
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:14px; font-weight:500; color:#374151; margin-bottom:4px;">Reason <span style="color:#ef4444;">*</span></label>
                        <select id="ondc-cancel-reason" name="reason_id" style="width:100%; border:1px solid #d1d5db; border-radius:6px; padding:8px 12px; background:#fff; color:#111827;" required></select>
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:14px; font-weight:500; color:#374151; margin-bottom:4px;">Note (optional)</label>
                        <textarea id="ondc-cancel-note" name="note" rows="2" maxlength="500" style="width:100%; border:1px solid #d1d5db; border-radius:6px; padding:8px 12px;" placeholder="Optional internal note"></textarea>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:8px; padding-top:16px; border-top:1px solid #e5e7eb;">
                        <button type="button" onclick="window.closeOndcCancelModal()" style="padding:8px 16px; border-radius:6px; background:#e5e7eb; color:#1f2937; border:none; cursor:pointer;">Cancel</button>
                        <button type="submit" id="ondc-cancel-submit" style="padding:8px 16px; border-radius:6px; background:#dc2626; color:#fff; border:none; cursor:pointer;">Confirm</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    @push('scripts')
    <script>
        // Define these IMMEDIATELY so Vue can find them right away
        window.openOndcCancelModal = function() {};
        window.closeOndcCancelModal = function() {};

        // Run init as soon as DOM is ready. Bagisto admin pushes scripts at the
        // bottom of <body>, so DOMContentLoaded has often already fired by the
        // time this script runs — in that case attach immediately.
        function __ondcCancelInit() {
            const CSRF = '{{ csrf_token() }}';
            const modal = () => document.getElementById('ondc-cancel-modal');
            const titleEl = () => document.getElementById('ondc-cancel-modal-title');
            const formEl = () => document.getElementById('ondc-cancel-form');
            const loadingEl = () => document.getElementById('ondc-cancel-loading');
            const contentEl = () => document.getElementById('ondc-cancel-content');
            const reasonEl = () => document.getElementById('ondc-cancel-reason');
            const noteEl = () => document.getElementById('ondc-cancel-note');
            const itemsSec = () => document.getElementById('ondc-cancel-items-section');
            const itemsBody = () => document.getElementById('ondc-cancel-items-body');
            const incrEl = () => document.getElementById('ondc-cancel-incrementid');
            const statusEl = () => document.getElementById('ondc-cancel-currentstatus');
            const submitBtn = () => document.getElementById('ondc-cancel-submit');

            let currentMode = 'full';
            let currentOrder = null;
            let cancelData = null;

            function escapeHtml(s) {
                return String(s ?? '').replace(/[&<>"']/g, c => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
                }[c]));
            }

            function appendHidden(form, name, value) {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = name;
                inp.value = value;
                inp.setAttribute('data-dynamic', '1');
                form.appendChild(inp);
            }

            function renderModal() {
                loadingEl().style.display = 'none';
                contentEl().style.display = 'block';

                incrEl().innerText = cancelData.increment_id;
                statusEl().innerText = cancelData.order_status;

                const fullUrl = currentOrder.actions.find(a => a.title === 'Cancel Order')?.url;
                const partialUrl = currentOrder.actions.find(a => a.title === 'Partial Cancel')?.url;
                formEl().action = currentMode === 'full' ? fullUrl : partialUrl;

                const reasons = currentMode === 'full' ? cancelData.reasons_full : cancelData.reasons_partial;
                reasonEl().innerHTML = reasons.map(r => {
                    // temporary
                    // const rto = r.triggers_rto ? ' (triggers RTO)' : '';
                    // return `<option value="${r.code}">${r.code} — ${r.reason}${rto}</option>`;
                    const rto = r.triggers_rto ? ' ✅ triggers RTO' : '';
                    return `<option value="${r.code}" ${r.triggers_rto ? 'style="font-weight:bold;color:green"' : ''}>${r.code} — ${r.reason}${rto}</option>`;
                }).join('');

                if (currentMode === 'partial') {
                    itemsSec().style.display = 'block';
                    itemsBody().innerHTML = cancelData.items.map(item => {
                        const remaining = (item.qty_ordered || 0) - (item.qty_canceled || 0);
                        const refund = ((item.total || 0) / Math.max(1, item.qty_ordered)) || 0;
                        return `
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-2 py-2"><input type="checkbox" class="ondc-item-check" data-row="${item.id}" ${remaining > 0 ? '' : 'disabled'}></td>
                                <td class="px-2 py-2 text-gray-800 dark:text-white">${escapeHtml(item.name)}</td>
                                <td class="px-2 py-2 text-gray-600 dark:text-gray-300">${escapeHtml(item.sku ?? '')}</td>
                                <td class="px-2 py-2">${item.qty_ordered}</td>
                                <td class="px-2 py-2">${item.qty_canceled || 0}</td>
                                <td class="px-2 py-2">
                                    <input type="number" class="ondc-item-qty w-20 border rounded px-2 py-1 dark:bg-gray-800 dark:text-white" min="1" max="${remaining}" value="${remaining}" data-row="${item.id}" ${remaining > 0 ? '' : 'disabled'}>
                                </td>
                                <td class="px-2 py-2 text-gray-600 dark:text-gray-300">₹<span data-refund-row="${item.id}">${(refund * remaining).toFixed(2)}</span></td>
                                <input type="hidden" data-row-itemid="${item.id}" value="${escapeHtml(item.sku ?? item.product_id)}">
                                <input type="hidden" data-row-orig="${item.id}" value="${item.qty_ordered}">
                                <input type="hidden" data-row-price="${item.id}" value="${refund.toFixed(2)}">
                            </tr>
                        `;
                    }).join('');

                    itemsBody().querySelectorAll('.ondc-item-qty').forEach(inp => {
                        inp.addEventListener('input', () => {
                            const row = inp.dataset.row;
                            const price = parseFloat(itemsBody().querySelector(`[data-row-price="${row}"]`).value);
                            const qty = parseInt(inp.value || '0', 10);
                            itemsBody().querySelector(`[data-refund-row="${row}"]`).innerText = (price * qty).toFixed(2);
                        });
                    });
                } else {
                    itemsSec().style.display = 'none';
                }

                formEl().onsubmit = onSubmit;
            }

            async function onSubmit(e) {
                if (currentMode === 'partial') {
                    formEl().querySelectorAll('input[data-dynamic]').forEach(n => n.remove());

                    const checks = itemsBody().querySelectorAll('.ondc-item-check:checked');
                    if (checks.length === 0) {
                        e.preventDefault();
                        alert('Select at least one item to partial-cancel.');
                        return false;
                    }

                    let idx = 0;
                    checks.forEach(check => {
                        const row = check.dataset.row;
                        const itemId = itemsBody().querySelector(`[data-row-itemid="${row}"]`).value;
                        const orig = itemsBody().querySelector(`[data-row-orig="${row}"]`).value;
                        const price = itemsBody().querySelector(`[data-row-price="${row}"]`).value;
                        const qty = itemsBody().querySelector(`[data-row="${row}"].ondc-item-qty`).value;

                        if (parseInt(qty, 10) > 0) {
                            appendHidden(formEl(), `items[${idx}][item_id]`, itemId);
                            appendHidden(formEl(), `items[${idx}][original_quantity]`, orig);
                            appendHidden(formEl(), `items[${idx}][cancelled_quantity]`, qty);
                            appendHidden(formEl(), `items[${idx}][item_price]`, price);
                            idx++;
                        }
                    });

                    if (idx === 0) {
                        e.preventDefault();
                        alert('Set quantity > 0 on selected items.');
                        return false;
                    }
                }

                submitBtn().innerText = 'Cancelling…';
                submitBtn().disabled = true;
            }

            // NOW replace the placeholder with the real function
            window.openOndcCancelModal = async function(record, mode) {
                currentMode = mode;
                currentOrder = record;

                modal().style.display = 'flex';
                loadingEl().style.display = 'block';
                contentEl().style.display = 'none';
                titleEl().innerText = mode === 'full' ? 'Cancel Order (Full)' : 'Partial Cancellation';

                try {
                    const action = record.actions.find(a => a.title === 'Cancel Data');
                    if (!action) {
                        alert('Cancel data action not available for this order. Try refreshing.');
                        window.closeOndcCancelModal();
                        return;
                    }
                    const resp = await fetch(action.url, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const json = await resp.json();
                    if (!json.success) throw new Error(json.error || 'failed to load');
                    cancelData = json.data;
                    // DEBUG: Log all data
                    console.log('=== CANCEL DATA LOADED ===');
                    console.log('Order ID:', cancelData.increment_id);
                    console.log('reasons_full:', cancelData.reasons_full);
                    console.log('reasons_partial:', cancelData.reasons_partial);
                    console.log('Full reasons count:', cancelData.reasons_full ? cancelData.reasons_full.length : 0);
                    console.log('Partial reasons count:', cancelData.reasons_partial ? cancelData.reasons_partial.length : 0);
                    console.log('Full Data:', cancelData);
                    console.log('=========================');
                    
                    renderModal();
                } catch (e) {
                    alert('Could not load order details: ' + e.message);
                    window.closeOndcCancelModal();
                }
            };

            window.closeOndcCancelModal = function() {
                modal().style.display = 'none';
                cancelData = null;
            };
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', __ondcCancelInit);
        } else {
            __ondcCancelInit();
        }
    </script>
    @endpush
</x-admin::layouts>