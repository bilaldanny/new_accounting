<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref } from 'vue';
    import LookupSelect from '@/components/LookupSelect.vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Manufacturing',
            subtitle: 'Bills of materials, production plan, work orders, shop floor entries and production cost',
            breadcrumbs: [{ title: 'Manufacturing', href: 'NULL' }],
        },
    });

    type Product = { id: number; name: string; sku: string | null; variations: { id: number; name: string }[] };
    type Bom = { id: number; product: string; product_id: number; code: string; name: string; version: number; status: string; output_qty: number };
    type Order = { id: number; wo_no: string; product: string; status: string; bom_version: number; planned_qty: number; produced_qty: number; scrap_qty: number; fg_unit_cost: number | null };
    type Item = { component_product_id: number | ''; component_variation_id: number | ''; quantity: number; scrap_percent: number };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const tab = ref<'orders' | 'boms' | 'plan' | 'settings'>('orders');
    const products = ref<Product[]>([]);
    const boms = ref<Bom[]>([]);
    const orders = ref<Order[]>([]);
    const current = ref<Record<string, any> | null>(null);
    const bomDetail = ref<Record<string, any> | null>(null);
    const plans = ref<any[]>([]);
    const materials = ref<any[]>([]);
    const withoutBom = ref<string[]>([]);
    const settings = reactive<Record<string, any>>({ posting_enabled: false, require_qc_before_receipt: false, allow_over_issue: true, accounts: {} });
    const accountSlots = ref<string[]>([]);

    const bomForm = reactive<{ id: number; product_id: number | ''; name: string; output_qty: number; items: Item[]; outputs: any[]; operations: any[] }>({
        id: 0, product_id: '', name: '', output_qty: 1, items: [], outputs: [], operations: [],
    });
    const orderForm = reactive({ product_id: '' as number | '', planned_qty: 1, planned_start: '' });
    const planForm = reactive({ product_id: '' as number | '', period_start: '', planned_qty: 0 });
    const issueForm = reactive({ product_id: '' as number | '', quantity: 0 });
    const laborForm = reactive({ operation: '', hours: 1, labor_rate: 0, overhead_rate: 0 });
    const scrapForm = reactive({ quantity: 0, is_normal: true, reason: '' });
    const checkForm = reactive({ check_name: '', result: 'pass', qty_inspected: 0, qty_failed: 0 });
    const receiveForm = reactive({ quantity: 0, backflush: false });

    const canManage = computed(() => can('/manufacturing/manage'));
    const canProduce = computed(() => can('/manufacturing/produce'));
    const canApprove = computed(() => can('/manufacturing/approve'));
    const get = async (url: string, params: Record<string, unknown> = {}) => (await run(() => window.axios.get(url, { params: { ...scope(), ...params } })))?.data;

    async function load() {
        products.value = (await get('/api/manufacturing/products'))?.data ?? [];
        boms.value = (await get('/api/manufacturing/boms'))?.data ?? [];
        orders.value = (await get('/api/manufacturing/work-orders'))?.data ?? [];
        const plan = await get('/api/manufacturing/plans');
        plans.value = plan?.data ?? [];
        materials.value = plan?.materials ?? [];
        withoutBom.value = plan?.without_bom ?? [];
        const s = await get('/api/manufacturing/settings');
        Object.assign(settings, s?.data ?? {});
        accountSlots.value = s?.account_slots ?? [];
    }

    async function send(method: 'post' | 'put' | 'delete', url: string, body: Record<string, unknown> = {}, ok?: string) {
        const result = await run(() => (method === 'delete' ? window.axios.delete(url, { params: scope() }) : window.axios[method](url, { ...scope(), ...body })), ok);

        if (result) {
            await load();
        }

        return result;
    }

    async function openOrder(id: number) {
        current.value = (await get(`/api/manufacturing/work-orders/${id}`))?.data ?? null;
    }

    async function act(path: string, body: Record<string, unknown>, ok: string) {
        if (current.value && (await send('post', path.replace(':id', String(current.value.id)), body, ok))) {
            await openOrder(current.value.id);
        }
    }

    async function createOrder() {
        const result = await send('post', '/api/manufacturing/work-orders', { ...orderForm }, 'Work order made');

        if (result?.data?.data?.id) {
            await openOrder(result.data.data.id);
        }
    }

    async function openBom(id: number) {
        bomDetail.value = (await get(`/api/manufacturing/boms/${id}`))?.data ?? null;
    }

    function editBom() {
        if (! bomDetail.value) {
            return;
        }

        Object.assign(bomForm, {
            id: bomDetail.value.id, product_id: bomDetail.value.product_id, name: bomDetail.value.name, output_qty: Number(bomDetail.value.output_qty),
            items: bomDetail.value.items.map((i: any) => ({ component_product_id: i.component_product_id, component_variation_id: i.component_variation_id, quantity: Number(i.quantity), scrap_percent: Number(i.scrap_percent) })),
            outputs: bomDetail.value.outputs.map((o: any) => ({ product_id: o.product_id, variation_id: o.variation_id, kind: o.kind, quantity: Number(o.quantity), cost_share_percent: Number(o.cost_share_percent) })),
            operations: bomDetail.value.operations.map((o: any) => ({ name: o.name, minutes: Number(o.minutes), labor_rate_per_hour: Number(o.labor_rate_per_hour), overhead_rate_per_hour: Number(o.overhead_rate_per_hour) })),
        });
    }

    async function saveBom() {
        const body = { product_id: bomForm.product_id || undefined, name: bomForm.name, output_qty: bomForm.output_qty, items: bomForm.items, outputs: bomForm.outputs, operations: bomForm.operations };
        const result = await send(bomForm.id ? 'put' : 'post', bomForm.id ? `/api/manufacturing/boms/${bomForm.id}` : '/api/manufacturing/boms', body, 'BOM saved');

        if (result?.data?.data?.id) {
            await openBom(result.data.data.id);
        }
    }

    const variationsOf = (id: number | ''): { id: number; name: string }[] => products.value.find((p) => p.id === id)?.variations ?? [];
    const addItem = () => bomForm.items.push({ component_product_id: '', component_variation_id: '', quantity: 1, scrap_percent: 0 });
    const saveSettings = () => send('put', '/api/manufacturing/settings', { ...settings }, 'Settings saved');

    onMounted(load);
</script>

<template>
    <Head title="Manufacturing" />

    <div class="container-fluid py-3">
        <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm mb-3" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />

        <ul class="nav nav-tabs mb-3">
            <li v-for="item in [['orders', 'Work orders'], ['boms', 'Bills of materials'], ['plan', 'Production plan'], ['settings', 'Settings']]" :key="item[0]" class="nav-item">
                <button type="button" class="nav-link" :class="{ active: tab === item[0] }" @click="tab = item[0] as typeof tab">{{ item[1] }}</button>
            </li>
        </ul>

        <section v-if="tab === 'orders'">
            <div v-if="canProduce" class="border rounded p-3 mb-3">
                <h6>New work order</h6>
                <div class="row g-2">
                    <div class="col-md-4"><select v-model="orderForm.product_id" class="form-select form-select-sm" aria-label="Product"><option value="">Product with an active BOM</option><option v-for="b in boms.filter((x) => x.status === 'active')" :key="b.id" :value="b.product_id">{{ b.product }} (v{{ b.version }})</option></select></div>
                    <div class="col-md-2"><input v-model.number="orderForm.planned_qty" type="number" class="form-control form-control-sm" aria-label="Quantity" /></div>
                    <div class="col-md-3"><input v-model="orderForm.planned_start" type="date" class="form-control form-control-sm" aria-label="Planned start" /></div>
                    <div class="col-md-2"><button type="button" class="btn btn-dark btn-sm" :disabled="! orderForm.product_id" @click="createOrder">Make work order</button></div>
                </div>
            </div>

            <table class="table table-sm">
                <thead><tr><th>Work order</th><th>Product</th><th>Status</th><th class="text-end">Planned</th><th class="text-end">Produced</th><th class="text-end">Unit cost</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="o in orders" :key="o.id">
                        <td>{{ o.wo_no }}</td><td>{{ o.product }} <span class="text-muted">v{{ o.bom_version }}</span></td><td class="text-capitalize">{{ o.status.replace('_', ' ') }}</td>
                        <td class="text-end">{{ o.planned_qty }}</td><td class="text-end">{{ o.produced_qty }}</td><td class="text-end">{{ o.fg_unit_cost === null ? '-' : money(o.fg_unit_cost) }}</td>
                        <td class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="openOrder(o.id)">Open</button></td>
                    </tr>
                    <tr v-if="! orders.length"><td colspan="7" class="text-center text-muted">No work orders yet.</td></tr>
                </tbody>
            </table>

            <div v-if="current" class="border rounded p-3">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <h6 class="mb-0 me-3">{{ current.wo_no }} - {{ current.product }} <span class="badge text-bg-secondary text-capitalize">{{ String(current.status).replace('_', ' ') }}</span></h6>
                    <span class="small">In progress: <strong>{{ money(current.wip_value) }}</strong> (material {{ money(current.material_cost) }}, labour {{ money(current.labor_cost) }}, overhead {{ money(current.overhead_cost) }})</span>
                    <button v-if="current.status === 'draft' && canApprove" type="button" class="btn btn-success btn-sm" @click="act('/api/work-orders/:id/approve', {}, 'Work order released')">Release</button>
                    <button v-if="['draft', 'released'].includes(current.status) && canProduce" type="button" class="btn btn-outline-danger btn-sm" @click="act('/api/manufacturing/work-orders/:id/cancel', {}, 'Work order cancelled')">Cancel</button>
                    <button v-if="['in_progress', 'completed'].includes(current.status) && canProduce" type="button" class="btn btn-outline-dark btn-sm" @click="act('/api/manufacturing/work-orders/:id/close', {}, 'Work order closed')">Close and take variance</button>
                </div>

                <div v-if="['released', 'in_progress'].includes(current.status) && canProduce" class="row g-3 mb-3">
                    <div class="col-lg-4">
                        <h6 class="small">Issue material</h6>
                        <div class="d-flex gap-2">
                            <select v-model="issueForm.product_id" class="form-select form-select-sm" aria-label="Material"><option value="">Material</option><option v-for="m in current.materials" :key="m.id" :value="m.product_id">{{ m.name }}</option></select>
                            <input v-model.number="issueForm.quantity" type="number" class="form-control form-control-sm" style="width: 6rem" aria-label="Issue quantity" />
                            <button type="button" class="btn btn-outline-dark btn-sm" @click="act('/api/manufacturing/work-orders/:id/issue', { lines: [{ product_id: issueForm.product_id, quantity: issueForm.quantity }] }, 'Material issued')">Issue</button>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <h6 class="small">Direct labour and overhead</h6>
                        <div class="d-flex gap-2">
                            <input v-model="laborForm.operation" class="form-control form-control-sm" placeholder="Operation" aria-label="Operation" />
                            <input v-model.number="laborForm.hours" type="number" class="form-control form-control-sm" style="width: 5rem" aria-label="Hours" />
                            <input v-model.number="laborForm.labor_rate" type="number" class="form-control form-control-sm" style="width: 5rem" placeholder="Rate" aria-label="Labour rate" />
                            <input v-model.number="laborForm.overhead_rate" type="number" class="form-control form-control-sm" style="width: 5rem" placeholder="OH" aria-label="Overhead rate" />
                            <button type="button" class="btn btn-outline-dark btn-sm" @click="act('/api/manufacturing/work-orders/:id/labor', { ...laborForm }, 'Labour recorded')">Add</button>
                        </div>
                        <button type="button" class="btn btn-link btn-sm px-0" @click="act('/api/manufacturing/work-orders/:id/standard-labor', {}, 'Standard labour applied')">Apply the BOM operations</button>
                    </div>
                    <div class="col-lg-4">
                        <h6 class="small">Receive finished goods</h6>
                        <div class="d-flex gap-2 align-items-center">
                            <input v-model.number="receiveForm.quantity" type="number" class="form-control form-control-sm" style="width: 6rem" aria-label="Receive quantity" />
                            <label class="small"><input v-model="receiveForm.backflush" type="checkbox" class="me-1" />Issue what is missing</label>
                            <button type="button" class="btn btn-dark btn-sm" @click="act('/api/manufacturing/work-orders/:id/receive', { ...receiveForm }, 'Finished goods received')">Receive</button>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="small">Scrap</h6>
                        <div class="d-flex gap-2 align-items-center">
                            <input v-model.number="scrapForm.quantity" type="number" class="form-control form-control-sm" style="width: 6rem" aria-label="Scrap quantity" />
                            <label class="small"><input v-model="scrapForm.is_normal" type="checkbox" class="me-1" />Normal</label>
                            <input v-model="scrapForm.reason" class="form-control form-control-sm" placeholder="Reason" aria-label="Scrap reason" />
                            <button type="button" class="btn btn-outline-dark btn-sm" @click="act('/api/manufacturing/work-orders/:id/scrap', { ...scrapForm }, 'Scrap recorded')">Record</button>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="small">Quality check</h6>
                        <div class="d-flex gap-2">
                            <input v-model="checkForm.check_name" class="form-control form-control-sm" placeholder="Check" aria-label="Check name" />
                            <select v-model="checkForm.result" class="form-select form-select-sm" style="width: 6rem" aria-label="Result"><option value="pass">Pass</option><option value="fail">Fail</option></select>
                            <input v-model.number="checkForm.qty_inspected" type="number" class="form-control form-control-sm" style="width: 6rem" placeholder="Inspected" aria-label="Inspected" />
                            <input v-model.number="checkForm.qty_failed" type="number" class="form-control form-control-sm" style="width: 6rem" placeholder="Failed" aria-label="Failed" />
                            <button type="button" class="btn btn-outline-dark btn-sm" @click="act('/api/manufacturing/work-orders/:id/checks', { ...checkForm }, 'Check recorded')">Record</button>
                        </div>
                    </div>
                </div>

                <table class="table table-sm mb-0">
                    <thead><tr><th>Material</th><th class="text-end">Required</th><th class="text-end">Issued</th></tr></thead>
                    <tbody><tr v-for="m in current.materials" :key="m.id"><td>{{ m.name }}</td><td class="text-end">{{ m.required_qty }}</td><td class="text-end">{{ m.issued_qty }}</td></tr></tbody>
                </table>
                <div v-if="current.receipts.length" class="small mt-2">
                    Received: <span v-for="r in current.receipts" :key="r.id" class="me-3">{{ r.name }} {{ r.quantity }} ({{ r.kind }}) at {{ money(r.unit_cost) }}</span>
                </div>
            </div>
        </section>

        <section v-if="tab === 'boms'">
            <div class="row g-3">
                <div class="col-lg-6">
                    <table class="table table-sm">
                        <thead><tr><th>Product</th><th>Version</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="b in boms" :key="b.id"><td>{{ b.product }}</td><td>v{{ b.version }}</td><td class="text-capitalize">{{ b.status }}</td><td class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="openBom(b.id)">Open</button></td></tr>
                            <tr v-if="! boms.length"><td colspan="4" class="text-center text-muted">No bill of materials yet.</td></tr>
                        </tbody>
                    </table>
                    <div v-if="bomDetail" class="border rounded p-3 small">
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <strong>{{ bomDetail.name }} v{{ bomDetail.version }}</strong> <span class="badge text-bg-secondary text-capitalize">{{ bomDetail.status }}</span>
                            <button v-if="bomDetail.status === 'draft' && canApprove" type="button" class="btn btn-success btn-sm" @click="send('post', `/api/boms/${bomDetail.id}/approve`, {}, 'Version activated').then(() => openBom(bomDetail!.id))">Activate</button>
                            <button v-if="bomDetail.status === 'draft' && canManage" type="button" class="btn btn-outline-dark btn-sm" @click="editBom">Edit</button>
                            <button v-if="bomDetail.status !== 'draft' && canManage" type="button" class="btn btn-outline-dark btn-sm" @click="send('post', `/api/manufacturing/boms/${bomDetail.id}/new-version`, {}, 'New version made')">New version</button>
                        </div>
                        <div>Cost of {{ bomDetail.cost.quantity }}: material {{ money(bomDetail.cost.material) }}, labour {{ money(bomDetail.cost.labor) }}, overhead {{ money(bomDetail.cost.overhead) }}; per unit <strong>{{ money(bomDetail.cost.unit) }}</strong>
                            <span v-if="bomDetail.cost.unpriced.length" class="text-warning"> (no cost yet: {{ bomDetail.cost.unpriced.join(', ') }})</span></div>
                        <ul class="mt-2 mb-0"><li v-for="row in bomDetail.explosion.tree" :key="row.product_id + '-' + row.level" :style="{ marginLeft: (row.level - 1) * 1.2 + 'rem' }">{{ row.name }} x {{ row.quantity }}<span v-if="row.is_assembly" class="text-muted"> (sub-assembly)</span></li></ul>
                    </div>
                </div>
                <div v-if="canManage" class="col-lg-6 border rounded p-3">
                    <h6>{{ bomForm.id ? 'Edit draft version' : 'New bill of materials' }}</h6>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><select v-model="bomForm.product_id" class="form-select form-select-sm" :disabled="bomForm.id > 0" aria-label="Finished product"><option value="">Finished product</option><option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
                        <div class="col-4"><input v-model.number="bomForm.output_qty" type="number" class="form-control form-control-sm" placeholder="Batch quantity" aria-label="Batch quantity" /></div>
                    </div>
                    <div v-for="(item, index) in bomForm.items" :key="index" class="row g-2 mb-1">
                        <div class="col-5"><select v-model="item.component_product_id" class="form-select form-select-sm" aria-label="Component"><option value="">Component</option><option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
                        <div class="col-3"><select v-model="item.component_variation_id" class="form-select form-select-sm" aria-label="Variation"><option value="">Variation</option><option v-for="v in variationsOf(item.component_product_id)" :key="v.id" :value="v.id">{{ v.name }}</option></select></div>
                        <div class="col-2"><input v-model.number="item.quantity" type="number" class="form-control form-control-sm" aria-label="Quantity" /></div>
                        <div class="col-1"><input v-model.number="item.scrap_percent" type="number" class="form-control form-control-sm" title="Scrap %" aria-label="Scrap percent" /></div>
                        <div class="col-1"><button type="button" class="btn btn-outline-danger btn-sm" @click="bomForm.items.splice(index, 1)">x</button></div>
                    </div>
                    <button type="button" class="btn btn-outline-dark btn-sm mt-1" @click="addItem">Add component</button>
                    <button type="button" class="btn btn-outline-dark btn-sm mt-1 ms-2" @click="bomForm.operations.push({ name: '', minutes: 0, labor_rate_per_hour: 0, overhead_rate_per_hour: 0 })">Add operation</button>
                    <button type="button" class="btn btn-outline-dark btn-sm mt-1 ms-2" @click="bomForm.outputs.push({ product_id: '', variation_id: '', kind: 'byproduct', quantity: 1, cost_share_percent: 0 })">Add by-product</button>
                    <div v-for="(op, index) in bomForm.operations" :key="'op' + index" class="row g-2 mt-1">
                        <div class="col-5"><input v-model="op.name" class="form-control form-control-sm" placeholder="Operation" aria-label="Operation name" /></div>
                        <div class="col-2"><input v-model.number="op.minutes" type="number" class="form-control form-control-sm" placeholder="Minutes" aria-label="Minutes" /></div>
                        <div class="col-2"><input v-model.number="op.labor_rate_per_hour" type="number" class="form-control form-control-sm" placeholder="Labour/h" aria-label="Labour rate" /></div>
                        <div class="col-2"><input v-model.number="op.overhead_rate_per_hour" type="number" class="form-control form-control-sm" placeholder="OH/h" aria-label="Overhead rate" /></div>
                        <div class="col-1"><button type="button" class="btn btn-outline-danger btn-sm" @click="bomForm.operations.splice(index, 1)">x</button></div>
                    </div>
                    <div v-for="(out, index) in bomForm.outputs" :key="'out' + index" class="row g-2 mt-1">
                        <div class="col-4"><select v-model="out.product_id" class="form-select form-select-sm" aria-label="Output product"><option value="">By-product</option><option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
                        <div class="col-3"><select v-model="out.variation_id" class="form-select form-select-sm" aria-label="Output variation"><option value="">Variation</option><option v-for="v in variationsOf(out.product_id)" :key="v.id" :value="v.id">{{ v.name }}</option></select></div>
                        <div class="col-2"><select v-model="out.kind" class="form-select form-select-sm" aria-label="Kind"><option value="byproduct">By</option><option value="coproduct">Co</option></select></div>
                        <div class="col-1"><input v-model.number="out.quantity" type="number" class="form-control form-control-sm" aria-label="Output quantity" /></div>
                        <div class="col-1"><input v-model.number="out.cost_share_percent" type="number" class="form-control form-control-sm" title="Cost share %" aria-label="Cost share" /></div>
                        <div class="col-1"><button type="button" class="btn btn-outline-danger btn-sm" @click="bomForm.outputs.splice(index, 1)">x</button></div>
                    </div>
                    <div class="mt-3"><button type="button" class="btn btn-dark btn-sm" :disabled="! bomForm.items.length || ! bomForm.product_id" @click="saveBom">Save draft</button></div>
                </div>
            </div>
        </section>

        <section v-if="tab === 'plan'">
            <div v-if="canManage" class="border rounded p-3 mb-3">
                <div class="row g-2">
                    <div class="col-md-4"><select v-model="planForm.product_id" class="form-select form-select-sm" aria-label="Plan product"><option value="">Product</option><option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
                    <div class="col-md-3"><input v-model="planForm.period_start" type="date" class="form-control form-control-sm" aria-label="Week starting" /></div>
                    <div class="col-md-2"><input v-model.number="planForm.planned_qty" type="number" class="form-control form-control-sm" aria-label="Planned quantity" /></div>
                    <div class="col-md-2"><button type="button" class="btn btn-dark btn-sm" @click="send('post', '/api/manufacturing/plans', { ...planForm }, 'Plan saved')">Save plan</button></div>
                </div>
            </div>
            <table class="table table-sm">
                <thead><tr><th>Week</th><th>Product</th><th class="text-end">Planned</th><th class="text-end">On work orders</th><th class="text-end">Produced</th><th class="text-end">Still to cover</th></tr></thead>
                <tbody>
                    <tr v-for="p in plans" :key="p.id"><td>{{ p.period_start }}</td><td>{{ p.product }}</td><td class="text-end">{{ p.planned_qty }}</td><td class="text-end">{{ p.work_order_qty }}</td><td class="text-end">{{ p.produced_qty }}</td><td class="text-end">{{ p.to_cover }}</td></tr>
                    <tr v-if="! plans.length"><td colspan="6" class="text-center text-muted">No production plan for the next eight weeks.</td></tr>
                </tbody>
            </table>
            <h6>Material requirements for what is still to cover</h6>
            <div v-if="withoutBom.length" class="alert alert-warning py-1 small">No active BOM: {{ withoutBom.join(', ') }}</div>
            <table class="table table-sm">
                <thead><tr><th>Material</th><th class="text-end">Required</th><th class="text-end">On hand</th><th class="text-end">Shortage</th></tr></thead>
                <tbody>
                    <tr v-for="m in materials" :key="m.product_id + '-' + m.variation_id" :class="{ 'table-warning': m.shortage > 0 }"><td>{{ m.name }}</td><td class="text-end">{{ m.required }}</td><td class="text-end">{{ m.on_hand }}</td><td class="text-end">{{ m.shortage }}</td></tr>
                </tbody>
            </table>
        </section>

        <section v-if="tab === 'settings'">
            <div class="alert alert-info small">Stock moves and costs are worked out for every work order. Ledger entries are made only when you switch posting on and map the six accounts below.</div>
            <div class="row g-2 align-items-end">
                <div class="col-md-3 form-check ms-2"><input id="mf-post" v-model="settings.posting_enabled" type="checkbox" class="form-check-input" :disabled="! canManage" /><label class="form-check-label small" for="mf-post">Post manufacturing to the ledger</label></div>
                <div class="col-md-3 form-check"><input id="mf-qc" v-model="settings.require_qc_before_receipt" type="checkbox" class="form-check-input" :disabled="! canManage" /><label class="form-check-label small" for="mf-qc">Receive only what passed a quality check</label></div>
                <div class="col-md-3 form-check"><input id="mf-over" v-model="settings.allow_over_issue" type="checkbox" class="form-check-input" :disabled="! canManage" /><label class="form-check-label small" for="mf-over">Allow issuing more than required</label></div>
                <div v-for="slot in accountSlots" :key="slot" class="col-md-3">
                    <label class="form-label small text-capitalize" :for="`mf-acc-${slot}`">{{ slot.replace(/_/g, ' ') }} account</label>
                    <LookupSelect v-model="settings.accounts[slot]" endpoint="/api/fetchchildaccounts" :params="scope()" placeholder="Choose an account" :aria-label="`${slot.replace(/_/g, ' ')} account`" :disabled="! canManage" />
                </div>
                <div v-if="canManage" class="col-md-2"><button type="button" class="btn btn-dark btn-sm" @click="saveSettings">Save settings</button></div>
            </div>
        </section>
    </div>
</template>
