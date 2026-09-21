<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Loyalty Settings',
            subtitle: 'How customers earn and spend points',
            breadcrumbs: [
                {
                    title: 'Loyalty Points',
                    href: '/loyalty',
                },
                {
                    title: 'Loyalty Settings',
                    href: 'NULL',
                },
            ],
        },
    });

    const page = usePage();
    const { Notify, fetchCompany, companiesdata } = useCommons();

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
    } | null);

    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');

    const loading = ref(true);
    const saving = ref(false);
    const companyId = ref<number | string>(isSuperadmin.value ? '' : (authUser.value?.company_id ?? ''));
    const form = ref({
        is_enabled: false,
        amount_per_point: 100,
        point_value: 1,
        min_redeem_points: 0,
    });

    const earnExample = computed(() => {
        const per = Number(form.value.amount_per_point);

        return per > 0 ? Math.floor(1000 / per) : 0;
    });

    async function loadSettings() {
        if (! companyId.value) {
            loading.value = false;

            return;
        }

        loading.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.loyaltySettings, { params: { company_id: companyId.value } });
            form.value = {
                is_enabled: Boolean(response.data.is_enabled),
                amount_per_point: Number(response.data.amount_per_point),
                point_value: Number(response.data.point_value),
                min_redeem_points: Number(response.data.min_redeem_points ?? 0),
            };
        } catch {
            Notify('Unable to load the loyalty settings.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function save() {
        saving.value = true;

        try {
            const response = await window.axios.put(API_ENDPOINTS.loyaltySettings, {
                company_id: companyId.value || null,
                ...form.value,
            });
            Notify(response.data?.message || 'Successfully Saved', 'success');
        } catch (error: unknown) {
            const message = window.axios.isAxiosError(error)
                ? (Object.values(error.response?.data?.errors ?? {}).flat()[0] as string | undefined) || error.response?.data?.message || 'The request failed.'
                : 'Unexpected error occurred';
            Notify(message, 'alert');
        } finally {
            saving.value = false;
        }
    }

    onMounted(async () => {
        if (isSuperadmin.value) {
            await fetchCompany();
        }

        await loadSettings();
    });
</script>

<template>
    <Head title="Loyalty Settings" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/loyalty" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to loyalty points
                </Link>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <div v-if="isSuperadmin" class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label" for="loyalty-company">Company</label>
                        <select id="loyalty-company" v-model="companyId" class="form-select form-select-sm" @change="loadSettings">
                            <option value="">Select company</option>
                            <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                                {{ company.text ?? company.name }}
                            </option>
                        </select>
                    </div>
                </div>

                <Loader v-if="loading" message="Loading loyalty settings…" />

                <form v-else-if="companyId" class="row g-3" @submit.prevent="save">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input id="loyalty-enabled" v-model="form.is_enabled" type="checkbox" class="form-check-input" role="switch">
                            <label class="form-check-label" for="loyalty-enabled">Loyalty programme is switched on</label>
                        </div>
                        <p class="text-muted small mb-0">While it is off no points can be earned or redeemed (manual adjustments still work).</p>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="loyalty-amount-per-point">Spend per point</label>
                        <input id="loyalty-amount-per-point" v-model.number="form.amount_per_point" type="number" min="0.01" step="0.01" class="form-control form-control-sm" required>
                        <p class="text-muted small mb-0">Every whole multiple of this amount earns one point. A 1000 sale earns {{ earnExample }} points.</p>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="loyalty-point-value">Value of one point</label>
                        <input id="loyalty-point-value" v-model.number="form.point_value" type="number" min="0.01" step="0.01" class="form-control form-control-sm" required>
                        <p class="text-muted small mb-0">What one point is worth when it is redeemed.</p>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="loyalty-min-redeem">Minimum points to redeem</label>
                        <input id="loyalty-min-redeem" v-model.number="form.min_redeem_points" type="number" min="0" step="1" class="form-control form-control-sm">
                        <p class="text-muted small mb-0">Smallest number of points that can be redeemed at once.</p>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="saving">Save settings</button>
                    </div>
                </form>

                <p v-else class="text-muted mb-0">Choose a company to see its loyalty settings.</p>
            </div>
        </div>
    </div>
</template>
