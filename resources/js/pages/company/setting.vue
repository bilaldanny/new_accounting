<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import Fields from './setting/Fields.vue';
    import SettingTabsNav from './setting/SettingTabsNav.vue';
    import FinancialYearTab from './setting/tabs/FinancialYearTab.vue';
    import LinkAccountsTab from './setting/tabs/LinkAccountsTab.vue';
    import PurchaseSettingTab from './setting/tabs/PurchaseSettingTab.vue';
    import SellSettingTab from './setting/tabs/SellSettingTab.vue';
    import TaxTab from './setting/tabs/TaxTab.vue';
    import { isStandaloneSettingTab, settingTabs } from './setting/settingTabs';
    import useCompanySettings from '@/composables/companySetting';
    import useCommons from '@/composables/common';
    import { company } from '@/routes';
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, nextTick, onMounted, ref, watch } from 'vue';

    defineOptions({
        layout: {
            title: 'Company Setting',
            subtitle: 'Manage business configuration',
            breadcrumbs: [
                {
                    title: 'Company Management',
                    href: company().url,
                },
                {
                    title: 'Setting',
                    href: 'NULL',
                },
            ],
        },
    });

    const page = usePage();

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
        company_name?: string;
    } | null);

    const isSuperadmin = computed(() => normalizeRoleName(authUser.value?.rolename) === 'superadmin');
    const isCompanyadmin = computed(() => normalizeRoleName(authUser.value?.rolename) === 'companyadmin');
    const showCompanyFilter = computed(() => isSuperadmin.value);
    const showBranchFilter = computed(() => isSuperadmin.value || isCompanyadmin.value);

    const {
        loading,
        saving,
        formData,
        defaultFormData,
        currenciesdata,
        timezonesdata,
        branchesdata,
        customersdata,
        parentaccountdata,
        parentsaleaccountdata,
        parentpurchaseaccountdata,
        fetchCurrencies,
        fetchTimezones,
        loadBranchOptions,
        fetchCustomers,
        fetchParentAccounts,
        getCompanySetting,
        saveCompanySetting,
    } = useCompanySettings();

    const { handleError } = useCommons();

    const formRef = ref(null);
    const activeTab = ref('business');
    const pageReady = ref(false);

    const selectedCompanyId = computed(() => {
        const companyId = formData.value?.company_id;

        return companyId === null || companyId === undefined || companyId === ''
            ? ''
            : String(companyId);
    });

    const logoUrl = computed(() => String(formData.value?.logo_url ?? ''));

    const canEditSettings = computed(() => {
        if (isSuperadmin.value) {
            return Boolean(selectedCompanyId.value);
        }

        return Boolean(authUser.value?.company_id);
    });

    const scopeSummary = computed(() => {
        if (isSuperadmin.value) {
            return selectedCompanyId.value ? 'Company selected' : 'Select a company';
        }

        return authUser.value?.company_name || 'Current company';
    });

    const isStandaloneTab = computed(() => isStandaloneSettingTab(activeTab.value));

    const showSaveButton = computed(() => {
        return activeTab.value !== 'tax' && activeTab.value !== 'financialYear';
    });

    const activeTabMeta = computed(() =>
        settingTabs.find((tab) => tab.id === activeTab.value) ?? settingTabs[0],
    );

    function applyScopedDefaults() {
        if (isSuperadmin.value || !authUser.value?.company_id) {
            return;
        }

        formData.value.company_id = authUser.value.company_id;

        if (authUser.value.branch_id && !isCompanyadmin.value) {
            formData.value.branch_id = authUser.value.branch_id;
        }

        formRef.value?.update?.({
            company_id: formData.value.company_id,
            branch_id: formData.value.branch_id,
        });
    }

    async function reloadBranchScopedData(companyId: string, branchId: string | number) {
        if (!companyId || !branchId) {
            customersdata.value = [];
            parentaccountdata.value = [];
            parentsaleaccountdata.value = [];
            parentpurchaseaccountdata.value = [];
            return;
        }

        await Promise.all([
            fetchCustomers(companyId, branchId),
            fetchParentAccounts(companyId, branchId),
        ]);
    }

    async function loadSettings(companyId: string, branchId: string | number = '') {
        if (!companyId) {
            formData.value = { ...defaultFormData };
            formRef.value?.reset?.();
            return;
        }

        await getCompanySetting(companyId, branchId);
        await nextTick();
        formRef.value?.update?.({ ...formData.value });

        const effectiveBranchId = branchId || formData.value.branch_id;

        if (effectiveBranchId) {
            await reloadBranchScopedData(companyId, effectiveBranchId);
        }
    }

    async function handleCompanyChange(companyId: unknown) {
        const normalizedCompanyId =
            companyId === null || companyId === undefined || companyId === ''
                ? ''
                : String(companyId);

        if (!normalizedCompanyId) {
            formData.value = { ...defaultFormData };
            formRef.value?.reset?.();
            return;
        }

        formData.value.company_id = normalizedCompanyId;
        formData.value.branch_id = '';

        await loadBranchOptions(normalizedCompanyId);
        await loadSettings(normalizedCompanyId);
    }

    async function handleBranchChange(branchId: unknown) {
        const normalizedBranchId =
            branchId === null || branchId === undefined || branchId === ''
                ? ''
                : String(branchId);

        const companyId = selectedCompanyId.value;

        if (!companyId) {
            return;
        }

        formData.value.branch_id = normalizedBranchId;

        await loadSettings(companyId, normalizedBranchId);
    }

    async function initializePage() {
        pageReady.value = false;

        await Promise.all([
            fetchCurrencies(),
            fetchTimezones(),
        ]);

        if (isSuperadmin.value) {
            formData.value = { ...defaultFormData };
            pageReady.value = true;
            return;
        }

        applyScopedDefaults();

        const companyId = String(authUser.value?.company_id ?? '');

        if (!companyId) {
            pageReady.value = true;
            return;
        }

        if (isCompanyadmin.value) {
            await loadBranchOptions(companyId);
        }

        const branchId = formData.value.branch_id || authUser.value?.branch_id || '';
        await loadSettings(companyId, branchId ? String(branchId) : '');

        pageReady.value = true;
    }

    function onSaveSuccess(response: { companySetting?: Record<string, unknown>; message?: string }) {
        if (response?.companySetting) {
            formData.value = {
                ...formData.value,
                ...response.companySetting,
            };
            formRef.value?.update?.({ ...formData.value });
        }
    }

    function handleFormError(error: unknown, details?: unknown) {
        handleError(error, details, formRef);
    }

    async function handleFormSubmit(form$: { data?: Record<string, unknown> }) {
        const companyId = selectedCompanyId.value;

        if (!companyId) {
            saving.value = false;
            if (formRef.value?.isSubmitting !== undefined) {
                formRef.value.isSubmitting = false;
            }
            return;
        }

        try {
            const payload = {
                ...formData.value,
                ...(form$?.data ?? {}),
            };

            const response = await saveCompanySetting(companyId, payload);
            onSaveSuccess(response);
        } catch (error) {
            if (window.axios.isAxiosError(error) && error.response?.data?.errors) {
                handleFormError(error, { type: 'submit' });
            }
        } finally {
            saving.value = false;
            if (formRef.value?.isSubmitting !== undefined) {
                formRef.value.isSubmitting = false;
            }
        }
    }

    async function handleSaveClick() {
        const companyId = selectedCompanyId.value;

        if (!companyId) {
            return;
        }

        if (isStandaloneTab.value) {
            saving.value = true;

            try {
                const response = await saveCompanySetting(companyId, { ...formData.value });
                onSaveSuccess(response);
            } catch (error) {
                if (window.axios.isAxiosError(error) && error.response?.data?.errors) {
                    handleFormError(error, { type: 'submit' });
                }
            } finally {
                saving.value = false;
            }

            return;
        }

        await formRef.value?.submitForm();
    }

    onMounted(() => {
        void initializePage();
    });

    watch(activeTab, async () => {
        await nextTick();
        formRef.value?.update?.({ ...formData.value });
    });
</script>

<template>
    <Head title="Company Setting" />

    <div class="company-setting-page">
        <section class="card custom-card company-setting-page__card">
            <div class="card-body company-setting-page__card-body">
                <div v-if="pageReady && !loading" class="company-setting-page__header">
                    <div class="company-setting-page__header-main">
                        <div class="company-setting-page__header-icon" aria-hidden="true">
                            <component :is="activeTabMeta.icon" size="md" />
                        </div>
                        <div>
                            <h2 class="company-setting-page__title">{{ activeTabMeta.label }}</h2>
                            <p class="company-setting-page__subtitle">
                                Manage company configuration
                                <span class="company-setting-page__scope-badge">{{ scopeSummary }}</span>
                            </p>
                        </div>
                    </div>
                </div>

                <Loader v-if="!pageReady || loading" message="Loading company settings…" />

                <div v-else-if="canEditSettings" class="company-setting-shell">
                    <SettingTabsNav v-model:active-tab="activeTab" />

                    <div class="company-setting-shell__main">
                        <TaxTab
                            v-if="activeTab === 'tax'"
                            :company-id="selectedCompanyId"
                            :is-superadmin="isSuperadmin"
                        />

                        <FinancialYearTab
                            v-else-if="activeTab === 'financialYear'"
                            :company-id="selectedCompanyId"
                            :is-superadmin="isSuperadmin"
                        />

                        <LinkAccountsTab
                            v-else-if="activeTab === 'linkAccounts'"
                            :form-data="formData"
                            :branchesdata="branchesdata"
                            :parentaccountdata="parentaccountdata"
                            :parentsaleaccountdata="parentsaleaccountdata"
                            :parentpurchaseaccountdata="parentpurchaseaccountdata"
                            :show-branch-filter="showBranchFilter"
                            @branch-change="handleBranchChange"
                        />

                        <PurchaseSettingTab
                            v-else-if="activeTab === 'purchaseSetting'"
                            :form-data="formData"
                        />

                        <SellSettingTab
                            v-else-if="activeTab === 'sellSetting'"
                            :form-data="formData"
                            :branchesdata="branchesdata"
                            :customersdata="customersdata"
                            :show-branch-filter="showBranchFilter"
                            @branch-change="handleBranchChange"
                        />

                        <TheForm
                            v-show="!isStandaloneTab"
                            v-model:submitting="saving"
                            :form-data="formData"
                            :on-submit="handleFormSubmit"
                            :error="handleFormError"
                            ref="formRef"
                        >
                            <Fields
                                :active-tab="activeTab"
                                :logo-url="logoUrl"
                                :currenciesdata="currenciesdata"
                                :timezonesdata="timezonesdata"
                                :show-company-filter="showCompanyFilter"
                                :is-companyadmin="isCompanyadmin"
                                :form-data="formData"
                                :form-ref="formRef"
                                @company-change="handleCompanyChange"
                            />
                        </TheForm>

                        <div v-if="showSaveButton" class="company-setting-page__actions">
                            <button
                                type="button"
                                class="btn btn-primary company-setting-page__save-btn"
                                :disabled="saving || (isSuperadmin && !selectedCompanyId)"
                                :aria-busy="saving"
                                @click="handleSaveClick()"
                            >
                                <span
                                    v-if="saving"
                                    class="spinner-border spinner-border-sm me-2"
                                    role="status"
                                    aria-hidden="true"
                                ></span>
                                {{ saving ? 'Saving…' : 'Save Settings' }}
                            </button>
                        </div>
                    </div>
                </div>

                <p v-else-if="pageReady && !loading && isSuperadmin" class="text-muted mb-0">
                    Select a company to load and edit its settings.
                </p>
            </div>
        </section>
    </div>
</template>

<style scoped>
.company-setting-page__card-body {
    padding: 1.5rem;
}

.company-setting-page__header {
    margin-bottom: 1.25rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid #eef2f7;
}

.company-setting-page__header-main {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.company-setting-page__header-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3rem;
    height: 3rem;
    border-radius: 0.875rem;
    background: rgba(25, 150, 131, 0.1);
    color: var(--accent-dark, #199683);
}

.company-setting-page__title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-main, #111827);
    margin: 0 0 0.25rem;
}

.company-setting-page__subtitle {
    font-size: 0.875rem;
    color: var(--text-muted, #6b7280);
    margin: 0;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

.company-setting-page__scope-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.2rem 0.625rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--accent-dark, #199683);
    background: rgba(25, 150, 131, 0.1);
    border: 1px solid rgba(25, 150, 131, 0.18);
}

.company-setting-shell {
    display: flex;
    align-items: flex-start;
    gap: 1.25rem;
    width: 100%;
}

.company-setting-shell__main {
    flex: 1 1 0;
    min-width: 0;
    width: 100%;
}

@media (max-width: 767.98px) {
    .company-setting-shell {
        flex-direction: column;
    }
}
</style>
