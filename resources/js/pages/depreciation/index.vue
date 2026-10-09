<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Depreciation',
            subtitle: 'See what is due, book it up to a month, and look at what was booked',
            breadcrumbs: [
                {
                    title: 'Depreciation',
                    href: 'NULL',
                },
            ],
        },
    });

    const { Notify } = useCommons();
    const { props } = usePage();

    const authUser = computed(() => props.auth?.user as { rolename?: string; company_id?: number | null; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const canRun = computed(() => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes('/depreciation/run'));

    const lastMonth = () => {
        const date = new Date();
        date.setDate(1);
        date.setMonth(date.getMonth() - 1);

        return date.toISOString().slice(0, 7);
    };

    const companies = ref<Array<Record<string, any>>>([]);
    const categories = ref<Array<Record<string, any>>>([]);
    const filters = reactive({ company_id: (authUser.value?.company_id ?? '') as number | string, period: lastMonth(), category_id: '' as number | string });
    const preview = ref<{ rows: Array<Record<string, any>>; total: number } | null>(null);
    const booked = ref<Array<Record<string, any>>>([]);
    const busy = ref(false);

    const money = (value: unknown): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    const params = () => ({ company_id: filters.company_id || undefined, period: filters.period, category_id: filters.category_id || undefined });

    async function loadPreview() {
        try {
            preview.value = (await window.axios.get('/api/depreciation/preview', { params: params() })).data.data;
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    async function loadBooked() {
        booked.value = (await window.axios.get('/api/depreciation', { params: { company_id: filters.company_id || undefined, show_record: 50 } })).data?.data?.data ?? [];
    }

    async function run() {
        if (! window.confirm(`Book depreciation up to ${filters.period}? This posts journal vouchers and cannot be undone here.`)) {
            return;
        }

        busy.value = true;

        try {
            const result = (await window.axios.post('/api/depreciation/run', params())).data.data;
            Notify(`Booked ${result.count} asset-months in ${result.vouchers} vouchers (${money(result.total)})`, 'success');
            await Promise.all([loadPreview(), loadBooked()]);
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    async function loadCategories() {
        categories.value = filters.company_id ? (await window.axios.get('/api/asset-categories', { params: { company_id: filters.company_id, show_record: 100 } })).data?.data?.data ?? [] : [];
    }

    watch(() => filters.company_id, async () => {
        await loadCategories();
        preview.value = null;
        await loadBooked();
    });

    onMounted(async () => {
        try {
            if (isSuperadmin.value) {
                companies.value = (await window.axios.get('/api/fetchcompanies')).data ?? [];
            }

            await loadCategories();
            await loadBooked();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    });
</script>

<template>
    <Head title="Depreciation" />

    <div class="card mb-3">
        <div class="card-body">
            <form class="d-flex flex-wrap gap-2 align-items-end" @submit.prevent="loadPreview">
                <div v-if="isSuperadmin">
                    <label class="form-label">Company</label>
                    <select v-model="filters.company_id" class="form-select" required>
                        <option value="">Choose a company</option>
                        <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.text ?? company.name }}</option>
                    </select>
                </div>
                <div><label class="form-label">Up to month</label><input v-model="filters.period" type="month" class="form-control" required></div>
                <div>
                    <label class="form-label">Category</label>
                    <select v-model="filters.category_id" class="form-select"><option value="">All categories</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select>
                </div>
                <button type="submit" class="btn btn-outline-primary btn-sm">Preview what is due</button>
                <button v-if="canRun" type="button" class="btn btn-primary btn-sm" :disabled="busy || ! preview?.rows.length" @click="run">Book it</button>
            </form>
            <p class="text-muted small mt-2 mb-0">Each asset is depreciated once per month, so booking again never doubles up. Vouchers follow your journal approval setting.</p>
        </div>
    </div>

    <div v-if="preview" class="card mb-3">
        <div class="card-body">
            <h6>Due up to {{ filters.period }}</h6>
            <p v-if="! preview.rows.length" class="text-muted mb-0">Nothing is due.</p>
            <div v-else class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Month</th><th>Asset</th><th class="text-end">Opening book value</th><th class="text-end">Depreciation</th><th class="text-end">Closing book value</th></tr></thead>
                    <tbody>
                        <tr v-for="row in preview.rows" :key="row.asset_id + row.period">
                            <td>{{ row.period }}</td><td>{{ row.code }} {{ row.name }}</td>
                            <td class="text-end">{{ money(row.opening_book_value) }}</td><td class="text-end fw-bold">{{ money(row.amount) }}</td><td class="text-end">{{ money(row.closing_book_value) }}</td>
                        </tr>
                    </tbody>
                    <tfoot><tr><th colspan="3">Total</th><th class="text-end">{{ money(preview.total) }}</th><th /></tr></tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h6>Recently booked</h6>
            <p v-if="! booked.length" class="text-muted mb-0">Nothing booked yet.</p>
            <div v-else class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Month</th><th>Asset</th><th class="text-end">Depreciation</th><th class="text-end">Book value after</th><th>Voucher</th><th>Voucher status</th></tr></thead>
                    <tbody>
                        <tr v-for="row in booked" :key="row.id">
                            <td>{{ row.period }}</td><td>{{ row.code }} {{ row.name }}</td><td class="text-end">{{ money(row.amount) }}</td><td class="text-end">{{ money(row.closing_book_value) }}</td><td>{{ row.voucher_no }}</td><td>{{ row.voucher_status }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
