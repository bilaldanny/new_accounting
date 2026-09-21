<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref, watch } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Exchange Rates',
            subtitle: 'Show an invoice total in the customer\'s currency',
            breadcrumbs: [
                {
                    title: 'Exchange Rates',
                    href: 'NULL',
                },
            ],
        },
    });

    type CurrencyRow = { id: number; code: string; name: string; symbol: string | null; rate: number | null };

    const page = usePage();
    const { Notify, fetchCompany, companiesdata } = useCommons();

    const loading = ref(false);
    const saving = ref(false);
    const companyId = ref<string>('');
    const base = ref<{ id: number; code: string; symbol: string | null } | null>(null);
    const rows = ref<CurrencyRow[]>([]);
    const rates = ref<Record<number, string>>({});
    const search = ref('');

    const user = computed(() => page.props.auth?.user as { rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(user.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const canUpdate = computed(() => isSuperadmin.value || (user.value?.permission_paths ?? []).includes('/currencyrate/update'));
    const visibleRows = computed(() => {
        const term = search.value.trim().toLowerCase();

        return term === '' ? rows.value : rows.value.filter((row) => `${row.code} ${row.name}`.toLowerCase().includes(term));
    });

    function errorMessage(error: unknown): string {
        if (! window.axios.isAxiosError(error)) {
            return 'Unexpected error occurred';
        }

        const errors = Object.values(error.response?.data?.errors ?? {}).flat() as string[];

        return errors[0] || error.response?.data?.message || 'The request failed.';
    }

    async function load() {
        if (isSuperadmin.value && companyId.value === '') {
            rows.value = [];
            base.value = null;

            return;
        }

        loading.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.currencyRates, { params: isSuperadmin.value ? { company_id: companyId.value } : {} });
            base.value = response.data?.base ?? null;
            rows.value = response.data?.currencies ?? [];
            rates.value = Object.fromEntries(rows.value.map((row) => [row.id, row.rate === null ? '' : String(row.rate)]));
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function save() {
        saving.value = true;

        try {
            const changed = rows.value
                .filter((row) => String(rates.value[row.id] ?? '') !== (row.rate === null ? '' : String(row.rate)))
                .map((row) => ({ currency_id: row.id, rate: String(rates.value[row.id] ?? '').trim() === '' ? null : Number(rates.value[row.id]) }));

            if (changed.length === 0) {
                Notify('Nothing changed.', 'info');

                return;
            }

            const response = await window.axios.put(API_ENDPOINTS.currencyRates, { company_id: isSuperadmin.value ? companyId.value : undefined, rates: changed });
            Notify(response.data?.message || 'Successfully Saved', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            saving.value = false;
        }
    }

    watch(companyId, load);

    onMounted(async () => {
        if (isSuperadmin.value) {
            await fetchCompany();
        }

        await load();
    });
</script>

<template>
    <Head title="Exchange Rates" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__body p-3 p-md-4">
                <div class="alert alert-info small">
                    <strong>Display only.</strong> Every amount is kept and posted in the company's base currency
                    <span v-if="base">(<strong>{{ base.code }}</strong>)</span>. Enter how many units of another currency
                    <strong>one {{ base?.code ?? 'base currency' }}</strong> is worth; a sales invoice of a customer whose currency has a rate then also shows
                    its total in that currency. A currency without a rate shows nothing.
                </div>

                <div v-if="isSuperadmin" class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label" for="currencyrate-company">Company</label>
                        <select id="currencyrate-company" v-model="companyId" class="form-select form-select-sm">
                            <option value="">Choose a company</option>
                            <option v-for="company in companiesdata" :key="company.id" :value="String(company.id)">{{ company.text ?? company.name }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="! base && ! loading && (! isSuperadmin || companyId !== '')" class="alert alert-warning small">
                    This company has no base currency yet. Set it in Company Settings so rates have something to convert from.
                </div>

                <Loader v-if="loading" message="Loading exchange rates…" />

                <template v-else-if="rows.length">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <input v-model="search" type="search" class="form-control form-control-sm" style="max-width: 16rem" placeholder="Find a currency" aria-label="Find a currency">
                        <button v-if="canUpdate" type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save rates' }}</button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Currency</th>
                                    <th>Symbol</th>
                                    <th class="text-end" style="width: 14rem">1 {{ base?.code ?? 'base' }} =</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in visibleRows" :key="row.id">
                                    <td>{{ row.code }} <small class="text-muted">{{ row.name }}</small></td>
                                    <td>{{ row.symbol || '—' }}</td>
                                    <td class="text-end">
                                        <input
                                            v-model="rates[row.id]"
                                            type="number"
                                            min="0"
                                            step="0.000001"
                                            class="form-control form-control-sm text-end"
                                            :disabled="! canUpdate"
                                            :aria-label="`Rate for ${row.code}`"
                                            placeholder="No rate"
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <p v-else-if="! loading && (! isSuperadmin || companyId !== '')" class="text-muted text-center">No other active currencies.</p>
            </div>
        </div>
    </div>
</template>
