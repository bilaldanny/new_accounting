<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';
    import useTaxes from '@/composables/tax';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import debounce from '@/utils/debounce';
    import { Plus } from '@boxicons/vue';
    import { computed, onMounted, ref, watch } from 'vue';
    import AddModal from '../tax/add.vue';
    import AddGroupModal from '../tax/addGroup.vue';
    import EditModal from '../tax/edit.vue';

    const props = defineProps({
        companyId: { type: [String, Number], default: '' },
        isSuperadmin: { type: Boolean, default: false },
    });

    const { handleError, Notify } = useCommons();

    const form$ = ref(null);
    const groupForm$ = ref(null);
    const editForm$ = ref(null);
    const edit_id = ref({ id: 0, type: 0 });
    let editFetchToken = 0;

    const {
        state,
        getTaxes,
        changeStatus,
        deleteRecord,
        formData,
        defaultFormData,
        groupFormData,
        defaultGroupFormData,
        getEditData,
    } = useTaxes();

    const canManage = computed(() => Boolean(props.companyId));

    const debouncedGetTaxes = debounce((params) => {
        getTaxes(params);
    }, 300);

    const getData = async () => {
        if (!canManage.value) {
            return;
        }

        state.loading = true;
        await debouncedGetTaxes({ ...state.search });
    };

    const EditModalOpen = (id: number, type = 0) => {
        edit_id.value.id = id;
        edit_id.value.type = type;
        state.modalLoading = true;
        const token = ++editFetchToken;

        getEditData(id).then((resolvedType) => {
            if (token !== editFetchToken) {
                return;
            }

            edit_id.value.type = resolvedType;
            state.modalLoading = false;
        });
    };

    const openAddModal = async () => {
        state.modalLoading = true;
        form$.value?.reset?.();
        formData.value = {
            ...defaultFormData.value,
            company_id: String(props.companyId || ''),
            type: 0,
            status: true,
        };
        state.modalLoading = false;
    };

    const openAddGroupModal = async () => {
        state.modalLoading = true;
        groupForm$.value?.reset?.();
        groupFormData.value = {
            ...defaultGroupFormData.value,
            company_id: String(props.companyId || ''),
            type: 1,
            status: true,
            sub_tax: [],
        };
        state.modalLoading = false;
    };

    function handleAddModalClose() {
        state.modalLoading = true;
    }

    function handleEditModalClose() {
        state.modalLoading = true;
        editFetchToken++;
    }

    function handleTaxSuccess(response: { data?: { message?: string } }) {
        Notify(response?.data?.message || 'Successfully Saved', 'success');
        getData();
        document.querySelectorAll('.btn-close').forEach((element) => {
            (element as HTMLElement).click();
        });
    }

    async function handleDelete(id: number) {
        await deleteRecord([id]);
        await getData();
    }

    onMounted(() => {
        state.search.company_id = String(props.companyId || '');
        void getData();
    });

    watch(
        () => props.companyId,
        async (companyId) => {
            state.search.company_id = String(companyId || '');
            state.search.page = 1;
            await getData();
        },
    );
</script>

<template>
    <div class="company-setting-tab-panel">
        <div v-if="!canManage" class="company-setting-empty-state">
            Select a company to manage taxes.
        </div>

        <template v-else>
            <div class="company-setting-tab-toolbar">
                <p class="company-setting-section-help mb-0">
                    Manage tax rates applied to transactions for this company.
                </p>

                <div class="d-flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="btn btn-sm btn-primary company-setting-tab-toolbar__action d-inline-flex align-items-center gap-1"
                        data-bs-toggle="modal"
                        data-bs-target="#TaxAddModal"
                        @click="openAddModal"
                    >
                        <Plus size="xs" />
                        Add Tax
                    </button>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary company-setting-tab-toolbar__action d-inline-flex align-items-center gap-1"
                        data-bs-toggle="modal"
                        data-bs-target="#TaxAddGroupModal"
                        @click="openAddGroupModal"
                    >
                        <Plus size="xs" />
                        Add Group
                    </button>
                </div>
            </div>

            <Loader v-if="state.loading" message="Loading taxes…" />

            <div v-else class="table-responsive company-setting-table-wrap">
                <table class="table table-sm table-hover align-middle company-setting-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Name</th>
                            <th>Rate %</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="state.records.data.length === 0">
                            <td colspan="5" class="text-center text-muted">No record found</td>
                        </tr>
                        <tr v-for="(item, index) in state.records.data" :key="String(item.id)">
                            <td>{{ state.records.from + index }}</td>
                            <td>{{ item.name || '-' }}</td>
                            <td>{{ item.percentage ?? '-' }}</td>
                            <td>
                                <span
                                    class="badge cursor-pointer"
                                    :class="item.status ? 'bg-success' : 'bg-danger'"
                                    @click="changeStatus([Number(item.id)], null)"
                                >
                                    {{ item.status ? 'Active' : 'In Active' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-warning me-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#EditModal"
                                    @click="EditModalOpen(Number(item.id), Number(item.type))"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    @click="handleDelete(Number(item.id))"
                                >
                                    Delete
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <AddModal
            :show-loader="state.modalLoading"
            :form-data="formData"
            :form-ref="form$"
            :endpoint="API_ENDPOINTS.taxes"
            :company-id="companyId"
            :is-superadmin="isSuperadmin"
            :on-open="openAddModal"
            :on-close="handleAddModalClose"
            :success="handleTaxSuccess"
            :error="(error, details) => handleError(error, details, form$)"
        />

        <AddGroupModal
            :show-loader="state.modalLoading"
            :form-data="groupFormData"
            :form-ref="groupForm$"
            :endpoint="API_ENDPOINTS.taxes"
            :company-id="companyId"
            :is-superadmin="isSuperadmin"
            :on-open="openAddGroupModal"
            :on-close="handleAddModalClose"
            :success="handleTaxSuccess"
            :error="(error, details) => handleError(error, details, groupForm$)"
        />

        <EditModal
            :show-loader="state.modalLoading"
            :form-data="formData"
            :group-form-data="groupFormData"
            :form-ref="editForm$"
            :record-id="edit_id.id || null"
            :edit-type="edit_id.type"
            :endpoint="`${API_ENDPOINTS.taxes}/${edit_id.id}`"
            :company-id="companyId"
            :is-superadmin="isSuperadmin"
            :on-close="handleEditModalClose"
            :success="handleTaxSuccess"
            :error="(error, details) => handleError(error, details, editForm$)"
        />
    </div>
</template>
