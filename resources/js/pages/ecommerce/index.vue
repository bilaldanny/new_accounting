<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'E-commerce Sync',
            subtitle: 'Pull online orders into sales (Shopify, WooCommerce, or the built-in mock)',
            breadcrumbs: [{ title: 'E-commerce Sync', href: 'NULL' }],
        },
    });

    type Connection = {
        id: number; platform: string; name: string; store_url: string | null; default_contact_id: number | null; is_active: boolean;
        configured: boolean; last_synced_at: string | null; orders: Record<string, number>;
    };
    type Order = { id: number; external_id: string; status: string; error: string | null; transaction_id: number | null; created_at: string };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rows = ref<Connection[]>([]);
    const orders = ref<Order[]>([]);
    const shown = ref<Connection | null>(null);
    const editing = ref<number | null>(null);
    const blank = () => ({ platform: 'shopify', name: '', store_url: '', default_contact_id: '' as number | '', is_active: true, access_token: '', consumer_key: '', consumer_secret: '' });
    const form = ref(blank());

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/ecommerce-connections', { params: scope() })))?.data?.data ?? [];
    }

    function edit(row: Connection) {
        editing.value = row.id;
        form.value = { ...blank(), platform: row.platform, name: row.name, store_url: row.store_url ?? '', default_contact_id: row.default_contact_id ?? '', is_active: row.is_active };
    }

    async function save() {
        const credentials: Record<string, string> = {};

        if (form.value.platform === 'shopify' && form.value.access_token) {
            credentials.access_token = form.value.access_token;
        }

        if (form.value.platform === 'woocommerce' && form.value.consumer_key) {
            credentials.consumer_key = form.value.consumer_key;
            credentials.consumer_secret = form.value.consumer_secret;
        }

        const body: Record<string, unknown> = {
            ...scope(), platform: form.value.platform, name: form.value.name, store_url: form.value.store_url || null,
            default_contact_id: form.value.default_contact_id === '' ? null : form.value.default_contact_id, is_active: form.value.is_active,
            credentials: Object.keys(credentials).length ? credentials : null,
        };
        const request = editing.value === null ? () => window.axios.post('/api/ecommerce-connections', body) : () => window.axios.put(`/api/ecommerce-connections/${editing.value}`, body);

        if (await run(request, 'Connection saved')) {
            editing.value = null;
            form.value = blank();
            await load();
        }
    }

    async function sync(row: Connection) {
        const result = await run(() => window.axios.post(`/api/ecommerce-connections/${row.id}/sync`));

        if (result) {
            window.alert(result.data.message);
            await load();
            await showOrders(row);
        }
    }

    async function showOrders(row: Connection) {
        shown.value = row;
        orders.value = (await run(() => window.axios.get(`/api/ecommerce-connections/${row.id}/orders`)))?.data?.data ?? [];
    }

    async function retry(order: Order) {
        if (shown.value && await run(() => window.axios.post(`/api/ecommerce-connections/${shown.value?.id}/orders/${order.id}/retry`), 'Order imported')) {
            await showOrders(shown.value);
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="E-commerce Sync" />

    <div class="container-fluid py-3">
        <div class="alert alert-info py-2 small">Shopify and WooCommerce connections use their REST APIs and have not been verified against a live store from here; use the mock platform to rehearse the flow.</div>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        </div>

        <div v-if="can('/ecommerce/add') || (editing !== null && can('/ecommerce/:id/edit'))" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small" for="ec-platform">Platform</label>
                    <select id="ec-platform" v-model="form.platform" class="form-select form-select-sm"><option value="shopify">Shopify</option><option value="woocommerce">WooCommerce</option><option value="mock">Mock</option></select>
                </div>
                <div class="col-md-3"><label class="form-label small" for="ec-name">Name</label><input id="ec-name" v-model="form.name" class="form-control form-control-sm" /></div>
                <div class="col-md-4"><label class="form-label small" for="ec-url">Store URL</label><input id="ec-url" v-model="form.store_url" type="url" class="form-control form-control-sm" placeholder="https://" /></div>
                <div class="col-md-3"><label class="form-label small" for="ec-contact">Default customer id</label><input id="ec-contact" v-model.number="form.default_contact_id" type="number" class="form-control form-control-sm" /></div>
                <div v-if="form.platform === 'shopify'" class="col-md-6"><label class="form-label small" for="ec-token">Access token</label><input id="ec-token" v-model="form.access_token" type="password" autocomplete="off" class="form-control form-control-sm" :placeholder="editing === null ? '' : 'Keep current'" /></div>
                <template v-if="form.platform === 'woocommerce'">
                    <div class="col-md-3"><label class="form-label small" for="ec-key">Consumer key</label><input id="ec-key" v-model="form.consumer_key" type="password" autocomplete="off" class="form-control form-control-sm" /></div>
                    <div class="col-md-3"><label class="form-label small" for="ec-secret">Consumer secret</label><input id="ec-secret" v-model="form.consumer_secret" type="password" autocomplete="off" class="form-control form-control-sm" /></div>
                </template>
                <div class="col-md-2 d-flex align-items-end"><label class="form-check-label small"><input v-model="form.is_active" type="checkbox" class="form-check-input me-1" />Active</label></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3 me-2" @click="save">{{ editing === null ? 'Add connection' : 'Save connection' }}</button>
            <button v-if="editing !== null" type="button" class="btn btn-outline-dark btn-sm mt-3" @click="editing = null; form = blank()">Cancel</button>
        </div>

        <table class="table table-sm align-middle">
            <thead><tr><th>Name</th><th>Platform</th><th>Configured</th><th>Orders</th><th></th></tr></thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td>{{ row.name }}<span v-if="! row.is_active" class="badge text-bg-secondary ms-1">inactive</span></td><td class="text-capitalize">{{ row.platform }}</td><td>{{ row.configured ? 'Yes' : 'No' }}</td>
                    <td><span v-for="(count, key) in row.orders" :key="key" class="me-2">{{ key }}: {{ count }}</span></td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="showOrders(row)">Orders</button>
                        <button v-if="can('/ecommerce/:id/edit')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="edit(row)">Edit</button>
                        <button v-if="can('/ecommerce/sync')" type="button" class="btn btn-dark btn-sm" @click="sync(row)">Sync now</button>
                    </td>
                </tr>
                <tr v-if="! rows.length"><td colspan="5" class="text-center text-muted">No connections.</td></tr>
            </tbody>
        </table>

        <div v-if="shown">
            <h6>Orders of {{ shown.name }}</h6>
            <table class="table table-sm align-middle">
                <thead><tr><th>Order</th><th>Status</th><th>Sale</th><th>Error</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="order in orders" :key="order.id">
                        <td>{{ order.external_id }}</td><td class="text-capitalize">{{ order.status }}</td><td>{{ order.transaction_id ? '#' + order.transaction_id : '' }}</td><td class="small text-danger">{{ order.error }}</td>
                        <td class="text-end"><button v-if="order.status !== 'imported' && can('/ecommerce/sync')" type="button" class="btn btn-outline-dark btn-sm" @click="retry(order)">Retry</button></td>
                    </tr>
                    <tr v-if="! orders.length"><td colspan="5" class="text-center text-muted">No orders pulled yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
