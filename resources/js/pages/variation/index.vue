<script setup lang="ts">



    import { onMounted, ref, watchEffect, computed } from 'vue';

    import TopButtons from '@/components/topButtons.vue';

    import TheFilter from '@/components/theFilter.vue';

    import useCommons from '@/composables/common';

    import { Head, usePage } from '@inertiajs/vue3';

    import debounce from '@/utils/debounce';

    import useVariations from '@/composables/variation';

    import TheTable from '@/components/theTable.vue';

    import { API_ENDPOINTS } from '@/composables/apiEndpoints';

    import { createTableExportAllRows } from '@/composables/tableExportList';

    import AddModal from './add.vue';

    import EditModal from './edit.vue';

    import ImportModal from './import.vue';



    defineOptions({

        layout: {

            title: 'Variation Management',

            subtitle: 'Define product variation options by category and item type',

            breadcrumbs: [

                {

                    title: 'Variation Management',

                    href: 'NULL',

                },

            ],

        },

    });



    const { props } = usePage();



    const form$ = ref(null)



    const edit_id = ref({ id: 0 });

    let editFetchToken = 0;



    const {

        state,

        getVariations,

        changeStatus,

        deleteRecord,

        changeOrder,

        checkAll,

        duplicate,

        formData,

        defaultFormData,

        getEditData

    } = useVariations();



    const {

        select_data,

        getSavedValue,

        formatedText,

        handleError,

        handleSuccess,

        fetchCompany,

        fetchCategory,

        fetchSubCategory,

        fetchItemType,

        companiesdata,

        categoriesdata,

        subcategoriesdata,

        itemtypesdata,

    } = useCommons();



    const authUser = computed(() => props.auth?.user as {

        rolename?: string;

        company_id?: number | string | null;

    } | null);



    const normalizeRoleName = (name: unknown): string =>

        String(name ?? '').toLowerCase().replace(/\s+/g, '');



    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));

    const isSuperadmin = computed(() => roleName.value === 'superadmin');

    const showCompanyFilter = computed(() => isSuperadmin.value);

    const showFilter = computed(() => true);



    const columns = computed(() => [

        ...(isSuperadmin.value ? [

            { key: 'company_name', label: 'Company', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },

        ] : []),

        { key: 'category_name', label: 'Category', type: 'primary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },

        { key: 'itemtype_name', label: 'Item Type', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },

        { key: 'values_display', label: 'Values', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },

        { key: 'active', label: 'Status', type: 'badge', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled', show: 'active' },

        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled', actions: ['edit', 'delete', 'duplicate']},

    ]);



    const currentUrl = ref('');

    const oldcurrentUrl = ref((getSavedValue('currentUrl') || ''));

    const currentPage = ref(getSavedValue('currentPage', (v) => parseInt(v, 10)) || 1);

    const currentSearch = ref(getSavedValue('currentSearch') || '');

    const currentStatus = ref(getSavedValue('currentStatus') || 'all');

    const currentRecord = ref(getSavedValue('currentRecord', (v) => parseInt(v, 10)) || 10);

    if(getSavedValue('currentUrl') === props.routeName){

        currentUrl.value = (getSavedValue('currentUrl') || props.routeName);

    }else{

        currentUrl.value = props.routeName;

    }



    const stateRefMap = {

        currentPage,

        currentSearch,

        currentStatus,

        currentRecord,

        currentUrl,

    } as const;



    watchEffect(() => {

        if (typeof localStorage === 'undefined') {

            return;

        }



        ;['currentPage', 'currentSearch', 'currentStatus', 'currentRecord', 'currentUrl'].forEach((key) => {

            const val = stateRefMap[key as keyof typeof stateRefMap]?.value

            if (val !== undefined && val !== null) {

            localStorage.setItem(key, val)

            }

        })

    })



    const debouncedGetVariations = debounce((params) => {

        getVariations(params);

    }, 300);



    const getData = async () => {

        try {

            state.loading = true;



            if(currentRecord.value !== state.search.show_record){

                state.search.page = 1;

            }

            await debouncedGetVariations({ ...state.search });

            currentPage.value = state.search.page;

            currentSearch.value = state.search.search;

            currentStatus.value = state.search.status;

            currentRecord.value = state.search.show_record;

        } catch (error) {

            console.error('Error fetching variations:', error);

        }

    };



    const EditModalOpen = (id: number) => {

        edit_id.value.id = id;

        state.modalLoading = true;

        const token = ++editFetchToken;



        getEditData(id).finally(() => {

            if (token === editFetchToken) {

                state.modalLoading = false;

            }

        });

    };



    async function loadFilterScopeOptions(companyId: string | number | null | undefined) {

        if (! companyId) {

            categoriesdata.value = [];

            subcategoriesdata.value = [];

            itemtypesdata.value = [];



            return;

        }



        await Promise.all([

            fetchCategory(companyId),

            fetchItemType(companyId),

        ]);

    }



    async function handleFilterCompanyChange(companyId: string | number | null | undefined) {

        state.search.category_id = '';

        state.search.subcategory_id = '';

        state.search.itemtype_id = '';

        await loadFilterScopeOptions(companyId);

    }



    async function handleFilterCategoryChange(

        companyId: string | number | null | undefined,

        categoryId: string | number | null | undefined,

    ) {

        state.search.subcategory_id = '';



        if (! companyId || ! categoryId) {

            subcategoriesdata.value = [];



            return;

        }



        await fetchSubCategory(companyId, categoryId);

    }



    onMounted(async () => {

        state.search.company_id = authUser.value?.company_id ?? '';



        if (showCompanyFilter.value) {

            await fetchCompany();

        }



        if (state.search.company_id) {

            await loadFilterScopeOptions(state.search.company_id);

        }



        if(oldcurrentUrl.value === props.routeName){

            const savedValues = {

                page: getSavedValue('currentPage', (v) => parseInt(v, 10)),

                search: getSavedValue('currentSearch'),

                status: getSavedValue('currentStatus'),

                show_record: getSavedValue('currentRecord', (v) => parseInt(v, 10)),

            };



            Object.keys(savedValues).forEach((key) => {

                if (savedValues[key] !== null) {

                    state.search[key] = savedValues[key];

                }

            });

        }



        currentPage.value = state.search.page;

        currentSearch.value = state.search.search;

        currentStatus.value = state.search.status;

        currentRecord.value = state.search.show_record;



        if (state.search.company_id && state.search.category_id) {

            await fetchSubCategory(state.search.company_id, state.search.category_id);

        }



        debouncedGetVariations({ ...state.search });

    });



    const openAddModal = async () => {

        state.modalLoading = true;

        form$.value?.reset();

        formData.value = {

            ...defaultFormData.value,

            ...(isSuperadmin.value

                ? {}

                : { company_id: authUser.value?.company_id ?? '' }),

        };



        if (showCompanyFilter.value) {

            await fetchCompany();

        }



        state.modalLoading = false;

    };



    function handleAddModalClose() {

        state.modalLoading = true;

    }



    function handleEditModalClose() {

        state.modalLoading = true;

    }



    function onStateUpdate(newState) {

        Object.assign(state, newState)

    }



    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.variations, () => state);



    const filterOpen = ref(false);



    function clearSearch() {

        state.search.status = 'all';

        state.search.search = '';

        state.search.show_record = 10;

        state.search.page = 1;

        state.search.company_id = authUser.value?.company_id ?? '';

        state.search.category_id = '';

        state.search.subcategory_id = '';

        state.search.itemtype_id = '';

        getData();

    }



</script>



<template>

    <Head :title="formatedText(props.routeName)" />



    <div class="admin-list-page">

        <div class="admin-list-card">

            <div class="admin-list-card__toolbar">

                <TopButtons

                    :state="state"

                    :filter-open="filterOpen"

                    :getData="getData"

                    :changeStatus="changeStatus"

                    :deleteRecord="deleteRecord"

                    :url="`${props.routeName?.split('.')[0]}`"

                    :show-filter="showFilter"

                    :show-import="true"

                    @toggle-filter="filterOpen = !filterOpen"

                />

            </div>



            <TheFilter v-if="showFilter" v-model:open="filterOpen" :loading="state.loading" @clear="clearSearch" @search="getData">

                <div v-if="showCompanyFilter" class="col-md-4 col-lg-3 admin-filter-field">

                    <label class="form-label" for="variation-filter-company">Company</label>

                    <select

                        id="variation-filter-company"

                        class="form-select form-select-sm"

                        v-model="state.search.company_id"

                        @change="handleFilterCompanyChange(state.search.company_id)"

                    >

                        <option value="">All</option>

                        <option v-for="company in companiesdata" :key="company.id" :value="company.id">

                            {{ company.text ?? company.name }}

                        </option>

                    </select>

                </div>



                <div class="col-md-4 col-lg-3 admin-filter-field">

                    <label class="form-label" for="variation-filter-category">Category</label>

                    <select

                        id="variation-filter-category"

                        class="form-select form-select-sm"

                        v-model="state.search.category_id"

                        @change="handleFilterCategoryChange(state.search.company_id, state.search.category_id)"

                    >

                        <option value="">All</option>

                        <option v-for="category in categoriesdata" :key="category.id" :value="category.id">

                            {{ category.text ?? category.name }}

                        </option>

                    </select>

                </div>



                <div class="col-md-4 col-lg-3 admin-filter-field">

                    <label class="form-label" for="variation-filter-subcategory">Sub Category</label>

                    <select

                        id="variation-filter-subcategory"

                        class="form-select form-select-sm"

                        v-model="state.search.subcategory_id"

                    >

                        <option value="">All</option>

                        <option v-for="subcategory in subcategoriesdata" :key="subcategory.id" :value="subcategory.id">

                            {{ subcategory.text ?? subcategory.name }}

                        </option>

                    </select>

                </div>



                <div class="col-md-4 col-lg-3 admin-filter-field">

                    <label class="form-label" for="variation-filter-itemtype">Item Type</label>

                    <select

                        id="variation-filter-itemtype"

                        class="form-select form-select-sm"

                        v-model="state.search.itemtype_id"

                    >

                        <option value="">All</option>

                        <option v-for="itemtype in itemtypesdata" :key="itemtype.id" :value="itemtype.id">

                            {{ itemtype.text ?? itemtype.name }}

                        </option>

                    </select>

                </div>

            </TheFilter>



            <div class="admin-list-card__body">

                <div class="admin-list-table">

                    <TheTable

                        :columns="columns"

                        :selectData="select_data"

                        :state="state"

                        :checkAll="checkAll"

                        :getData="getData"

                        :changeOrder="changeOrder"

                        :changeStatus="changeStatus"

                        :delete="deleteRecord"

                        :duplicate="duplicate"

                        :edit="EditModalOpen"

                        actionType="modal"

                        :apiUrl="props.routeName?.split('.')[0]"

                        show-export

                        :export-file-name="String(props.routeName ?? 'export').replace(/\./g, '-')"

                        :export-title="formatedText(props.routeName)"

                        :export-all-rows="fetchAllRowsForExport"

                        @update:state="onStateUpdate"

                    />

                </div>

            </div>

        </div>

    </div>



    <AddModal

        :showLoader="state.modalLoading"

        :formData="formData"

        :formRef="form$"

        :endpoint="API_ENDPOINTS.variations"

        :onOpen="openAddModal"

        :onClose="handleAddModalClose"

        :success="(response) => handleSuccess(response, form$)"

        :error="(error, details) => handleError(error, details, form$)"

    />



    <EditModal

        :showLoader="state.modalLoading"

        :formData="formData"

        :formRef="form$"

        :record-id="edit_id.id || null"

        :endpoint="`${API_ENDPOINTS.variations}/${edit_id.id}`"

        :onClose="handleEditModalClose"

        :success="(response) => handleSuccess(response, form$)"

        :error="(error, details) => handleError(error, details, form$)"

    />



    <ImportModal :on-success="getData" />

</template>


