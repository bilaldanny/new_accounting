<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
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

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="No quotations."  :columns="[{ key: 'c0', label: 'Quotation' }, { key: 'c1', label: 'Customer' }, { key: 'c2', label: 'Date' }, { key: 'c3', label: 'Amount' }, { key: 'c4', label: 'Valid until' }, { key: 'c5', label: 'Proforma' }, { key: 'c6', label: 'State' }, { key: 'c7', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.invoice_no), c1: (row.contact_name), c2: (row.transaction_date), c3: (money(row.final_amount)), c4: '', c5: '', c6: '', c7: '' }))" export-name="quotation">
<template #cell-c4="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><input v-model="row.valid_until" type="date" class="form-control form-control-sm" :disabled="! can('/quotation/:id/edit') || row.state === 'converted'" :aria-label="`Valid until ${row.invoice_no}`" /></template></template>
<template #cell-c5="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><input v-model="row.is_proforma" type="checkbox" class="form-check-input" :disabled="! can('/quotation/:id/edit') || row.state === 'converted'" :aria-label="`Proforma ${row.invoice_no}`" /></template></template>
<template #cell-c6="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                            <span class="badge" :class="{ 'text-bg-success': row.state === 'open', 'text-bg-danger': row.state === 'expired', 'text-bg-secondary': row.state === 'converted' }">{{ row.state }}</span>
                            <span v-if="row.converted_to" class="small text-muted ms-1">{{ row.converted_to.type }} #{{ row.converted_to.id }}</span>
                        </template></template>
<template #cell-c7="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                            <button v-if="can('/quotation/:id/edit') && row.state !== 'converted'" type="button" class="btn btn-outline-dark btn-sm me-1" @click="save(row)">Save</button>
                            <template v-if="can('/quotation/convert') && row.state === 'open'">
                                <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="convert(row, 'order')">To sales order</button>
                                <button type="button" class="btn btn-dark btn-sm" @click="convert(row, 'invoice')">To invoice</button>
                            </template>
                        </template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
