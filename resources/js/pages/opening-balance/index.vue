<script setup lang="ts">
    import { computed, onMounted, ref, watch } from 'vue';
    import { Head, usePage } from '@inertiajs/vue3';
    import TopButtons from '@/components/topButtons.vue';
    import TheFilter from '@/components/theFilter.vue';
    import useCommons from '@/composables/common';
    import useAccountBalances from '@/composables/accountBalance';
    import { Filter, LoaderLinesAlt, PieChart, Receipt, Wallet } from '@boxicons/vue';

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
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyFilter = computed(() => isSuperadmin.value);
    const showBranchFilter = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const branchFilterDisabled = computed(() => showCompanyFilter.value && ! formData.company_id);

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

    const formattedGrandTotal = computed(() =>
        Number(grandTotal.value).toLocaleString(undefined, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        }),
    );

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

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar">
                <TopButtons
                    :state="state"
                    :filter-open="filterOpen"
                    :getData="handleLoadBalances"
                    :url="`${props.routeName?.split('.')[0]}`"
                    :show-add="false"
                    :show-import="false"
                    :show-status="false"
                    @toggle-filter="filterOpen = !filterOpen"
                />
            </div>

            <TheFilter
                v-model:open="filterOpen"
                :loading="state.loading"
                @clear="clearFilters"
                @search="handleLoadBalances"
            >
                <div v-if="showCompanyFilter" class="col-md-6 col-lg-3 admin-filter-field">
                    <label class="form-label" for="ob-filter-company">Company</label>
                    <select
                        id="ob-filter-company"
                        class="form-select form-select-sm"
                        v-model="formData.company_id"
                        @change="handleCompanyChange(formData.company_id)"
                    >
                        <option value="">Select company</option>
                        <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                            {{ company.text ?? company.name }}
                        </option>
                    </select>
                </div>

                <div v-if="showBranchFilter" class="col-md-6 col-lg-3 admin-filter-field">
                    <label class="form-label" for="ob-filter-branch">Branch</label>
                    <select
                        id="ob-filter-branch"
                        class="form-select form-select-sm"
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

                <div class="col-md-6 col-lg-3 admin-filter-field">
                    <label class="form-label" for="ob-filter-financial-year">Financial Year</label>
                    <select
                        id="ob-filter-financial-year"
                        class="form-select form-select-sm"
                        v-model="formData.financial_id"
                        :disabled="! resolvedCompanyId"
                    >
                        <option value="">Select year</option>
                        <option v-for="year in financialYears" :key="year.id" :value="year.id">
                            {{ year.text ?? year.name }}
                        </option>
                    </select>
                </div>

                <div class="col-md-6 col-lg-3 admin-filter-field">
                    <label class="form-label" for="ob-filter-account">Parent Account</label>
                    <select
                        id="ob-filter-account"
                        class="form-select form-select-sm"
                        v-model="formData.account_id"
                        :disabled="! filterReady"
                    >
                        <option value="">Select account</option>
                        <option v-for="account in obAccounts" :key="account.id" :value="account.id">
                            {{ account.text ?? account.name }}
                        </option>
                    </select>
                </div>
            </TheFilter>

            <div class="admin-list-card__body">
                <div v-if="formData.accounts.length > 0" class="ob-panel">
                    <div class="ob-panel__summary">
                        <p class="ob-panel__summary-text">
                            Enter opening balances for
                            <strong>{{ formData.accounts.length }}</strong>
                            {{ formData.accounts.length === 1 ? 'account' : 'accounts' }} under
                            <strong>{{ selectedAccountLabel }}</strong>
                            · {{ selectedBranchLabel }} · {{ selectedFinancialYearLabel }}
                        </p>

                        <div class="ob-panel__stats">
                            <span class="ob-stat-badge">
                                Accounts <strong>{{ formData.accounts.length }}</strong>
                            </span>
                            <span class="ob-stat-badge">
                                Total <strong>{{ formattedGrandTotal }}</strong>
                            </span>
                        </div>
                    </div>

                    <div class="ob-table-shell">
                        <div class="ob-table-head d-none d-md-grid">
                            <div>Account Code</div>
                            <div>Account Name</div>
                            <div class="text-end">Opening Balance</div>
                            <div class="text-end">Account Nature</div>
                        </div>

                        <div
                            v-for="(account, index) in formData.accounts"
                            :key="account.id"
                            class="ob-table-row"
                        >
                            <div class="ob-table-cell--code">
                                {{ account.code ?? '—' }}
                            </div>
                            <div class="ob-table-cell--name">
                                {{ account.name ?? '—' }}
                            </div>
                            <div class="ob-table-cell--amount">
                                <input
                                    type="text"
                                    inputmode="decimal"
                                    class="ob-amount-input"
                                    v-model="account.opening_balance"
                                    :name="`opening_balance[${index}]`"
                                    placeholder="0"
                                >
                            </div>
                            <div class="ob-table-cell--nature">
                                <select
                                    class="ob-nature-select"
                                    :class="account.acc_nature === 'cr' ? 'ob-nature-select--cr' : 'ob-nature-select--dr'"
                                    v-model="account.acc_nature"
                                    :name="`acc_nature[${index}]`"
                                >
                                    <option value="cr">Credit</option>
                                    <option value="dr">Debit</option>
                                </select>
                            </div>
                        </div>

                        <div class="ob-table-foot d-none d-md-grid">
                            <div class="ob-table-foot__label">Grand Total</div>
                            <div></div>
                            <div class="ob-table-foot__total text-end">{{ formattedGrandTotal }}</div>
                            <div></div>
                        </div>
                    </div>
                </div>

                <div v-else-if="state.loading" class="ob-empty-state">
                    <div class="ob-empty-state__icon">
                        <LoaderLinesAlt size="md" class="spin" />
                    </div>
                    <h3 class="ob-empty-state__title">Loading accounts</h3>
                    <p class="ob-empty-state__text">Fetching transaction accounts and existing opening balances…</p>
                </div>

                <div v-else-if="emptyState" class="ob-empty-state">
                    <div class="ob-empty-state__icon">
                        <PieChart v-if="! filterReady" size="md" />
                        <Filter v-else-if="! formData.financial_id || ! formData.account_id" size="md" />
                        <Receipt v-else size="md" />
                    </div>
                    <h3 class="ob-empty-state__title">{{ emptyState.title }}</h3>
                    <p class="ob-empty-state__text">{{ emptyState.text }}</p>
                </div>
            </div>

            <div v-if="formData.accounts.length > 0" class="admin-list-card__footer ob-footer">
                <p class="ob-footer__hint">
                    Changes apply to the selected financial year and can be updated at any time before posting transactions.
                </p>

                <div class="ob-footer__actions">
                    <div class="ob-footer__total d-none d-md-flex">
                        <span class="ob-footer__total-label">Grand Total</span>
                        <span class="ob-footer__total-value">{{ formattedGrandTotal }}</span>
                    </div>

                    <button
                        type="button"
                        class="ob-save-btn"
                        :disabled="! canSave"
                        @click="handleSave"
                    >
                        <span v-if="state.saving" class="spinner-border spinner-border-sm" role="status" />
                        <Wallet v-else size="sm" />
                        <span>{{ state.saving ? 'Saving…' : 'Save Opening Balances' }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.spin {
    animation: ob-spin 0.9s linear infinite;
}

@keyframes ob-spin {
    from {
        transform: rotate(0deg);
    }

    to {
        transform: rotate(360deg);
    }
}
</style>
