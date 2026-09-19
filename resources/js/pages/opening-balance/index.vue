<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import {
        AlertTriangle,
        CheckCircle2,
        ChevronLeft,
        FolderTree,
        Filter,
        Home,
        RotateCw,
        Save,
        Scale,
        Search,
        SlidersHorizontal,
        TrendingDown,
        TrendingUp,
    } from '@lucide/vue';
    import { computed, onMounted, ref, watch } from 'vue';
    import useAccountBalances from '@/composables/accountBalance';
    import type { OpeningBalanceAccount } from '@/composables/accountBalance';
    import useCommons from '@/composables/common';
    import FieldHint from '@/pages/journalentry/FieldHint.vue';
    import { dashboard } from '@/routes';
    import { formatNumber } from '@/utils/numberFormat';

    defineOptions({
        layout: {
            title: 'Opening Balance',
            subtitle: 'Set opening balances for chart of accounts by financial year',
            breadcrumbs: [
                {
                    title: 'Opening Balance',
                    href: 'NULL',
                },
            ],
        },
    });

    const { props } = usePage();
    const filterOpen = ref(true);
    const searchQuery = ref('');

    const {
        formData,
        state,
        obAccounts,
        financialYears,
        grandTotal,
        fetchObAccounts,
        fetchFinancialYears,
        fetchBalance,
        saveBalances,
        resetAccounts,
    } = useAccountBalances();

    const {
        formatedText,
        fetchCompany,
        fetchBranch,
        companiesdata,
        branchesdata,
    } = useCommons();

    const authUser = computed(() => props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
        currency_symbol?: string | null;
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyFilter = computed(() => isSuperadmin.value);
    const showBranchFilter = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const branchFilterDisabled = computed(() => showCompanyFilter.value && ! formData.company_id);
    const currencySymbol = computed(() => String(authUser.value?.currency_symbol ?? '').trim());

    const resolvedCompanyId = computed(() =>
        formData.company_id || authUser.value?.company_id || '',
    );

    const resolvedBranchId = computed(() =>
        formData.branch_id || authUser.value?.branch_id || '',
    );

    const filterReady = computed(() => {
        if (isSuperadmin.value) {
            return Boolean(formData.company_id && formData.branch_id);
        }

        if (isCompanyadmin.value) {
            return Boolean(resolvedCompanyId.value && formData.branch_id);
        }

        return Boolean(resolvedCompanyId.value && resolvedBranchId.value);
    });

    const canLoadBalances = computed(() =>
        filterReady.value && formData.financial_id && formData.account_id,
    );

    const canSave = computed(() =>
        canLoadBalances.value && formData.accounts.length > 0 && ! state.saving,
    );

    const selectedBranchLabel = computed(() => {
        const branchId = String(resolvedBranchId.value ?? '');

        return branchesdata.value.find((branch) => String(branch.id) === branchId)?.text
            ?? branchesdata.value.find((branch) => String(branch.id) === branchId)?.name
            ?? 'Selected branch';
    });

    const selectedFinancialYearLabel = computed(() => {
        const yearId = String(formData.financial_id ?? '');

        return financialYears.value.find((year) => String(year.id) === yearId)?.text
            ?? financialYears.value.find((year) => String(year.id) === yearId)?.name
            ?? 'Financial year';
    });

    const selectedAccountLabel = computed(() => {
        const accountId = String(formData.account_id ?? '');

        return obAccounts.value.find((account) => String(account.id) === accountId)?.text
            ?? obAccounts.value.find((account) => String(account.id) === accountId)?.name
            ?? 'Parent account';
    });

    function accountCategory(account: OpeningBalanceAccount): string {
        const code = String(account.code ?? '');

        if (code.startsWith('2')) {
            return 'Assets';
        }

        if (code.startsWith('1')) {
            return 'Liabilities';
        }

        if (code.startsWith('3')) {
            return 'Equity';
        }

        if (code.startsWith('4')) {
            return 'Revenue';
        }

        if (code.startsWith('5')) {
            return 'Expense';
        }

        return 'Other';
    }

    const visibleAccounts = computed(() => {
        const query = searchQuery.value.trim().toLowerCase();

        if (! query) {
            return formData.accounts;
        }

        return formData.accounts.filter((account) => {
            const haystack = `${account.code ?? ''} ${account.name ?? ''}`.toLowerCase();

            return haystack.includes(query);
        });
    });

    const totalDebit = computed(() =>
        formData.accounts.reduce((sum, account) => (
            account.acc_nature === 'dr' ? sum + Number(account.opening_balance || 0) : sum
        ), 0),
    );

    const totalCredit = computed(() =>
        formData.accounts.reduce((sum, account) => (
            account.acc_nature === 'cr' ? sum + Number(account.opening_balance || 0) : sum
        ), 0),
    );

    const difference = computed(() => Math.abs(totalDebit.value - totalCredit.value));
    const isBalanced = computed(() => formData.accounts.length > 0 && difference.value < 0.01);

    const visibleTotal = computed(() =>
        visibleAccounts.value.reduce((sum, account) => sum + Number(account.opening_balance || 0), 0),
    );

    function money(value: number): string {
        const amount = formatNumber(value);

        return currencySymbol.value ? `${currencySymbol.value} ${amount}` : amount;
    }

    const emptyState = computed(() => {
        if (! filterReady.value) {
            return {
                title: 'Select company and branch',
                text: 'Choose the company and branch above, then pick a financial year and parent account to load opening balances.',
            };
        }

        if (! formData.financial_id || ! formData.account_id) {
            return {
                title: 'Choose period and account group',
                text: 'Select a financial year and a balance sheet parent account to display transaction accounts for entry.',
            };
        }

        if (state.loading) {
            return null;
        }

        if (! state.hasLoaded) {
            return {
                title: 'Ready to load',
                text: 'Select a financial year and parent account — balances will load automatically.',
            };
        }

        if (formData.accounts.length > 0 && visibleAccounts.value.length === 0) {
            return {
                title: 'No accounts match the selected filter',
                text: 'Clear the search to show every loaded account.',
            };
        }

        return {
            title: 'No accounts found',
            text: 'There are no transaction accounts under the selected parent account. Try choosing a different parent account group.',
        };
    });

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        formData.branch_id = '';
        formData.financial_id = '';
        formData.account_id = '';
        resetAccounts();
        await fetchBranch(companyId);
        await fetchFinancialYears(companyId);
    }

    async function handleBranchChange() {
        formData.financial_id = '';
        formData.account_id = '';
        resetAccounts();

        if (filterReady.value) {
            await fetchObAccounts(resolvedCompanyId.value, resolvedBranchId.value);
        }
    }

    async function handleLoadBalances() {
        if (! canLoadBalances.value) {
            return;
        }

        await fetchBalance(resolvedCompanyId.value, resolvedBranchId.value);
    }

    async function handleSave() {
        if (! canSave.value) {
            return;
        }

        await saveBalances(resolvedCompanyId.value, resolvedBranchId.value);
    }

    function clearFilters() {
        formData.company_id = authUser.value?.company_id ?? '';
        formData.branch_id = authUser.value?.branch_id ?? '';
        formData.financial_id = '';
        formData.account_id = '';
        searchQuery.value = '';
        resetAccounts();
    }

    onMounted(async () => {
        formData.company_id = authUser.value?.company_id ?? '';
        formData.branch_id = authUser.value?.branch_id ?? '';

        if (showCompanyFilter.value) {
            await fetchCompany();
        }

        if (isCompanyadmin.value && authUser.value?.company_id) {
            formData.company_id = String(authUser.value.company_id);
            await fetchBranch(authUser.value.company_id);
        }

        if (resolvedCompanyId.value) {
            await fetchFinancialYears(resolvedCompanyId.value);
        }

        if (filterReady.value) {
            await fetchObAccounts(resolvedCompanyId.value, resolvedBranchId.value);
        }
    });

    watch(
        () => formData.company_id,
        async (companyId, previousCompanyId) => {
            if (companyId === previousCompanyId) {
                return;
            }

            if (! companyId) {
                formData.financial_id = '';
                formData.account_id = '';
                resetAccounts();
            }
        },
    );

    watch(
        () => [formData.financial_id, formData.account_id] as const,
        async ([financialId, accountId]) => {
            if (! filterReady.value || ! financialId || ! accountId) {
                resetAccounts();

                return;
            }

            await fetchBalance(resolvedCompanyId.value, resolvedBranchId.value);
        },
    );
</script>

<template>
    <Head :title="formatedText(props.routeName)" />

    <div class="product-form-view ob-page min-h-screen bg-slate-50/60 pb-8 font-sans text-slate-800">
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
                            <span class="font-semibold text-teal-800">Opening Balance</span>
                        </div>
                        <h1 class="flex items-center gap-2 text-lg font-bold tracking-tight text-slate-900 sm:text-xl">
                            Opening Balance
                            <span class="rounded-full border border-teal-200/80 bg-teal-50 px-2 py-0.5 text-xs font-semibold text-teal-700">
                                Fiscal Onboarding
                            </span>
                        </h1>
                    </div>

                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-teal-700 px-4 py-1.5 text-xs font-semibold text-white shadow-xs transition-all hover:bg-teal-800 disabled:opacity-50"
                        :disabled="!canSave"
                        @click="handleSave"
                    >
                        <span v-if="state.saving" class="spinner-border spinner-border-sm" role="status" />
                        <Save v-else class="h-3.5 w-3.5" />
                        <span>{{ state.saving ? 'Saving…' : 'Save Opening Balances' }}</span>
                    </button>
                </div>
            </div>

            <div class="space-y-4 py-5">
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-4 py-2.5">
                        <div class="flex items-center gap-2">
                            <SlidersHorizontal class="h-4 w-4 text-teal-700" />
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-800">
                                Financial Scope & Ledger Parameters
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-semibold transition-all"
                                :class="filterOpen
                                    ? 'border-teal-200 bg-teal-50 text-teal-800'
                                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                                @click="filterOpen = !filterOpen"
                            >
                                <Filter class="h-3.5 w-3.5 text-teal-600" />
                                <span>Filter</span>
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer rounded-md border border-slate-200 p-1.5 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800 disabled:opacity-50"
                                title="Reload opening balances"
                                :disabled="!canLoadBalances || state.loading"
                                @click="handleLoadBalances"
                            >
                                <RotateCw class="h-3.5 w-3.5" :class="{ 'animate-spin': state.loading }" />
                            </button>
                        </div>
                    </div>

                    <div v-if="filterOpen" class="flex flex-wrap items-end gap-3 px-4 py-3">
                        <div v-if="showCompanyFilter" class="form-field w-full max-w-[15.5rem] sm:w-[15.5rem]">
                            <label for="ob-filter-company">
                                <FieldHint label="Company" required>
                                    Select the company whose ledgers will receive these opening balances.
                                </FieldHint>
                            </label>
                            <select
                                id="ob-filter-company"
                                class="ob-scope-select"
                                v-model="formData.company_id"
                                @change="handleCompanyChange(formData.company_id)"
                            >
                                <option value="">Select company</option>
                                <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                                    {{ company.text ?? company.name }}
                                </option>
                            </select>
                        </div>

                        <div v-if="showBranchFilter" class="form-field w-full max-w-[15.5rem] sm:w-[15.5rem]">
                            <label for="ob-filter-branch">
                                <FieldHint label="Branch" required>
                                    Required. Opening balances are stored per branch.
                                </FieldHint>
                            </label>
                            <select
                                id="ob-filter-branch"
                                class="ob-scope-select"
                                v-model="formData.branch_id"
                                :disabled="branchFilterDisabled"
                                @change="handleBranchChange"
                            >
                                <option value="">Select branch</option>
                                <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">
                                    {{ branch.text ?? branch.name }}
                                </option>
                            </select>
                        </div>

                        <div class="form-field w-full max-w-[16.5rem] sm:w-[16.5rem]">
                            <label for="ob-filter-financial-year">
                                <FieldHint label="Financial Year" required>
                                    Opening balances apply to the selected financial year only.
                                </FieldHint>
                            </label>
                            <select
                                id="ob-filter-financial-year"
                                class="ob-scope-select"
                                v-model="formData.financial_id"
                                :disabled="! resolvedCompanyId"
                            >
                                <option value="">Select year</option>
                                <option v-for="year in financialYears" :key="year.id" :value="year.id">
                                    {{ year.text ?? year.name }}
                                </option>
                            </select>
                        </div>

                        <div class="form-field w-full max-w-[18rem] sm:w-[18rem]">
                            <label for="ob-filter-account">
                                <FieldHint label="Parent Account" required>
                                    Choose a balance-sheet parent to load its transactional child accounts.
                                </FieldHint>
                            </label>
                            <select
                                id="ob-filter-account"
                                class="ob-scope-select"
                                v-model="formData.account_id"
                                :disabled="! filterReady"
                            >
                                <option value="">Select account</option>
                                <option v-for="account in obAccounts" :key="account.id" :value="account.id">
                                    {{ account.text ?? account.name }}
                                </option>
                            </select>
                        </div>

                        <div class="ml-auto flex shrink-0 items-center gap-2 pb-0.5">
                            <button
                                type="button"
                                class="cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50 hover:text-slate-900"
                                @click="clearFilters"
                            >
                                Reset
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer rounded-lg bg-teal-700 px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs transition-colors hover:bg-teal-800 disabled:opacity-50"
                                :disabled="!canLoadBalances || state.loading"
                                @click="handleLoadBalances"
                            >
                                Apply
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-col justify-between gap-3 border-t border-slate-200/80 bg-slate-50/80 px-4 py-2.5 text-xs md:flex-row md:items-center">
                        <div class="flex items-center gap-2 text-slate-600">
                            <span class="inline-block h-2 w-2 rounded-full bg-teal-600"></span>
                            <span>
                            Enter opening balances for
                                <strong class="font-bold text-slate-900">
                                    {{ visibleAccounts.length }} {{ visibleAccounts.length === 1 ? 'account' : 'accounts' }}
                                </strong>
                                under
                                <strong class="font-semibold text-teal-900">{{ selectedAccountLabel }}</strong>
                                · <span class="text-slate-500">{{ selectedBranchLabel }}</span>
                                · <span class="text-slate-500">{{ selectedFinancialYearLabel }}</span>
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 font-medium text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                                Accounts <strong class="ml-1 text-slate-900">{{ visibleAccounts.length }}</strong>
                            </span>
                            <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 font-medium text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                                Visible Total
                                <strong class="ml-1 font-mono text-teal-800">{{ money(visibleTotal) }}</strong>
                            </span>
                            <span
                                v-if="formData.accounts.length > 0 && isBalanced"
                                class="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800"
                            >
                                <CheckCircle2 class="h-3.5 w-3.5 text-emerald-600" />
                                <span>Ledger Balanced</span>
                            </span>
                            <span
                                v-else-if="formData.accounts.length > 0"
                                class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-900"
                            >
                                <AlertTriangle class="h-3.5 w-3.5 text-amber-600" />
                                <span>Diff: {{ money(difference) }}</span>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div class="flex items-center justify-end border-b border-slate-100 px-4 py-2">
                        <div class="relative w-full sm:w-64">
                            <Search class="absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                            <input
                                type="text"
                                placeholder="Search code or account..."
                                class="w-full rounded-lg border border-slate-200 bg-slate-50 py-1.5 pr-12 pl-9 text-xs text-slate-800 placeholder:text-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-500/20 focus:outline-none"
                                v-model="searchQuery"
                            >
                            <button
                                v-if="searchQuery"
                                type="button"
                                class="absolute top-1/2 right-2.5 -translate-y-1/2 text-[10px] text-slate-400 hover:text-slate-600"
                                @click="searchQuery = ''"
                            >
                                Clear
                            </button>
                        </div>
                    </div>
                    <div v-if="state.loading" class="px-4 py-12 text-center text-slate-400">
                        <RotateCw class="mx-auto mb-2 h-8 w-8 animate-spin text-slate-300" />
                        <p class="font-semibold text-slate-600">Loading accounts</p>
                        <p class="mt-1 text-[11px] text-slate-400">Fetching transaction accounts and existing opening balances…</p>
                    </div>

                    <div v-else-if="formData.accounts.length === 0 && emptyState" class="px-4 py-12 text-center text-slate-400">
                        <FolderTree class="mx-auto mb-2 h-8 w-8 text-slate-300" />
                        <p class="font-semibold text-slate-600">{{ emptyState.title }}</p>
                        <p class="mt-1 text-[11px] text-slate-400">{{ emptyState.text }}</p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[760px] border-collapse text-left">
                            <thead>
                                <tr class="select-none border-b border-slate-200 bg-slate-50/90 text-[11px] font-bold tracking-wider text-slate-600 uppercase">
                                    <th class="w-44 px-4 py-3">Account code</th>
                                    <th class="min-w-[280px] px-4 py-3">Account name</th>
                                    <th class="w-36 px-4 py-3">Category</th>
                                    <th class="w-52 px-4 py-3 text-right">Opening balance</th>
                                    <th class="w-40 px-4 py-3 text-center">Account nature</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-xs">
                                <tr v-if="visibleAccounts.length === 0">
                                    <td colspan="5" class="px-4 py-12 text-center text-slate-400">
                                        <FolderTree class="mx-auto mb-2 h-8 w-8 text-slate-300" />
                                        <p class="font-semibold text-slate-600">No accounts match the selected filter</p>
                                        <p class="mt-1 text-[11px] text-slate-400">Clear your search to show every loaded account.</p>
                                        <button
                                            type="button"
                                            class="mt-3 cursor-pointer rounded-lg bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-200"
                                            @click="searchQuery = ''"
                                        >
                                            Clear search
                                        </button>
                                    </td>
                                </tr>
                                <tr
                                    v-for="(account, index) in visibleAccounts"
                                    :key="account.id"
                                    class="group transition-colors hover:bg-teal-50/30"
                                    :class="index % 2 === 0 ? 'bg-white' : 'bg-slate-50/30'"
                                >
                                    <td class="px-4 py-3.5 font-mono font-bold whitespace-nowrap text-teal-800">
                                {{ account.code ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <div class="font-semibold text-slate-900 transition-colors group-hover:text-teal-900">
                                {{ account.name ?? '—' }}
                            </div>
                                        <div class="mt-0.5 text-[11px] text-slate-400">
                                            {{ selectedAccountLabel }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3.5 whitespace-nowrap">
                                        <span
                                            class="inline-block rounded border px-2 py-0.5 text-[11px] font-semibold"
                                            :class="{
                                                'border-blue-200/80 bg-blue-50 text-blue-700': accountCategory(account) === 'Assets',
                                                'border-amber-200/80 bg-amber-50 text-amber-800': accountCategory(account) === 'Liabilities',
                                                'border-purple-200/80 bg-purple-50 text-purple-700': accountCategory(account) === 'Equity',
                                                'border-slate-200 bg-slate-100 text-slate-700': !['Assets', 'Liabilities', 'Equity'].includes(accountCategory(account)),
                                            }"
                                        >
                                            {{ accountCategory(account) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="relative inline-block w-44">
                                            <span class="absolute top-1/2 left-2.5 -translate-y-1/2 select-none text-[11px] font-bold text-slate-400">
                                                {{ currencySymbol || '' }}
                                            </span>
                                <input
                                    type="text"
                                    inputmode="decimal"
                                                class="w-full rounded-lg border border-slate-200 bg-white py-1.5 pr-2.5 text-right font-mono text-xs font-semibold text-slate-900 transition-all hover:border-slate-300 focus:border-teal-600 focus:ring-2 focus:ring-teal-500/20 focus:outline-none"
                                                :class="currencySymbol ? 'pl-7' : 'pl-2.5'"
                                    v-model="account.opening_balance"
                                    :name="`opening_balance[${index}]`"
                                                placeholder="0.00"
                                >
                            </div>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                <select
                                            class="cursor-pointer rounded-lg border px-3 py-1.5 text-xs font-bold shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-all"
                                            :class="account.acc_nature === 'dr'
                                                ? 'border-teal-200 bg-teal-50 text-teal-800 hover:bg-teal-100'
                                                : 'border-indigo-200 bg-indigo-50 text-indigo-800 hover:bg-indigo-100'"
                                    v-model="account.acc_nature"
                                    :name="`acc_nature[${index}]`"
                                >
                                            <option value="dr">Debit</option>
                                    <option value="cr">Credit</option>
                                </select>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2 border-slate-300/80 bg-slate-100/80 text-xs font-bold text-slate-800">
                                    <td colspan="3" class="px-4 py-3.5 font-bold tracking-wide text-slate-900">
                                        Grand Total
                                    </td>
                                    <td class="px-4 py-3.5 text-right font-mono text-sm font-bold text-teal-800">
                                        {{ money(visibleTotal) }}
                                    </td>
                                    <td class="px-4 py-3.5 text-center">
                                        <span class="text-[11px] font-semibold text-slate-500">
                                            {{ visibleAccounts.length }} Lines Included
                                        </span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                            </div>

                <div v-if="formData.accounts.length > 0" class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                        <div class="space-y-0.5">
                            <span class="flex items-center gap-1.5 text-[11px] font-bold tracking-wider text-slate-500 uppercase">
                                <TrendingUp class="h-3.5 w-3.5 text-teal-600" />
                                <span>Total Debits (Assets & Outlays)</span>
                            </span>
                            <div class="font-mono text-lg font-bold text-slate-900">{{ money(totalDebit) }}</div>
                        </div>
                        <div class="rounded-lg bg-teal-50 p-2.5 text-teal-700">
                            <Scale class="h-5 w-5" />
                        </div>
                    </div>
                    <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                        <div class="space-y-0.5">
                            <span class="flex items-center gap-1.5 text-[11px] font-bold tracking-wider text-slate-500 uppercase">
                                <TrendingDown class="h-3.5 w-3.5 text-indigo-600" />
                                <span>Total Credits (Liabilities & Equity)</span>
                            </span>
                            <div class="font-mono text-lg font-bold text-slate-900">{{ money(totalCredit) }}</div>
                        </div>
                        <div class="rounded-lg bg-indigo-50 p-2.5 text-indigo-700">
                            <Scale class="h-5 w-5" />
                </div>
                    </div>
                    <div
                        class="flex items-center justify-between rounded-xl border p-4 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        :class="isBalanced ? 'border-emerald-200 bg-emerald-50/60' : 'border-amber-200 bg-amber-50/60'"
                    >
                        <div class="space-y-0.5">
                            <span class="text-[11px] font-bold tracking-wider text-slate-600 uppercase">Ledger Variance / Difference</span>
                            <div class="font-mono text-lg font-bold" :class="isBalanced ? 'text-emerald-800' : 'text-amber-900'">
                                {{ money(difference) }}
                            </div>
                            <div class="text-[11px] font-medium text-slate-500">
                                {{ isBalanced ? 'Double-entry books are perfectly balanced' : 'Debits and credits do not yet match' }}
                            </div>
                        </div>
                        <div
                            class="rounded-lg p-2.5"
                            :class="isBalanced ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
                        >
                            <CheckCircle2 v-if="isBalanced" class="h-5 w-5" />
                            <AlertTriangle v-else class="h-5 w-5" />
                </div>
                    </div>
                </div>
                </div>
            </div>
        </div>
</template>

<style scoped>
.ob-scope-select {
    width: 100%;
    min-height: var(--form-control-height);
    padding: 0.375rem 0.625rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    background: #fff;
    color: #1e293b;
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
}

.ob-scope-select:hover:not(:disabled) {
    background: #f8fafc;
}

.ob-scope-select:focus {
    border-color: #0d9488;
    outline: none;
    box-shadow: 0 0 0 2px rgb(20 184 166 / 0.2);
}

.ob-scope-select:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}
</style>
