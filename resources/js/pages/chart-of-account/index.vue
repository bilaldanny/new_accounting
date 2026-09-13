<script setup lang="ts">
    import { computed, nextTick, onMounted, ref } from 'vue';
    import useCommons from '@/composables/common';
    import useChartOfAccounts from '@/composables/chartOfAccount';
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { dashboard } from '@/routes';
    import AddModal from './add.vue';
    import EditModal from './edit.vue';
    import ChildrenTree from './ChildrenTree.vue';
    import FieldHint from '@/pages/journalentry/FieldHint.vue';
    import type { ChartOfAccountNode } from '@/composables/chartOfAccount';
    import {
        ChevronLeft,
        Filter,
        FolderTree,
        Home,
        Plus,
        RotateCw,
        Search,
    } from '@lucide/vue';

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

    const filterOpen = ref(true);
    const pendingParentId = ref<number | null>(null);
    const addFormKey = ref(0);
    const searchQuery = ref('');
    const activeCategory = ref('All accounts');
    const collapsedIds = ref<number[]>([]);

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

    const categoryTabs = computed(() => [
        {
            name: 'All accounts',
            count: countAccounts(state.records),
        },
        ...state.records.map((item) => ({
            name: String(item.name ?? ''),
            count: countAccounts([item]),
        })),
    ]);

    function nodeMatchesSearch(node: ChartOfAccountNode, query: string): boolean {
        if (! query) {
            return true;
        }

        const haystack = `${node.code ?? ''} ${node.name ?? ''}`.toLowerCase();

        return haystack.includes(query);
    }

    function filterTree(nodes: ChartOfAccountNode[], query: string): ChartOfAccountNode[] {
        return nodes
            .map((node) => {
                const children = filterTree(node.children ?? [], query);
                const selfMatch = nodeMatchesSearch(node, query);

                if (! selfMatch && children.length === 0) {
                    return null;
                }

                return {
                    ...node,
                    children,
                };
            })
            .filter((node): node is ChartOfAccountNode => node !== null);
    }

    const visibleRecords = computed(() => {
        const category = activeCategory.value;
        const scoped = category === 'All accounts'
            ? state.records
            : state.records.filter((item) => item.name === category);

        return filterTree(scoped, searchQuery.value.trim().toLowerCase());
    });

    function collectExpandableIds(nodes: ChartOfAccountNode[], ids: number[] = []): number[] {
        nodes.forEach((node) => {
            if (node.children?.length) {
                ids.push(node.id);
                collectExpandableIds(node.children, ids);
            }
        });

        return ids;
    }

    function expandAll(): void {
        collapsedIds.value = [];
    }

    function collapseAll(): void {
        collapsedIds.value = collectExpandableIds(state.records);
    }

    function toggleCollapse(id: number): void {
        collapsedIds.value = collapsedIds.value.includes(id)
            ? collapsedIds.value.filter((collapsedId) => collapsedId !== id)
            : [...collapsedIds.value, id];
    }

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

    <div class="product-form-view coa-page min-h-screen bg-slate-50/60 pb-8 font-sans text-slate-800">
        <div class="product-form-view__inner mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8">
            <div class="product-form-view__top sticky top-0 z-20 border-b border-slate-200/80 bg-white/95 py-3.5 backdrop-blur-md">
                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                    <div>
                        <div class="mb-0.5 flex items-center gap-1.5 text-xs font-medium text-slate-500">
                            <Link
                                :href="dashboard()"
                                class="flex cursor-pointer items-center gap-1 transition-colors hover:text-teal-700"
                            >
                                <Home class="h-3.5 w-3.5 text-slate-400" />
                                <span>Home</span>
                            </Link>
                            <ChevronLeft class="h-3 w-3 rotate-180 text-slate-300" />
                            <span class="text-slate-400">Accounts</span>
                            <ChevronLeft class="h-3 w-3 rotate-180 text-slate-300" />
                            <span class="font-semibold text-teal-800">Chart Of Account</span>
                        </div>
                        <h1 class="flex items-center gap-2 text-lg font-bold tracking-tight text-slate-900 sm:text-xl">
                            Chart Of Account
                            <span class="rounded-full border border-teal-200/80 bg-teal-50 px-2 py-0.5 text-xs font-semibold text-teal-700">
                                Standard Hierarchy
                            </span>
                        </h1>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-all"
                            :class="filterOpen
                                ? 'border-teal-200 bg-teal-50 text-teal-800'
                                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
                            @click="filterOpen = !filterOpen"
                        >
                            <Filter class="h-3.5 w-3.5 text-teal-600" />
                            <span>Filter</span>
                        </button>
                        <button
                            type="button"
                            class="cursor-pointer rounded-lg border border-slate-200 bg-white p-1.5 text-slate-600 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-colors hover:bg-slate-50 hover:text-slate-900 disabled:opacity-50"
                            title="Reload Chart of Accounts"
                            :disabled="!filterReady || state.loading"
                            @click="getData"
                        >
                            <RotateCw class="h-3.5 w-3.5" :class="{ 'animate-spin': state.loading }" />
                        </button>
                        <button
                            type="button"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-teal-700 px-4 py-1.5 text-xs font-semibold text-white shadow-xs transition-all hover:bg-teal-800 hover:shadow disabled:opacity-50"
                            data-bs-toggle="modal"
                            data-bs-target="#AddModal"
                            :disabled="!filterReady"
                            @click="queueAddModal(null)"
                        >
                            <Plus class="h-3.5 w-3.5" />
                            <span>Add New</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="space-y-4 py-4">
                <div
                    v-if="filterOpen"
                    class="overflow-hidden rounded-2xl border border-slate-200/90 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                >
                    <div class="flex flex-col gap-4 px-4 py-4 lg:flex-row lg:items-end lg:justify-between">
                        <div class="grid flex-1 grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <div v-if="showCompanyFilter" class="form-field">
                                <label for="coa-filter-company">
                                    <FieldHint label="Company" required>
                                        Select the company whose chart of accounts you want to view.
                                    </FieldHint>
                                </label>
                                <select
                                    id="coa-filter-company"
                                    class="coa-scope-select"
                                    v-model="state.search.company_id"
                                    @change="handleCompanyFilterChange(state.search.company_id)"
                                >
                                    <option value="">Select company</option>
                                    <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                                        {{ company.text ?? company.name }}
                                    </option>
                                </select>
                            </div>
                            <div v-if="showBranchFilter" class="form-field">
                                <label for="coa-filter-branch">
                                    <FieldHint label="Branch" required>
                                        Required. Accounts are scoped to the selected branch.
                                    </FieldHint>
                                </label>
                                <select
                                    id="coa-filter-branch"
                                    class="coa-scope-select"
                                    v-model="state.search.branch_id"
                                    :disabled="branchFilterDisabled"
                                    @change="getData()"
                                >
                                    <option value="">Select branch</option>
                                    <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">
                                        {{ branch.text ?? branch.name }}
                                    </option>
                                </select>
                            </div>
                            <div class="form-field">
                                <label for="coa-filter-status">
                                    <FieldHint label="Status">
                                        Filter accounts by active or inactive status.
                                    </FieldHint>
                                </label>
                                <select
                                    id="coa-filter-status"
                                    class="coa-scope-select"
                                    v-model="state.search.status"
                                    @change="getData()"
                                >
                                    <option value="all">All Status</option>
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2 border-t border-slate-100 pt-3 lg:border-t-0 lg:border-l lg:pl-4 lg:pt-0">
                            <button
                                type="button"
                                class="cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 shadow-[0_1px_1px_rgba(15,23,42,0.04)] transition-colors hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900"
                                @click="expandAll"
                            >
                                Expand All
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 shadow-[0_1px_1px_rgba(15,23,42,0.04)] transition-colors hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900"
                                @click="collapseAll"
                            >
                                Collapse All
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col justify-between gap-2 px-1 text-xs text-slate-600 sm:flex-row sm:items-center">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span>Showing</span>
                        <strong class="font-bold text-slate-900">{{ state.records.length }}</strong>
                        <span>categories and</span>
                        <strong class="font-bold text-slate-900">{{ totalAccounts }}</strong>
                        <span>accounts for</span>
                        <span class="rounded border border-teal-200/60 bg-teal-50 px-1.5 py-0.5 font-bold text-teal-900">
                            {{ selectedBranchLabel }}
                        </span>
                        <span>.</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" class="cursor-pointer text-xs font-medium text-teal-700 hover:text-teal-900 hover:underline" @click="expandAll">
                            Expand All
                        </button>
                        <span class="text-slate-300">•</span>
                        <button type="button" class="cursor-pointer text-xs font-medium text-slate-500 hover:text-slate-700 hover:underline" @click="collapseAll">
                            Collapse Subgroups
                        </button>
                    </div>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-200/90 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                    <div class="flex flex-col gap-3 border-b border-slate-100 bg-slate-50/80 px-3 py-2.5 lg:flex-row lg:items-center lg:justify-between">
                        <div class="coa-category-tabs" role="tablist" aria-label="Account categories">
                            <button
                                v-for="tab in categoryTabs"
                                :key="tab.name"
                                type="button"
                                role="tab"
                                :aria-selected="activeCategory === tab.name"
                                :class="activeCategory === tab.name ? 'is-active' : ''"
                                @click="activeCategory = tab.name"
                            >
                                <span>{{ tab.name }}</span>
                                <span class="coa-category-tabs__count">{{ tab.count }}</span>
                            </button>
                        </div>
                        <div class="relative w-full shrink-0 lg:w-72">
                            <Search class="pointer-events-none absolute top-1/2 left-3 z-10 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                            <input
                                type="text"
                                placeholder="Filter code or account..."
                                class="coa-search-input"
                                autocomplete="off"
                                v-model="searchQuery"
                            >
                            <button
                                v-if="searchQuery"
                                type="button"
                                class="absolute top-1/2 right-2 -translate-y-1/2 text-[10px] font-semibold text-slate-400 hover:text-slate-600"
                                @click="searchQuery = ''"
                            >
                                ✕
                            </button>
                        </div>
                    </div>
                    <div v-if="! filterReady" class="px-4 py-12 text-center text-slate-400">
                        <FolderTree class="mx-auto mb-2 h-8 w-8 text-slate-300" />
                        <p class="font-semibold text-slate-600">Select a company and branch</p>
                        <p class="mt-1 text-[11px] text-slate-400">Choose company and branch, then load the chart of accounts.</p>
                    </div>
                    <div v-else-if="state.loading" class="px-4 py-12 text-center text-slate-400">
                        <RotateCw class="mx-auto mb-2 h-8 w-8 animate-spin text-slate-300" />
                        <p class="font-semibold text-slate-600">Loading accounts</p>
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[720px] border-collapse text-left">
                            <thead>
                                <tr class="select-none border-b border-slate-200 bg-slate-50/90 text-[11px] font-bold tracking-wider text-slate-600 uppercase">
                                    <th class="w-44 px-4 py-3">Code</th>
                                    <th class="min-w-[340px] px-4 py-3">Name</th>
                                    <th class="w-44 px-4 py-3 text-right">Balance</th>
                                    <th class="w-48 px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-xs">
                                <tr v-if="visibleRecords.length === 0">
                                    <td colspan="4" class="px-4 py-12 text-center text-slate-400">
                                        <FolderTree class="mx-auto mb-2 h-8 w-8 text-slate-300" />
                                        <p class="font-semibold text-slate-600">No accounts match the selected category or search</p>
                                        <button
                                            type="button"
                                            class="mt-2 cursor-pointer text-xs font-semibold text-teal-700 hover:underline"
                                            @click="activeCategory = 'All accounts'; searchQuery = ''"
                                        >
                                            Show All Accounts
                                        </button>
                                    </td>
                                </tr>
                                <ChildrenTree
                                    v-else
                                    :nodes="visibleRecords"
                                    :collapsed-ids="collapsedIds"
                                    @add="queueAddModal"
                                    @edit="openEditModal"
                                    @toggle="toggleCollapse"
                                />
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex flex-col items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 text-xs shadow-[0_1px_2px_rgba(15,23,42,0.04)] md:flex-row">
                    <div class="flex flex-wrap items-center gap-4 text-slate-500">
                        <div class="flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full bg-indigo-500"></span>
                            <span class="font-medium text-slate-700">Control Account</span>
                            <span class="text-slate-400">(Summary rollup, non-posting)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                            <span class="font-medium text-slate-700">Transactional Account</span>
                            <span class="text-slate-400">(General ledger posting active)</span>
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

<style scoped>
.coa-category-tabs {
    display: flex;
    min-width: 0;
    align-items: center;
    gap: 0.25rem;
    overflow-x: auto;
    padding: 0.125rem;
    scrollbar-width: none;
}

.coa-category-tabs::-webkit-scrollbar {
    display: none;
}

.coa-category-tabs button {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    gap: 0.375rem;
    padding: 0.4rem 0.75rem;
    border: 1px solid transparent;
    border-radius: 0.625rem;
    background: transparent;
    color: #64748b;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}

.coa-category-tabs button:hover {
    background: #fff;
    color: #0f172a;
}

.coa-category-tabs button.is-active {
    background: #fff;
    border-color: #ccfbf1;
    color: #0f766e;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
}

.coa-category-tabs__count {
    display: inline-flex;
    min-width: 1.25rem;
    align-items: center;
    justify-content: center;
    padding: 0.05rem 0.35rem;
    border-radius: 999px;
    background: #e2e8f0;
    color: #475569;
    font-size: 0.625rem;
    font-weight: 700;
    line-height: 1.2;
}

.coa-category-tabs button.is-active .coa-category-tabs__count {
    background: #ccfbf1;
    color: #0f766e;
}

.coa-search-input {
    box-sizing: border-box;
    display: block;
    width: 100%;
    height: var(--form-control-height);
    min-height: var(--form-control-height);
    padding: 0.5rem 2rem 0.5rem 2.35rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.625rem;
    background-color: #fff;
    background-image: none;
    color: #0f172a;
    font-size: 0.8125rem;
    font-weight: 500;
    line-height: 1.25;
    box-shadow: 0 1px 1px rgba(15, 23, 42, 0.04);
    appearance: none;
}

.coa-search-input::placeholder {
    color: #94a3b8;
}

.coa-search-input:hover {
    border-color: #cbd5e1;
}

.coa-search-input:focus {
    border-color: #0d9488;
    outline: none;
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.14);
}
</style>
