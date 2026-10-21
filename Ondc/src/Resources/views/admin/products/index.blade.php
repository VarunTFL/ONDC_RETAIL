<x-admin::layouts>
    <x-slot:title>
        Ondc Product
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">
            Ondc Product
        </p>

        <div class="flex items-center gap-x-2.5">
            @if (bouncer()->hasPermission('catalog.products.create'))
                <v-create-product-button></v-create-product-button>
            @endif
        </div>
    </div>

    {!! view_render_event('bagisto.admin.catalog.products.list.before') !!}

    <!-- Datagrid -->
    <x-admin::datagrid
        ref="datagrid"
        :src="route('admin.ondc.products.index')"
        :isMultiRow="true"
    >
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
                        })"
                    >
                    <span
                        class="absolute right-2 top-1/2 -translate-y-1/2 cursor-pointer text-xl text-gray-500 hover:text-blue-600 icon-search"
                        @click="search({
                            columns: [{ index: 'all', value: [$refs.searchInput.value] }]
                        })"
                    >
                    </span>
                </div>
            </div>
        </template>
        
        <!-- Row Checkbox Template - FIXED -->
        <template #row="{ record, applied }">
            <td>
                <input
                    type="checkbox"
                    :id="`product_${record.product_id}`"
                    :value="record.product_id"
                    class="peer hidden"
                    v-model="applied.massActions.indices"
                    :checked="record.ondc_status_raw == 1 || applied.massActions.indices.includes(record.product_id)"
                    :disabled="record.ondc_status_raw == 1"
                >
                <label
                    :for="`product_${record.product_id}`"
                    class="cursor-pointer rounded-md text-2xl"
                    :class="{
                        'icon-checked text-blue-600': record.ondc_status_raw == 1 || applied.massActions.indices.includes(record.product_id),
                        'icon-uncheckbox peer-checked:icon-checked peer-checked:text-blue-600': record.ondc_status_raw != 1 && !applied.massActions.indices.includes(record.product_id)
                    }"
                ></label>
            </td>
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
                        v-for="(columnGroup, index) in [['name', 'sku', 'attribute_family'], ['base_image', 'price', 'quantity', 'product_id'], ['status', 'category_name', 'type']]"
                    >
                        @if ($hasPermission)
                            <label
                                class="flex w-max cursor-pointer select-none items-center gap-1"
                                for="mass_action_select_all_records"
                                v-if="! index"
                            >
                                <input
                                    type="checkbox"
                                    name="mass_action_select_all_records"
                                    id="mass_action_select_all_records"
                                    class="peer hidden"
                                    :checked="['all', 'partial'].includes(applied.massActions.meta.mode)"
                                    @change="selectAll"
                                >

                                <span
                                    class="icon-uncheckbox cursor-pointer rounded-md text-2xl"
                                    :class="[
                                        applied.massActions.meta.mode === 'all' ? 'peer-checked:icon-checked peer-checked:text-blue-600' : (
                                            applied.massActions.meta.mode === 'partial' ? 'peer-checked:icon-checkbox-partial peer-checked:text-blue-600' : ''
                                        ),
                                    ]"
                                >
                                </span>
                            </label>
                        @endif

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
                                        "
                                    >
                                        @{{ available.columns.find(columnTemp => columnTemp.index === column)?.label }}
                                    </span>
                                </template>
                            </span>

                            <i
                                class="align-text-bottom text-base text-gray-800 dark:text-white ltr:ml-1.5 rtl:mr-1.5"
                                :class="[applied.sort.order === 'asc' ? 'icon-down-stat': 'icon-up-stat']"
                                v-if="columnGroup.includes(applied.sort.column)"
                            ></i>
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
                    class="row border-b px-2 py-2.5 transition-all hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950 sm:px-4 md:grid md:grid-cols-[2fr_1fr_1fr] md:grid-rows-1 md:gap-1.5"
                    v-for="record in available.records"
                >
                    <!-- Mobile Layout -->
                    <div class="block space-y-3 md:hidden">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-2.5">
                                @if ($hasPermission)
                                    <input
                                        type="checkbox"
                                        :id="`mass_action_select_record_${record.product_id}`"
                                        :value="record.product_id"
                                        class="peer hidden"
                                        v-model="applied.massActions.indices"
                                        :checked="record.ondc_status_raw == 1 || applied.massActions.indices.includes(record.product_id)"
                                        :disabled="record.ondc_status_raw == 1"
                                    >
                                    <label
                                        class="cursor-pointer rounded-md text-2xl"
                                        :for="`mass_action_select_record_${record.product_id}`"
                                        :class="{
                                            'icon-checked text-blue-600': record.ondc_status_raw == 1 || applied.massActions.indices.includes(record.product_id),
                                            'icon-uncheckbox peer-checked:icon-checked peer-checked:text-blue-600': record.ondc_status_raw != 1 && !applied.massActions.indices.includes(record.product_id)
                                        }"
                                    ></label>
                                @endif

                                <!-- Rest of mobile content (same as before) -->
                                <div class="relative flex-shrink-0">
                                    <template v-if="record.base_image">
                                        <img
                                            class="h-12 w-12 rounded object-cover sm:h-16 sm:w-16"
                                            :src='record.base_image'
                                        />
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

                                <div class="flex flex-col gap-1 flex-1">
                                    <p class="break-all text-sm font-semibold text-gray-800 dark:text-white sm:text-base">
                                        @{{ record.name }}
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
                                    <!-- <p :class="[record.status ? 'label-active': 'label-info']">
                                        @{{ record.status ? "@lang('admin::app.catalog.products.index.datagrid.active')" : "@lang('admin::app.catalog.products.index.datagrid.disable')" }}
                                    </p> -->
                                    <p :class="[record.ondc_status_raw ? 'label-active': 'label-info']">
                                        @{{ record.ondc_status_raw ? "ONDC Product" : "Not ONDC Product" }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-1">
                                <span
                                    class="cursor-pointer rounded-md p-1.5 text-xl transition-all hover:bg-gray-200 dark:hover:bg-gray-800"
                                    :class="action.icon"
                                    v-text="! action.icon ? action.title : ''"
                                    v-for="action in record.actions"
                                    @click="performAction(action)"
                                >
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Desktop Layout -->
                    <div class="hidden md:contents">
                        <div class="flex gap-1.5">
                            @if ($hasPermission)
                                <input
                                    type="checkbox"
                                    :name="`mass_action_select_record_${record.product_id}`"
                                    :id="`mass_action_select_record_${record.product_id}`"
                                    :value="record.product_id"
                                    class="peer hidden"
                                    v-model="applied.massActions.indices"
                                    :checked="record.ondc_status_raw == 1 || applied.massActions.indices.includes(record.product_id)"
                                    :disabled="record.ondc_status_raw == 1"
                                >
                                <label
                                    class="cursor-pointer rounded-md text-2xl"
                                    :for="`mass_action_select_record_${record.product_id}`"
                                    :class="{
                                        'icon-checked text-blue-600': record.ondc_status_raw == 1 || applied.massActions.indices.includes(record.product_id),
                                        'icon-uncheckbox peer-checked:icon-checked peer-checked:text-blue-600': record.ondc_status_raw != 1 && !applied.massActions.indices.includes(record.product_id)
                                    }"
                                ></label>
                            @endif

                            <div class="flex flex-col gap-1.5">
                                <p class="break-all text-base font-semibold text-gray-800 dark:text-white">
                                    @{{ record.name }}
                                </p>
                                <p class="text-gray-600 dark:text-gray-300">
                                    @{{ "@lang('admin::app.catalog.products.index.datagrid.sku-value')".replace(':sku', record.sku) }}
                                </p>
                                <p class="text-gray-600 dark:text-gray-300">
                                    @{{ "@lang('admin::app.catalog.products.index.datagrid.attribute-family-value')".replace(':attribute_family', record.attribute_family) }}
                                </p>
                            </div>
                        </div>

                        <!-- Rest of desktop content (same as before) -->
                        <div class="flex gap-1.5">
                            <div class="relative">
                                <template v-if="record.base_image">
                                    <img
                                        class="max-h-[65px] min-h-[65px] min-w-[65px] max-w-[65px] rounded"
                                        :src='record.base_image'
                                    />
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
                                            @{{ "@lang('admin::app.catalog.products.index.datagrid.qty-value')".replace(':qty', record.quantity) }}
                                        </span>
                                    </p>
                                    <p class="text-gray-600 dark:text-gray-300" v-else>
                                        <span class="text-red-600">
                                            @lang('admin::app.catalog.products.index.datagrid.out-of-stock')
                                        </span>
                                    </p>
                                </div>
                                <p class="text-gray-600 dark:text-gray-300">
                                    @{{ "@lang('admin::app.catalog.products.index.datagrid.id-value')".replace(':id', record.product_id) }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-x-4">
                            <div class="flex flex-col gap-1.5">
                                <p :class="[record.ondc_status_raw ? 'label-active': 'label-info']">
                                    @{{ record.ondc_status_raw ? "ONDC Product" : "Not ONDC Product" }}
                                </p>
                                <!-- <p :class="[record.status ? 'label-active': 'label-info']">
                                    @{{ record.status ? "@lang('admin::app.catalog.products.index.datagrid.active')" : "@lang('admin::app.catalog.products.index.datagrid.disable')" }}
                                </p> -->
                                <p class="text-gray-600 dark:text-gray-300">
                                    @{{ record.category_name ?? 'N/A' }}
                                </p>
                                <p class="text-gray-600 dark:text-gray-300">
                                    @{{ record.type }}
                                </p>
                            </div>

                            <p class="flex items-center gap-1.5" v-if="available.actions.length">
                                <span
                                    class="cursor-pointer rounded-md p-1.5 text-2xl transition-all hover:bg-gray-200 dark:hover:bg-gray-800 max-sm:place-self-center"
                                    :class="action.icon"
                                    v-text="! action.icon ? action.title : ''"
                                    v-for="action in record.actions"
                                    @click="performAction(action)"
                                >
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
            </template>
        </template>
    </x-admin::datagrid>

    {!! view_render_event('bagisto.admin.catalog.products.list.after') !!}

    @pushOnce('scripts')
    <script type="module">
        const savedProductIds = @json($savedProductIds ?? []).map(id => Number(id));

        app.component('v-create-product-button', {
            template: `
                <button type="button" class="primary-button" @click="saveSelectedProducts">
                    Create ONDC Product
                </button>
            `,
            data() {
                return {
                    savedProductIds: savedProductIds
                };
            },
            mounted() {
                this.waitForDatagrid();
            },
            methods: {
                isProductSaved(productId) {
                    return this.savedProductIds.includes(Number(productId));
                },

                waitForDatagrid() {
                    let attempts = 0;
                    const maxAttempts = 50;
                    
                    const check = () => {
                        attempts++;
                        const datagrid = this.$root.$refs.datagrid;
                        
                        if (!datagrid) {
                            if (attempts < maxAttempts) {
                                setTimeout(check, 200);
                            }
                            return;
                        }
                        
                        if (!datagrid.available?.records || datagrid.available.records.length === 0) {
                            if (attempts < maxAttempts) {
                                setTimeout(check, 200);
                            }
                            return;
                        }
                        
                        // SUCCESS - datagrid loaded with records
                        const firstRecord = datagrid.available.records[0];
                        this.prePopulateSavedProducts();
                    };
                    
                    setTimeout(check, 500);
                },

                prePopulateSavedProducts() {
                    const datagrid = this.$root.$refs.datagrid;
                    
                    if (!datagrid || !datagrid.applied?.massActions?.indices) {
                        setTimeout(() => this.prePopulateSavedProducts(), 200);
                        return;
                    }

                    if (!datagrid.available?.records || datagrid.available.records.length === 0) {
                        setTimeout(() => this.prePopulateSavedProducts(), 200);
                        return;
                    }

                    const indices = datagrid.applied.massActions.indices;
                    
                    savedProductIds.forEach(id => {
                        if (!indices.includes(id)) {
                            indices.push(id);
                        }
                    });
                },

                saveSelectedProducts() {
                    const datagrid = this.$root.$refs.datagrid;
                    const selected = datagrid?.applied?.massActions?.indices || [];
                    const toSave = selected.filter(id => !this.isProductSaved(id));

                    if (toSave.length === 0) {
                        alert('No new products selected to save');
                        return;
                    }

                    axios.post("{{ route('admin.ondc.products.store') }}", {
                        products: toSave
                    })
                    .then(response => {
                        toSave.forEach(id => {
                            const numericId = Number(id);
                            if (!this.savedProductIds.includes(numericId)) {
                                this.savedProductIds.push(numericId);
                            }
                        });

                        alert('Products saved successfully');

                        setTimeout(() => {
                            window.location.reload();
                        }, 500);
                    })
                    .catch(error => {
                        console.error(error);
                        const errorMessage = error.response?.data?.message || 'Something went wrong.';
                        alert(errorMessage);
                    });
                }
            }
        });
    </script>
@endPushOnce
</x-admin::layouts>