<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Cash Flow Forecast',
            subtitle: 'Weekly cash position from open invoices, bills and loan instalments',
            breadcrumbs: [{ title: 'Cash Flow Forecast', href: 'NULL' }],
        },
    });

    type Bucket = { label: string; from: string | null; to: string | null; receivables: number; payables: number; loan_instalments: number; net: number; closing: number };
    type Forecast = {
        as_of: string; weeks: number; opening_balance: number; buckets: Bucket[]; lowest_closing: number;
        beyond: { receivables: number; payables: number; loan_instalments: number }; notes: string[];
    };

    const { companyId, isSuperadmin, scope, run, money } = useCloseout();
    const weeks = ref(8);
    const data = ref<Forecast | null>(null);

    async function load() {
        data.value = (await run(() => window.axios.get('/api/cash-flow-forecast', { params: { ...scope(), weeks: weeks.value } })))?.data?.data ?? null;
    }

    onMounted(load);
</script>

<template>
    <Head title="Cash Flow Forecast" />

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
            <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />
            <label class="small" for="cf-weeks">Weeks ahead</label>
            <input id="cf-weeks" v-model.number="weeks" type="number" min="1" max="26" class="form-control form-control-sm" style="width: 6rem" @change="load" />
        </div>

        <div v-if="data">
            <p>Bank and cash today ({{ data.as_of }}): <strong>{{ money(data.opening_balance) }}</strong> &middot; lowest point: <strong :class="data.lowest_closing < 0 ? 'text-danger' : ''">{{ money(data.lowest_closing) }}</strong></p>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Period</th><th class="text-end">Receivables due</th><th class="text-end">Payables due</th><th class="text-end">Loan instalments</th><th class="text-end">Net</th><th class="text-end">Cash after</th></tr></thead>
                    <tbody>
                        <tr v-for="bucket in data.buckets" :key="bucket.label">
                            <td>{{ bucket.label }} <small v-if="bucket.from" class="text-muted">{{ bucket.from }}</small></td>
                            <td class="text-end">{{ money(bucket.receivables) }}</td><td class="text-end">{{ money(bucket.payables) }}</td><td class="text-end">{{ money(bucket.loan_instalments) }}</td>
                            <td class="text-end" :class="bucket.net < 0 ? 'text-danger' : ''">{{ money(bucket.net) }}</td>
                            <td class="text-end" :class="bucket.closing < 0 ? 'text-danger' : ''">{{ money(bucket.closing) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr><td>Later than {{ data.weeks }} weeks</td><td class="text-end">{{ money(data.beyond.receivables) }}</td><td class="text-end">{{ money(data.beyond.payables) }}</td><td class="text-end">{{ money(data.beyond.loan_instalments) }}</td><td colspan="2"></td></tr>
                    </tfoot>
                </table>
            </div>

            <ul class="small text-muted">
                <li v-for="note in data.notes" :key="note">{{ note }}</li>
            </ul>
        </div>
    </div>
</template>
