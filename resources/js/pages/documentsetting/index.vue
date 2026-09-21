<script setup lang="ts">
    import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    /**
     * One page for the barcode, invoice and receipt printer settings: the server describes the fields of
     * the group (App\Models\DocumentSetting) and this page draws and saves them.
     */
    const routeProps = defineProps({
        group: {
            required: true,
            type: String,
        },
    });

    const titles: Record<string, { title: string; subtitle: string }> = {
        barcode: { title: 'Barcode Settings', subtitle: 'Defaults for barcode labels' },
        invoice: { title: 'Invoice Settings', subtitle: 'Invoice number format, terms and footer' },
        receipt: { title: 'Receipt Printer Settings', subtitle: 'Printer type, paper size and receipt content' },
    };

    const heading = titles[routeProps.group] ?? { title: 'Settings', subtitle: '' };

    setLayoutProps({
        title: heading.title,
        subtitle: heading.subtitle,
        breadcrumbs: [
            {
                title: heading.title,
                href: 'NULL',
            },
        ],
    });

    type Field = {
        key: string;
        label: string;
        type: 'select' | 'number' | 'switch' | 'text' | 'textarea';
        options?: Record<string, string>;
        help?: string;
    };

    const page = usePage();
    const { Notify, fetchCompany, companiesdata } = useCommons();

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        permission_paths?: string[];
    } | null);

    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const canSave = computed(() => (authUser.value?.permission_paths ?? []).includes(`/${routeProps.group}/settings/update`) || isSuperadmin.value);

    const loading = ref(true);
    const saving = ref(false);
    const companyId = ref<number | string>(isSuperadmin.value ? '' : (authUser.value?.company_id ?? ''));
    const fields = ref<Field[]>([]);
    const values = ref<Record<string, string | number | boolean>>({});

    function errorMessage(error: unknown): string {
        if (! window.axios.isAxiosError(error)) {
            return 'Unexpected error occurred';
        }

        const errors = Object.values(error.response?.data?.errors ?? {}).flat() as string[];

        return errors[0] || error.response?.data?.message || 'The request failed.';
    }

    async function load() {
        if (! companyId.value) {
            loading.value = false;

            return;
        }

        loading.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.documentSettings(routeProps.group), { params: { company_id: companyId.value } });
            fields.value = response.data.fields;
            values.value = response.data.values;
        } catch {
            Notify('Unable to load the settings.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function save() {
        saving.value = true;

        try {
            const response = await window.axios.put(API_ENDPOINTS.documentSettings(routeProps.group), {
                company_id: companyId.value || null,
                ...values.value,
            });
            values.value = response.data.values;
            Notify(response.data?.message || 'Successfully Saved', 'success');
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            saving.value = false;
        }
    }

    onMounted(async () => {
        if (isSuperadmin.value) {
            await fetchCompany();
        }

        await load();
    });
</script>

<template>
    <Head :title="heading.title" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__body p-3 p-md-4">
                <div v-if="isSuperadmin" class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label" for="document-setting-company">Company</label>
                        <select id="document-setting-company" v-model="companyId" class="form-select form-select-sm" @change="load">
                            <option value="">Select company</option>
                            <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                                {{ company.text ?? company.name }}
                            </option>
                        </select>
                    </div>
                </div>

                <Loader v-if="loading" message="Loading settings…" />

                <form v-else-if="companyId" class="row g-3" @submit.prevent="save">
                    <div v-for="field in fields" :key="field.key" :class="field.type === 'textarea' ? 'col-12' : 'col-md-6 col-xl-4'">
                        <div v-if="field.type === 'switch'" class="form-check form-switch mt-4">
                            <input :id="`ds-${field.key}`" v-model="values[field.key]" type="checkbox" class="form-check-input" role="switch" :disabled="! canSave">
                            <label class="form-check-label" :for="`ds-${field.key}`">{{ field.label }}</label>
                        </div>

                        <template v-else>
                            <label class="form-label" :for="`ds-${field.key}`">{{ field.label }}</label>

                            <select v-if="field.type === 'select'" :id="`ds-${field.key}`" v-model="values[field.key]" class="form-select form-select-sm" :disabled="! canSave">
                                <option v-for="(text, value) in field.options" :key="value" :value="typeof values[field.key] === 'number' ? Number(value) : value">
                                    {{ text }}
                                </option>
                            </select>

                            <textarea v-else-if="field.type === 'textarea'" :id="`ds-${field.key}`" v-model="values[field.key] as string" rows="4" class="form-control form-control-sm" :disabled="! canSave"></textarea>

                            <input v-else-if="field.type === 'number'" :id="`ds-${field.key}`" v-model.number="values[field.key]" type="number" step="1" class="form-control form-control-sm" :disabled="! canSave">

                            <input v-else :id="`ds-${field.key}`" v-model="values[field.key] as string" type="text" class="form-control form-control-sm" :disabled="! canSave">
                        </template>

                        <p v-if="field.help" class="text-muted small mb-0">{{ field.help }}</p>
                    </div>

                    <div v-if="canSave" class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="saving">Save settings</button>
                    </div>
                </form>

                <p v-else class="text-muted mb-0">Choose a company to see its settings.</p>
            </div>
        </div>
    </div>
</template>
