<script setup lang="ts">
    import { computed, ref, watch } from 'vue';

    type BatchRow = { batch_id?: number | string; batch_no?: string; expiry_date?: string; qty: number | string };
    type AvailableBatch = { batch_id: number; batch_no: string; expiry_date: string | null; qty: number; expiry_state: string };

    /**
     * The serial numbers or batches of one document line. `direction` says what the document does with them:
     * `in` (a receiving note: the serials / batches that arrive, with expiry dates) or `out` (a sale or transfer: the serials
     * that leave, or the batches drawn - left empty, the earliest expiry goes first). It only fills `serials` / `batches` on the
     * line; the server checks them.
     */
    const props = defineProps({
        line: { type: Object, required: true },
        direction: { type: String as () => 'in' | 'out', required: true },
        companyId: { type: [String, Number], default: '' },
        branchId: { type: [String, Number], default: '' },
        /** The base units the line moves (quantity x packing) when it is not on the line itself. */
        units: { type: Number, default: null },
        disabled: { type: Boolean, default: false },
    });

    const emit = defineEmits<{ update: [patch: { serials?: string[]; batches?: BatchRow[] }] }>();

    const available = ref<string[]>([]);
    const availableBatches = ref<AvailableBatch[]>([]);
    const showAvailable = ref(false);

    const isSerial = computed(() => props.line.tracking_type === 'serial');
    const needed = computed(() => props.units ?? Number(props.line.quantity || 0) * Math.max(Number(props.line.packing_qty || 1), 1));
    const serialText = computed(() => (Array.isArray(props.line.serials) ? props.line.serials : []).join('\n'));
    const serialCount = computed(() => (Array.isArray(props.line.serials) ? props.line.serials.length : 0));
    const rows = computed<BatchRow[]>(() => (Array.isArray(props.line.batches) ? props.line.batches : []));

    function setSerials(text: string) {
        emit('update', { serials: text.split(/[\r\n,;]+/).map((value) => value.trim()).filter((value) => value !== '') });
    }

    function setRows(next: BatchRow[]) {
        emit('update', { batches: next });
    }

    function patchRow(index: number, patch: Partial<BatchRow>) {
        setRows(rows.value.map((row, rowIndex) => (rowIndex === index ? { ...row, ...patch } : row)));
    }

    function addRow() {
        setRows([...rows.value, props.direction === 'in' ? { batch_no: '', expiry_date: '', qty: '' } : { batch_id: '', qty: '' }]);
    }

    function removeRow(index: number) {
        setRows(rows.value.filter((_, rowIndex) => rowIndex !== index));
    }

    async function loadAvailable() {
        if (props.direction !== 'out' || ! props.branchId || ! props.line.product_id) {
            return;
        }

        const params = { company_id: props.companyId || undefined, branch_id: props.branchId, product_id: props.line.product_id };

        try {
            if (isSerial.value) {
                available.value = (await window.axios.get('/api/stock-tracking/available-serials', { params })).data ?? [];
            } else {
                availableBatches.value = (await window.axios.get('/api/stock-tracking/available-batches', { params })).data ?? [];
            }
        } catch {
            available.value = [];
            availableBatches.value = [];
        }
    }

    function addSerial(serial: string) {
        const current = Array.isArray(props.line.serials) ? props.line.serials : [];

        if (! current.includes(serial)) {
            emit('update', { serials: [...current, serial] });
        }
    }

    watch(() => [props.line.product_id, props.branchId, props.line.tracking_type], loadAvailable, { immediate: true });
</script>

<template>
    <div class="tracking-input" data-test="tracking-input">
        <template v-if="isSerial">
            <label class="form-label mb-1">
                Serial / IMEI numbers, one per line
                <small :class="serialCount === needed ? 'text-success' : 'text-warning'">({{ serialCount }} of {{ needed }})</small>
            </label>
            <textarea class="form-control form-control-sm" rows="3" :value="serialText" :disabled="disabled" @input="setSerials(($event.target as HTMLTextAreaElement).value)" />
            <div v-if="direction === 'out'" class="mt-1">
                <button type="button" class="btn btn-link btn-sm p-0" @click="showAvailable = ! showAvailable">{{ showAvailable ? 'Hide' : 'Pick from' }} the {{ available.length }} in stock here</button>
                <div v-if="showAvailable" class="d-flex flex-wrap gap-1 mt-1">
                    <button v-for="serial in available" :key="serial" type="button" class="btn btn-outline-secondary btn-sm py-0" :disabled="disabled" @click="addSerial(serial)">{{ serial }}</button>
                    <span v-if="! available.length" class="text-muted">None in stock in this branch.</span>
                </div>
            </div>
        </template>

        <template v-else>
            <label class="form-label mb-1">
                {{ direction === 'in' ? 'Batches received (batch no, expiry, quantity)' : 'Batches to draw' }}
                <small v-if="direction === 'out'" class="text-muted">Leave empty to take the earliest expiry first.</small>
            </label>
            <div v-for="(row, index) in rows" :key="index" class="d-flex flex-wrap gap-2 mb-1 align-items-center">
                <template v-if="direction === 'in'">
                    <input class="form-control form-control-sm" style="max-width: 160px" placeholder="Batch no" :value="row.batch_no" :disabled="disabled" @input="patchRow(index, { batch_no: ($event.target as HTMLInputElement).value })">
                    <input type="date" class="form-control form-control-sm" style="max-width: 160px" :value="row.expiry_date" :disabled="disabled" @input="patchRow(index, { expiry_date: ($event.target as HTMLInputElement).value })">
                </template>
                <select v-else class="form-select form-select-sm" style="max-width: 280px" :value="row.batch_id" :disabled="disabled" @change="patchRow(index, { batch_id: ($event.target as HTMLSelectElement).value })">
                    <option value="">Choose a batch</option>
                    <option v-for="batch in availableBatches" :key="batch.batch_id" :value="batch.batch_id">{{ batch.batch_no }} · {{ batch.expiry_date ?? 'no expiry' }} · {{ batch.qty }} left{{ batch.expiry_state === 'expired' ? ' (expired)' : '' }}</option>
                </select>
                <input type="number" min="0" step="any" class="form-control form-control-sm" style="max-width: 110px" placeholder="Qty" :value="row.qty" :disabled="disabled" @input="patchRow(index, { qty: ($event.target as HTMLInputElement).value })">
                <button type="button" class="btn btn-outline-danger btn-sm py-0" :disabled="disabled" @click="removeRow(index)">Remove</button>
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="disabled" @click="addRow">Add a batch</button>
        </template>
    </div>
</template>
