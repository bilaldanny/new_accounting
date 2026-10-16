<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
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

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <label class="small" for="cf-weeks">Weeks ahead</label>
            <input id="cf-weeks" v-model.number="weeks" type="number" min="1" max="26" class="form-control form-control-sm" style="width: 6rem" @change="load" />
        </div>

        <div v-if="data">
            <p>Bank and cash today ({{ data.as_of }}): <strong>{{ money(data.opening_balance) }}</strong> &middot; lowest point: <strong :class="data.lowest_closing < 0 ? 'text-danger' : ''">{{ money(data.lowest_closing) }}</strong></p>

            <div class="table-responsive">
                <ClientTable
                    :columns="[{ key: 'c0', label: 'Period' }, { key: 'c1', label: 'Receivables due' }, { key: 'c2', label: 'Payables due' }, { key: 'c3', label: 'Loan instalments' }, { key: 'c4', label: 'Net' }, { key: 'c5', label: 'Cash after' }]"
                    :rows="(data.buckets).map((bucket) => ({ __s: { bucket }, c0: bucket.label + (bucket.from ? ' ' + bucket.from : ''), c1: money(bucket.receivables), c2: money(bucket.payables), c3: money(bucket.loan_instalments), c4: money(bucket.net), c5: money(bucket.closing) }))"
                    :page-size="Math.max(data.buckets.length, 10)"
                    export-name="cash-flow-forecast"
                >
                    <template #footer>
                        <tr><td>Later than {{ data.weeks }} weeks</td><td class="text-end">{{ money(data.beyond.receivables) }}</td><td class="text-end">{{ money(data.beyond.payables) }}</td><td class="text-end">{{ money(data.beyond.loan_instalments) }}</td><td colspan="2"></td></tr>
                    </template>
                </ClientTable>
            </div>

            <ul class="small text-muted">
                <li v-for="note in data.notes" :key="note">{{ note }}</li>
            </ul>
        </div>
    </div></div></div>
</template>
