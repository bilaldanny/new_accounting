<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Quotations & Proforma',
            subtitle: 'Validity, proforma flag and conversion to an order or an invoice',
            breadcrumbs: [{ title: 'Quotations & Proforma', href: 'NULL' }],
        },
    });

    type Quote = {
        id: number; invoice_no: string; contact_name: string | null; transaction_date: string; final_amount: number;
        valid_until: string | null; is_proforma: boolean; state: 'open' | 'expired' | 'converted';
        converted_to: { type: string; id: number } | null;
    };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const rows = ref<Quote[]>([]);

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/quotations', { params: scope() })))?.data?.data?.data ?? [];
    }

    async function save(row: Quote) {
        await run(() => window.axios.put(`/api/quotations/${row.id}`, { valid_until: row.valid_until || null, is_proforma: row.is_proforma }), 'Saved');
        await load();
    }

    async function convert(row: Quote, to: 'order' | 'invoice') {
        if (await run(() => window.axios.post(`/api/quotations/${row.id}/convert`, { to }), 'Quotation converted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Quotations & Proforma" />

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Quotation</th><th>Customer</th><th>Date</th><th class="text-end">Amount</th><th>Valid until</th><th>Proforma</th><th>State</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.invoice_no }}</td><td>{{ row.contact_name }}</td><td>{{ row.transaction_date }}</td><td class="text-end">{{ money(row.final_amount) }}</td>
                        <td><input v-model="row.valid_until" type="date" class="form-control form-control-sm" :disabled="! can('/quotation/:id/edit') || row.state === 'converted'" :aria-label="`Valid until ${row.invoice_no}`" /></td>
                        <td><input v-model="row.is_proforma" type="checkbox" class="form-check-input" :disabled="! can('/quotation/:id/edit') || row.state === 'converted'" :aria-label="`Proforma ${row.invoice_no}`" /></td>
                        <td>
                            <span class="badge" :class="{ 'text-bg-success': row.state === 'open', 'text-bg-danger': row.state === 'expired', 'text-bg-secondary': row.state === 'converted' }">{{ row.state }}</span>
                            <span v-if="row.converted_to" class="small text-muted ms-1">{{ row.converted_to.type }} #{{ row.converted_to.id }}</span>
                        </td>
                        <td class="text-end text-nowrap">
                            <button v-if="can('/quotation/:id/edit') && row.state !== 'converted'" type="button" class="btn btn-outline-dark btn-sm me-1" @click="save(row)">Save</button>
                            <template v-if="can('/quotation/convert') && row.state === 'open'">
                                <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="convert(row, 'order')">To sales order</button>
                                <button type="button" class="btn btn-dark btn-sm" @click="convert(row, 'invoice')">To invoice</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="8" class="text-center text-muted">No quotations.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
