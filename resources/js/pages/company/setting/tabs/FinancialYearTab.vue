<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import ModalComponent from '@/components/ModalComponent.vue';
    import useCommons from '@/composables/common';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { Plus } from '@boxicons/vue';
    import { computed, onMounted, reactive, ref, watch } from 'vue';

    const props = defineProps({
        companyId: { type: [String, Number], default: '' },
        isSuperadmin: { type: Boolean, default: false },
    });

    const { fetchWithRetry, Notify, fetchCompany, companiesdata } = useCommons();

    const loading = ref(false);
    const saving = ref(false);
    const financialYears = ref<{ data: Array<Record<string, unknown>> }>({ data: [] });

    const search = reactive({
        company_id: '',
        status: 'all',
        search: '',
        show_record: 10,
        cur_page: 1,
        sort_by: 'created_at',
        sort_type: 'desc',
    });

    const addForm = reactive({
        company_id: '',
        start_date: '',
        end_date: '',
        status: true,
    });

    const canManage = computed(() => Boolean(props.companyId));

    function formatDate(value: unknown) {
        if (!value) {
            return '-';
        }

        const date = new Date(String(value));

        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return date.toLocaleDateString();
    }

    async function loadFinancialYears() {
        if (!canManage.value) {
            financialYears.value = { data: [] };
            return;
        }

        loading.value = true;

        try {
            const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.financialYears, {
                params: {
                    ...search,
                    company_id: props.companyId || search.company_id,
                },
            });
            financialYears.value = response.data;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                Notify(error.response?.data?.message || 'Unable to load financial years', 'alert');
            }
        } finally {
            loading.value = false;
        }
    }

    async function updateStatus(item: Record<string, unknown>) {
        try {
            await fetchWithRetry(window.axios.put, `${API_ENDPOINTS.financialYears}/${item.id}`, {
                updatetype: 'status',
                status: item.status,
                company_id: item.company_id || props.companyId,
            });
            Notify('Successfully Saved', 'success');
            await loadFinancialYears();
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                Notify(error.response?.data?.message || 'Unable to update financial year status', 'alert');
            }
            await loadFinancialYears();
        }
    }

    function resetAddForm() {
        addForm.company_id = String(props.companyId || '');
        addForm.start_date = '';
        addForm.end_date = '';
        addForm.status = true;
    }

    function openAddModal() {
        resetAddForm();
    }

    async function saveFinancialYear() {
        saving.value = true;

        try {
            await fetchWithRetry(window.axios.post, API_ENDPOINTS.financialYears, {
                ...addForm,
                company_id: addForm.company_id || props.companyId,
            });
            Notify('Successfully Saved', 'success');
            await loadFinancialYears();

            document.getElementById('FinancialYearAddModal')?.querySelector('[data-bs-dismiss="modal"]')?.click();
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                Notify(error.response?.data?.message || 'Unable to save financial year', 'alert');
            }
        } finally {
            saving.value = false;
        }
    }

    onMounted(async () => {
        if (props.isSuperadmin) {
            await fetchCompany();
        }

        search.company_id = String(props.companyId || '');
        await loadFinancialYears();
    });

    watch(
        () => props.companyId,
        async (companyId) => {
            search.company_id = String(companyId || '');
            await loadFinancialYears();
        },
    );
</script>

<template>
    <div class="company-setting-tab-panel">
        <div v-if="!canManage" class="company-setting-empty-state">
            Select a company to manage financial years.
        </div>

        <template v-else>
            <div class="company-setting-tab-toolbar">
                <p class="company-setting-section-help mb-0">
                    Define financial year periods for reporting and transactions.
                </p>
                <button
                    type="button"
                    class="btn btn-sm btn-primary company-setting-tab-toolbar__action d-inline-flex align-items-center gap-1"
                    data-bs-toggle="modal"
                    data-bs-target="#FinancialYearAddModal"
                    @click="openAddModal"
                >
                    <Plus size="xs" />
                    Add Financial Year
                </button>
            </div>

            <Loader v-if="loading" message="Loading financial years…" />

            <div v-else class="table-responsive company-setting-table-wrap">
                <table class="table table-sm table-hover align-middle company-setting-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="financialYears.data.length === 0">
                            <td colspan="4" class="text-center text-muted">No record found</td>
                        </tr>
                        <tr v-for="(item, index) in financialYears.data" :key="String(item.id)">
                            <td>{{ index + 1 }}</td>
                            <td>{{ formatDate(item.start_date) }}</td>
                            <td>{{ formatDate(item.end_date) }}</td>
                            <td>
                                <div class="form-check form-switch mb-0">
                                    <input
                                        :id="`financial-year-status-${item.id}`"
                                        v-model="item.status"
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        @change="updateStatus(item)"
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <ModalComponent id="FinancialYearAddModal" title="Add Financial Year" :on-open="openAddModal">
            <div class="row g-3">
                <div v-if="isSuperadmin" class="col-md-6">
                    <label class="form-label" for="financial-year-add-company">Company</label>
                    <select
                        id="financial-year-add-company"
                        v-model="addForm.company_id"
                        class="form-select form-select-sm"
                    >
                        <option value="">Select company</option>
                        <option
                            v-for="company in companiesdata"
                            :key="(company as { id: string | number }).id"
                            :value="(company as { id: string | number }).id"
                        >
                            {{ (company as { text: string }).text }}
                        </option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="financial-year-start">Start Date</label>
                    <input
                        id="financial-year-start"
                        v-model="addForm.start_date"
                        type="date"
                        class="form-control form-control-sm"
                    />
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="financial-year-end">End Date</label>
                    <input
                        id="financial-year-end"
                        v-model="addForm.end_date"
                        type="date"
                        class="form-control form-control-sm"
                    />
                </div>
            </div>

            <template #footer>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" :disabled="saving" @click="saveFinancialYear">
                    Save
                </button>
            </template>
        </ModalComponent>
    </div>
</template>
