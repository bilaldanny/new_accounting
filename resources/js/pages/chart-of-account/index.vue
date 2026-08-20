<script setup lang="ts">
    import { computed, nextTick, onMounted, ref } from 'vue';
    import TopButtons from '@/components/topButtons.vue';
    import TheFilter from '@/components/theFilter.vue';
    import useCommons from '@/composables/common';
    import useChartOfAccounts from '@/composables/chartOfAccount';
    import { Head, usePage } from '@inertiajs/vue3';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import AddModal from './add.vue';
    import EditModal from './edit.vue';
    import ChildrenTree from './ChildrenTree.vue';

    defineOptions({
        layout: {
            title: 'Chart Of Account',
            subtitle: 'Manage your chart of accounts hierarchy',
            breadcrumbs: [
                {
                    title: 'Chart Of Account',
                    href: 'NULL',
                },
            ],
        },
    });

    const { props } = usePage();
    const form$ = ref(null);
    const editId = ref(0);
    let editFetchToken = 0;

    const {
        state,
        formData,
        defaultFormData,
        controlAccounts,
        getChartOfAccounts,
        fetchControlAccounts,
        generateAccountCode,
        applyParentClassification,
        getEditData,
    } = useChartOfAccounts();

    const {
        formatedText,
        handleError,
        handleSuccess,
        fetchCompany,
        fetchBranch,
        companiesdata,
        branchesdata,
        Notify,
    } = useCommons();

    const authUser = computed(() => props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyFilter = computed(() => isSuperadmin.value);
    const showBranchFilter = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const branchFilterDisabled = computed(() => showCompanyFilter.value && ! state.search.company_id);

    const resolvedCompanyId = computed(() =>
        state.search.company_id || authUser.value?.company_id || '',
    );

    const resolvedBranchId = computed(() =>
        state.search.branch_id || authUser.value?.branch_id || '',
    );

    const filterReady = computed(() => {
        if (isSuperadmin.value) {
            return Boolean(state.search.company_id && state.search.branch_id);
        }

        if (isCompanyadmin.value) {
            return Boolean(resolvedCompanyId.value && state.search.branch_id);
        }

        return Boolean(resolvedCompanyId.value && resolvedBranchId.value);
    });

    const filterOpen = ref(false);
    const pendingParentId = ref<number | null>(null);
    const addFormKey = ref(0);

    function resolveParentIdFromTrigger(event?: Event): number | '' {
        const trigger = (event as (Event & { relatedTarget?: HTMLElement | null }) | undefined)?.relatedTarget;
        const parentIdFromTrigger = trigger?.dataset?.parentId;

        if (parentIdFromTrigger) {
            return Number(parentIdFromTrigger);
        }

        if (pendingParentId.value) {
            return Number(pendingParentId.value);
        }

        return '';
    }

    async function syncAddFormValues(parentId: number | '', code = formData.value.code) {
        if (! parentId) {
            return;
        }

        for (let attempt = 0; attempt < 8; attempt += 1) {
            await nextTick();

            if (form$.value?.update) {
                form$.value.update({
                    parent_id: parentId,
                    code,
                });

                return;
            }
        }
    }

    const apiSearchParams = computed(() => ({
        company_id: resolvedCompanyId.value,
        branch_id: isSuperadmin.value || isCompanyadmin.value
            ? state.search.branch_id
            : resolvedBranchId.value,
        status: state.search.status,
    }));

    const getData = async () => {
        if (! filterReady.value) {
            Notify('Select a company and branch to load the chart of accounts.', 'alert');

            return;
        }

        await getChartOfAccounts(apiSearchParams.value);
    };

    async function handleCompanyFilterChange(companyId: string | number | null | undefined) {
        state.search.branch_id = '';
        await fetchBranch(companyId);
    }

    function clearSearch() {
        state.search.status = 'all';
        state.search.company_id = authUser.value?.company_id ?? '';
        state.search.branch_id = authUser.value?.branch_id ?? '';
        state.records = [];
    }

    function queueAddModal(parentId: number | null = null) {
        pendingParentId.value = parentId;
    }

    async function openAddModal(event?: Event) {
        if (! filterReady.value) {
            Notify('Select a company and branch before adding an account.', 'alert');

            return;
        }

        state.modalLoading = true;
        form$.value?.reset();

        const selectedParentId = resolveParentIdFromTrigger(event);
        pendingParentId.value = null;
        addFormKey.value += 1;

        formData.value = {
            ...defaultFormData.value,
            company_id: resolvedCompanyId.value,
            branch_id: isSuperadmin.value || isCompanyadmin.value
                ? state.search.branch_id
                : resolvedBranchId.value,
            parent_id: selectedParentId,
        };

        await fetchControlAccounts(formData.value.company_id, formData.value.branch_id);

        if (selectedParentId) {
            await applyParentClassification(
                selectedParentId,
                formData.value.company_id,
                formData.value.branch_id,
                formData.value.acc_type || 't',
            );

            await generateAccountCode(
                selectedParentId,
                formData.value.company_id,
                formData.value.branch_id,
                formData.value.acc_type || 't',
            );
        }

        state.modalLoading = false;
        await syncAddFormValues(selectedParentId, formData.value.code);
    }

    async function handleParentChange(parentId: string | number) {
        await applyParentClassification(
            parentId,
            formData.value.company_id,
            formData.value.branch_id,
            formData.value.acc_type || 't',
            form$.value,
        );

        await generateAccountCode(
            parentId,
            formData.value.company_id,
            formData.value.branch_id,
            formData.value.acc_type || 't',
        );
    }

    async function handleAccountTypeChange(accType: string) {
        const parentId = formData.value.parent_id;

        if (! parentId) {
            return;
        }

        await applyParentClassification(
            parentId,
            formData.value.company_id,
            formData.value.branch_id,
            accType || 't',
            form$.value,
        );

        await generateAccountCode(
            parentId,
            formData.value.company_id,
            formData.value.branch_id,
            accType || 't',
        );
    }

    function openEditModal(id: number) {
        editId.value = id;
        state.modalLoading = true;
        const token = ++editFetchToken;

        Promise.all([
            getEditData(id),
            fetchControlAccounts(resolvedCompanyId.value, resolvedBranchId.value),
        ]).finally(() => {
            if (token === editFetchToken) {
                state.modalLoading = false;
            }
        });
    }

    function handleAddModalClose() {
        state.modalLoading = true;
        pendingParentId.value = null;
    }

    function handleEditModalClose() {
        state.modalLoading = true;
    }

    function handleSaveSuccess(response: Parameters<typeof handleSuccess>[0]) {
        handleSuccess(response, form$);
        pendingParentId.value = null;
    }

    function countAccounts(nodes: typeof state.records): number {
        return nodes.reduce(
            (total, node) => total + 1 + countAccounts(node.children ?? []),
            0,
        );
    }

    const totalAccounts = computed(() => countAccounts(state.records));

    const selectedBranchLabel = computed(() => {
        const branchId = String(apiSearchParams.value.branch_id ?? '');

        return branchesdata.value.find((branch) => String(branch.id) === branchId)?.text
            ?? branchesdata.value.find((branch) => String(branch.id) === branchId)?.name
            ?? 'Selected branch';
    });

    onMounted(async () => {
        state.search.company_id = authUser.value?.company_id ?? '';
        state.search.branch_id = authUser.value?.branch_id ?? '';

        if (showCompanyFilter.value) {
            await fetchCompany();
        }

        if (isCompanyadmin.value && authUser.value?.company_id) {
            state.search.company_id = authUser.value.company_id;
            await fetchBranch(authUser.value.company_id);
        }

        if (filterReady.value) {
            await getData();
        }
    });
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
                    :url="`${props.routeName?.split('.')[0]}`"
                    :show-import="false"
                    :show-status="false"
                    @toggle-filter="filterOpen = !filterOpen"
                />
            </div>

            <TheFilter
                v-model:open="filterOpen"
                :loading="state.loading"
                @clear="clearSearch"
                @search="getData"
            >
                <div v-if="showCompanyFilter" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="coa-filter-company">Company</label>
                    <select
                        id="coa-filter-company"
                        class="form-select form-select-sm"
                        v-model="state.search.company_id"
                        @change="handleCompanyFilterChange(state.search.company_id)"
                    >
                        <option value="">All</option>
                        <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                            {{ company.text ?? company.name }}
                        </option>
                    </select>
                </div>
                <div v-if="showBranchFilter" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="coa-filter-branch">Branch</label>
                    <select
                        id="coa-filter-branch"
                        class="form-select form-select-sm"
                        v-model="state.search.branch_id"
                        :disabled="branchFilterDisabled"
                        @change="getData()"
                    >
                        <option value="">All</option>
                        <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">
                            {{ branch.text ?? branch.name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="coa-filter-status">Status</label>
                    <select
                        id="coa-filter-status"
                        class="form-select form-select-sm"
                        v-model="state.search.status"
                    >
                        <option value="all">All Status</option>
                        <option value="1">Active</option>
                        <option value="0">Not Active</option>
                    </select>
                </div>
            </TheFilter>

            <div class="admin-list-card__body">
                <div v-if="! filterReady" class="admin-list-empty">
                    <p class="mb-0 text-muted">Select a company and branch, then click Search to load the chart of accounts.</p>
                </div>

                <div v-else-if="state.loading" class="admin-list-empty">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading…</span>
                    </div>
                </div>

                <div v-else-if="state.records.length === 0" class="admin-list-empty">
                    <p class="mb-0 text-muted">No chart of accounts found for the selected filters.</p>
                </div>

                <div v-else class="coa-panel">
                    <div class="coa-panel__summary">
                        <p class="coa-panel__summary-text">
                            Showing <strong>{{ state.records.length }}</strong> categories and
                            <strong>{{ totalAccounts }}</strong> accounts for
                            <strong>{{ selectedBranchLabel }}</strong>.
                        </p>
                    </div>

                    <div class="coa-tabs-wrap">
                        <ul class="nav nav-tabs nav-success mb-0" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#coa-all" type="button" role="tab">
                                    All accounts
                                </button>
                            </li>
                            <li
                                v-for="item in state.records"
                                :key="item.id"
                                class="nav-item"
                                role="presentation"
                            >
                                <button
                                    class="nav-link"
                                    data-bs-toggle="tab"
                                    :data-bs-target="`#coa-tab-${item.id}`"
                                    type="button"
                                    role="tab"
                                >
                                    {{ item.name }}
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div class="tab-content coa-table-shell">
                        <div class="coa-table-head d-none d-md-grid">
                            <div>Code</div>
                            <div>Name</div>
                            <div class="text-end">Balance</div>
                            <div class="text-end">Actions</div>
                        </div>

                        <div class="tab-pane fade show active" id="coa-all" role="tabpanel">
                            <ChildrenTree
                                :nodes="state.records"
                                @add="queueAddModal"
                                @edit="openEditModal"
                            />
                        </div>
                        <div
                            v-for="item in state.records"
                            :key="`pane-${item.id}`"
                            class="tab-pane fade"
                            :id="`coa-tab-${item.id}`"
                            role="tabpanel"
                        >
                            <ChildrenTree
                                :nodes="[item]"
                                @add="queueAddModal"
                                @edit="openEditModal"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <AddModal
        :showLoader="state.modalLoading"
        :formData="formData"
        :formRef="form$"
        :form-key="addFormKey"
        :controlAccounts="controlAccounts"
        :endpoint="API_ENDPOINTS.chartOfAccounts"
        :onOpen="openAddModal"
        :onClose="handleAddModalClose"
        :success="handleSaveSuccess"
        :error="(error, details) => handleError(error, details, form$)"
        :onParentChange="handleParentChange"
        :onAccountTypeChange="handleAccountTypeChange"
    />

    <EditModal
        :showLoader="state.modalLoading"
        :formData="formData"
        :formRef="form$"
        :record-id="editId || null"
        :controlAccounts="controlAccounts"
        :endpoint="`${API_ENDPOINTS.chartOfAccounts}/${editId}`"
        :onClose="handleEditModalClose"
        :success="handleSaveSuccess"
        :error="(error, details) => handleError(error, details, form$)"
    />
</template>
