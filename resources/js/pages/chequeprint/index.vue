<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Cheque Printing',
            subtitle: 'Cheque leaf layouts and printing a cheque for a payment voucher',
            breadcrumbs: [{ title: 'Cheque Printing', href: 'NULL' }],
        },
    });

    type Layout = {
        id: number; name: string; width_mm: number; height_mm: number; payee_x: number; payee_y: number; words_x: number; words_y: number;
        amount_x: number; amount_y: number; date_x: number; date_y: number; font_size: number; account_payee: boolean; is_default: boolean;
    };
    type Sheet = {
        layout: Layout; payee: string; amount: number; amount_figures: string; amount_words: string; date_text: string; account_payee: boolean; voucher_no: string | null;
    };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const layouts = ref<Layout[]>([]);
    const editing = ref<number | null>(null);
    const blank = () => ({
        name: '', width_mm: 203, height_mm: 95, payee_x: 25, payee_y: 30, words_x: 25, words_y: 42, amount_x: 150, amount_y: 52,
        date_x: 150, date_y: 14, font_size: 12, account_payee: true, is_default: false,
    });
    const form = ref(blank());
    const print = ref({ layout_id: '' as number | '', t_account_id: '' as number | '', payee: '', amount: '' as number | '', date: new Date().toISOString().slice(0, 10), reprint: false });
    const sheet = ref<Sheet | null>(null);

    async function load() {
        layouts.value = (await run(() => window.axios.get('/api/cheque-layouts', { params: scope() })))?.data?.data ?? [];
    }

    function edit(layout: Layout) {
        editing.value = layout.id;
        form.value = { ...blank(), ...layout };
    }

    async function save() {
        const request = editing.value === null
            ? () => window.axios.post('/api/cheque-layouts', { ...scope(), ...form.value })
            : () => window.axios.put(`/api/cheque-layouts/${editing.value}`, { ...form.value });

        if (await run(request, 'Layout saved')) {
            editing.value = null;
            form.value = blank();
            await load();
        }
    }

    async function remove(layout: Layout) {
        if (window.confirm(`Delete layout ${layout.name}?`) && await run(() => window.axios.delete(`/api/cheque-layouts/${layout.id}`), 'Deleted')) {
            await load();
        }
    }

    async function render() {
        const body: Record<string, unknown> = { ...scope(), ...print.value };

        for (const key of ['layout_id', 't_account_id', 'payee', 'amount']) {
            if (body[key] === '') {
                delete body[key];
            }
        }

        const result = await run(() => window.axios.post('/api/cheque-print', body));

        if (result) {
            sheet.value = result.data.data;
        }
    }

    function printSheet() {
        window.print();
    }

    onMounted(load);
</script>

<template>
    <Head title="Cheque Printing" />

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        </div>

        <div class="row g-3 d-print-none">
            <div v-if="can('/chequeprint/layout')" class="col-lg-6">
                <div class="border rounded p-3">
                    <h6>{{ editing === null ? 'New layout' : 'Edit layout' }} <small class="text-muted">(millimetres from the top left of the leaf)</small></h6>
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label small" for="cl-name">Name</label><input id="cl-name" v-model="form.name" class="form-control form-control-sm" /></div>
                        <div class="col-3"><label class="form-label small" for="cl-w">Width</label><input id="cl-w" v-model.number="form.width_mm" type="number" step="any" class="form-control form-control-sm" /></div>
                        <div class="col-3"><label class="form-label small" for="cl-h">Height</label><input id="cl-h" v-model.number="form.height_mm" type="number" step="any" class="form-control form-control-sm" /></div>
                        <template v-for="field in ['payee', 'words', 'amount', 'date']" :key="field">
                            <div class="col-3"><label class="form-label small text-capitalize" :for="`cl-${field}-x`">{{ field }} x</label><input :id="`cl-${field}-x`" v-model.number="(form as any)[field + '_x']" type="number" step="any" class="form-control form-control-sm" /></div>
                            <div class="col-3"><label class="form-label small text-capitalize" :for="`cl-${field}-y`">{{ field }} y</label><input :id="`cl-${field}-y`" v-model.number="(form as any)[field + '_y']" type="number" step="any" class="form-control form-control-sm" /></div>
                        </template>
                        <div class="col-3"><label class="form-label small" for="cl-font">Font size</label><input id="cl-font" v-model.number="form.font_size" type="number" class="form-control form-control-sm" /></div>
                        <div class="col-9 d-flex align-items-end gap-3">
                            <label class="form-check-label small"><input v-model="form.account_payee" type="checkbox" class="form-check-input me-1" />Print "A/C Payee only"</label>
                            <label class="form-check-label small"><input v-model="form.is_default" type="checkbox" class="form-check-input me-1" />Default</label>
                        </div>
                    </div>
                    <button type="button" class="btn btn-dark btn-sm mt-3 me-2" @click="save">Save layout</button>
                    <button v-if="editing !== null" type="button" class="btn btn-outline-dark btn-sm mt-3" @click="editing = null; form = blank()">Cancel</button>

                    <table class="table table-sm align-middle mt-3">
                        <thead><tr><th>Layout</th><th>Size</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="layout in layouts" :key="layout.id">
                                <td>{{ layout.name }}<span v-if="layout.is_default" class="badge text-bg-secondary ms-1">default</span></td><td>{{ layout.width_mm }} x {{ layout.height_mm }} mm</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="edit(layout)">Edit</button>
                                    <button type="button" class="btn btn-outline-danger btn-sm" @click="remove(layout)">Delete</button>
                                </td>
                            </tr>
                            <tr v-if="! layouts.length"><td colspan="3" class="text-center text-muted">No layouts yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="can('/chequeprint/print')" class="col-lg-6">
                <div class="border rounded p-3">
                    <h6>Print a cheque</h6>
                    <div class="row g-2">
                        <div class="col-md-4"><label class="form-label small" for="cp-layout">Layout</label>
                            <select id="cp-layout" v-model="print.layout_id" class="form-select form-select-sm"><option value="">Default</option><option v-for="layout in layouts" :key="layout.id" :value="layout.id">{{ layout.name }}</option></select>
                        </div>
                        <div class="col-md-4"><label class="form-label small" for="cp-voucher">Payment voucher id</label><input id="cp-voucher" v-model.number="print.t_account_id" type="number" class="form-control form-control-sm" /></div>
                        <div class="col-md-4 d-flex align-items-end"><label class="form-check-label small"><input v-model="print.reprint" type="checkbox" class="form-check-input me-1" />Reprint</label></div>
                        <div class="col-md-5"><label class="form-label small" for="cp-payee">Or payee</label><input id="cp-payee" v-model="print.payee" class="form-control form-control-sm" /></div>
                        <div class="col-md-3"><label class="form-label small" for="cp-amount">Amount</label><input id="cp-amount" v-model.number="print.amount" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                        <div class="col-md-4"><label class="form-label small" for="cp-date">Date</label><input id="cp-date" v-model="print.date" type="date" class="form-control form-control-sm" /></div>
                    </div>
                    <button type="button" class="btn btn-dark btn-sm mt-3 me-2" @click="render">Prepare cheque</button>
                    <button v-if="sheet" type="button" class="btn btn-outline-dark btn-sm mt-3" @click="printSheet">Print</button>
                </div>
            </div>
        </div>

        <div v-if="sheet" class="cheque-sheet mt-4" :style="{ width: sheet.layout.width_mm + 'mm', height: sheet.layout.height_mm + 'mm', fontSize: sheet.layout.font_size + 'pt' }">
            <span v-if="sheet.account_payee" class="cheque-sheet__crossing">A/C PAYEE ONLY</span>
            <span class="cheque-sheet__field" :style="{ left: sheet.layout.date_x + 'mm', top: sheet.layout.date_y + 'mm' }">{{ sheet.date_text }}</span>
            <span class="cheque-sheet__field" :style="{ left: sheet.layout.payee_x + 'mm', top: sheet.layout.payee_y + 'mm' }">{{ sheet.payee }}</span>
            <span class="cheque-sheet__field" :style="{ left: sheet.layout.words_x + 'mm', top: sheet.layout.words_y + 'mm' }">{{ sheet.amount_words }}</span>
            <span class="cheque-sheet__field" :style="{ left: sheet.layout.amount_x + 'mm', top: sheet.layout.amount_y + 'mm' }">{{ sheet.amount_figures }}</span>
        </div>
    </div>
</template>

<style scoped>
    .cheque-sheet {
        position: relative;
        border: 1px dashed #999;
        background: #fff;
        color: #000;
    }

    .cheque-sheet__field {
        position: absolute;
        white-space: nowrap;
    }

    .cheque-sheet__crossing {
        position: absolute;
        left: 8mm;
        top: 8mm;
        font-size: 8pt;
        border-top: 1px solid #000;
        border-bottom: 1px solid #000;
        transform: rotate(-20deg);
    }

    @media print {
        .cheque-sheet {
            border: 0;
        }
    }
</style>
