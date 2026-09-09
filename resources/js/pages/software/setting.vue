<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { computed, nextTick, onMounted, ref, watch } from 'vue';
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import useCommons from '@/composables/common';
    import useSoftwareSettings from '@/composables/softwareSetting';
    import Fields from './setting/Fields.vue';
    import { softwareSettingTabs } from './setting/settingTabs';
    import SettingTabsNav from './setting/SettingTabsNav.vue';

    defineOptions({
        layout: {
            title: 'Software Setting',
            subtitle: 'Manage application configuration',
            breadcrumbs: [
                {
                    title: 'Software Setting',
                    href: 'NULL',
                },
            ],
        },
    });

    const {
        loading,
        saving,
        testingSmtp,
        sendingTest,
        formData,
        getSoftwareSetting,
        saveSoftwareSetting,
        testSmtpConnection,
        sendTestEmail,
    } = useSoftwareSettings();

    const { handleError } = useCommons();

    const formRef = ref(null);
    const activeTab = ref('general');
    const pageReady = ref(false);

    const logoUrl = computed(() => String(formData.value?.system_logo_url ?? ''));
    const emailLogoUrl = computed(() => String(formData.value?.email_logo_url ?? ''));
    const loginLogoUrl = computed(() => String(formData.value?.login_logo_url ?? ''));
    const hasSmtpPassword = computed(() => Boolean(formData.value?.has_smtp_password));

    const activeTabMeta = computed(() =>
        softwareSettingTabs.find((tab) => tab.id === activeTab.value) ?? softwareSettingTabs[0],
    );

    async function initializePage() {
        pageReady.value = false;
        await getSoftwareSetting();
        await nextTick();
        formRef.value?.update?.({ ...formData.value });
        pageReady.value = true;
    }

    function onSaveSuccess(response: { softwareSetting?: Record<string, unknown> }) {
        if (response?.softwareSetting) {
            formRef.value?.update?.({ ...formData.value });
        }
    }

    function handleFormError(error: unknown, details?: unknown) {
        handleError(error, details, formRef);
    }

    async function handleFormSubmit(form$: { data?: Record<string, unknown> }) {
        try {
            const payload = {
                ...formData.value,
                ...(form$?.data ?? {}),
            };

            const response = await saveSoftwareSetting(payload);
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
        await formRef.value?.submitForm();
    }

    async function handleTestSmtp() {
        await testSmtpConnection({ ...formData.value });
    }

    async function handleTestSend() {
        await sendTestEmail({ ...formData.value });
    }

    onMounted(() => {
        void initializePage();
    });

    watch(activeTab, async () => {
        const snapshot = { ...formData.value };

        await nextTick();
        await nextTick();
        formRef.value?.update?.(snapshot);
    });
</script>

<template>
    <Head title="Software Setting" />

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
                                Manage software configuration
                                <span class="company-setting-page__scope-badge">Global</span>
                            </p>
                        </div>
                    </div>
                </div>

                <Loader v-if="!pageReady || loading" message="Loading software settings…" />

                <div v-else class="company-setting-shell">
                    <SettingTabsNav v-model:active-tab="activeTab" />

                    <div class="company-setting-shell__main">
                        <TheForm
                            v-model:submitting="saving"
                            :form-data="formData"
                            :on-submit="handleFormSubmit"
                            :error="handleFormError"
                            ref="formRef"
                        >
                            <Fields
                                :active-tab="activeTab"
                                :logo-url="logoUrl"
                                :email-logo-url="emailLogoUrl"
                                :login-logo-url="loginLogoUrl"
                                :testing-smtp="testingSmtp"
                                :sending-test="sendingTest"
                                :has-smtp-password="hasSmtpPassword"
                                @test-smtp="handleTestSmtp"
                                @test-send="handleTestSend"
                            />
                        </TheForm>

                        <div class="company-setting-page__actions">
                            <button
                                type="button"
                                class="btn btn-primary company-setting-page__save-btn"
                                :disabled="saving"
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
