<x-admin::layouts>
    @push('styles')
    <style>
        @media (min-width: 768px) {
            .ondc-product-grid .md\:grid-cols-\[2fr_1fr_1fr\] {
                grid-template-columns: 1fr 1fr 1fr;
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
                    <div class="row grid gap-2 md:grid-cols-[2fr_1fr_1fr] grid-rows-1 items-center border-b px-4 py-2.5 dark:border-gray-800">
                        <div
                            class="flex select-none items-center gap-2.5"
                            v-for="(columnGroup, index) in [
                                ['name', 'increment_id', 'sku'], 
                                ['base_image', 'price', 'quantity', 'product_id'], 
                                ['address', 'category_name', 'type'],
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
                        class="row border-b px-1 py-2.5 transition-all hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950 sm:px-2 md:grid md:grid-cols-[2fr_1fr_1fr] md:grid-rows-1 md:gap-0"
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
                                        <p class="text-xs text-gray-600 dark:text-gray-300 sm:text-sm">
                                            @{{ "@lang('admin::app.catalog.products.index.datagrid.id-value')".replace(':id', record.product_id) }}
                                        </p>
                                        <p class="text-xs text-gray-600 dark:text-gray-300 sm:text-sm">
                                            @{{ "@lang('admin::app.catalog.products.index.datagrid.sku-value')".replace(':sku', record.sku) }}
                                        </p>
                                        <p class="text-sm font-semibold text-gray-800 dark:text-white sm:text-base">
                                            @{{ $admin.formatPrice(record.price) }}
                                        </p>
                                        <p :class="[record.ondc_status_raw ? 'label-active': 'label-info']">
                                            @{{ record.ondc_status_raw ? "ONDC Product" : "Not ONDC Product" }}
                                        </p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-0">
                                    <span
                                        class="cursor-pointer rounded-md p-1.5 text-xl transition-all hover:bg-gray-200 dark:hover:bg-gray-800"
                                        :class="action.icon"
                                        v-text="! action.icon ? action.title : ''"
                                        v-for="action in record.actions"
                                        @click="performAction(action)">
                                    </span>
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
                                    </div>
                                </div>
                            </div>

                            <!-- Product Information Column 3 -->
                            <div class="flex items-center justify-start gap-x-4">
                                <div class="flex flex-col gap-1.5">
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
                                
                                <div class="flex flex-col items-start gap-2 px-1">
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                                        Order Status: @{{ record.order_status }}
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
                                                action.title === 'Order Delivered' ? 'bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-300' : ''
                                            ]"
                                        >
                                            @{{ action.title }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <!-- Buttons Column -->
                        </div>
                    </div>
                </template>
            </template>
        </x-admin::datagrid>
    </div>
</x-admin::layouts>