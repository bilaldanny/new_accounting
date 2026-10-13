<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref } from 'vue';
    import LookupSelect from '@/components/LookupSelect.vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Online Shop',
            subtitle: 'The public shop of your company: published products and the orders that come in',
            breadcrumbs: [{ title: 'Online Shop', href: 'NULL' }],
        },
    });

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const settings = reactive({ code: '', enabled: false, title: '', intro: '', default_contact_id: '' as number | '' });
    const products = ref<any[]>([]);
    const orders = ref<any[]>([]);
    const publish = reactive({ product_id: '' as number | '', price: '' as number | '' });
    const customer = reactive<Record<number, number | ''>>({});
    const canManage = computed(() => can('/storefront/manage'));
    /** The settings form only appears once the saved settings are in, so a reply arriving late cannot overwrite what someone is typing. */
    const settingsLoaded = ref(false);
    const shopUrl = computed(() => (settings.code ? `${window.location.origin}/shop/${settings.code}` : ''));

    async function load() {
        const s = (await run(() => window.axios.get('/api/storefront-admin/settings', { params: scope() })))?.data?.data;
        Object.assign(settings, { code: s?.code ?? '', enabled: Boolean(s?.enabled), title: s?.title ?? '', intro: s?.intro ?? '', default_contact_id: s?.default_contact_id ?? '' });
        settingsLoaded.value = true;
        products.value = (await run(() => window.axios.get('/api/storefront-admin/products', { params: scope() })))?.data?.data ?? [];
        orders.value = (await run(() => window.axios.get('/api/storefront-admin/orders', { params: scope() })))?.data?.data ?? [];
    }

    async function send(method: 'post' | 'put', url: string, body: Record<string, unknown>, ok: string) {
        const clean = Object.fromEntries(Object.entries({ ...scope(), ...body }).filter(([, v]) => v !== ''));

        if (await run(() => window.axios[method](url, clean), ok)) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Online Shop" />

    <div class="container-fluid py-3">
        <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm mb-3" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />

        <div class="row g-3">
            <div class="col-lg-5">
                <div class="border rounded p-3 mb-3">
                    <h6>Shop settings</h6>
                    <div v-if="settingsLoaded" class="row g-2">
                        <div class="col-6"><input v-model="settings.code" class="form-control form-control-sm" placeholder="shop-code" aria-label="Shop code" :disabled="! canManage" /></div>
                        <div class="col-6"><input v-model="settings.title" class="form-control form-control-sm" placeholder="Shop title" aria-label="Shop title" :disabled="! canManage" /></div>
                        <div class="col-12"><input v-model="settings.intro" class="form-control form-control-sm" placeholder="A line under the title" aria-label="Intro" :disabled="! canManage" /></div>
                        <div class="col-6"><LookupSelect v-model="settings.default_contact_id" endpoint="/api/fetchcustomers" :params="scope()" placeholder="Default customer for orders" aria-label="Default customer" :disabled="! canManage" /></div>
                        <div class="col-6 form-check ps-4"><input id="shop-on" v-model="settings.enabled" type="checkbox" class="form-check-input" :disabled="! canManage" /><label class="form-check-label small" for="shop-on">Shop is open</label></div>
                    </div>
                    <button v-if="canManage" type="button" class="btn btn-dark btn-sm mt-2" @click="send('put', '/api/storefront-admin/settings', { ...settings }, 'Settings saved')">Save</button>
                    <div v-if="shopUrl && settings.enabled" class="small mt-2">Public address: <a :href="shopUrl" target="_blank" rel="noopener">{{ shopUrl }}</a></div>
                    <div class="small text-muted mt-2">Orders are requests: they do not touch stock or the books until you turn one into a sale. There is no online payment.</div>
                </div>

                <div class="border rounded p-3">
                    <h6>Published products</h6>
                    <div v-if="canManage" class="d-flex gap-2 mb-2">
                        <LookupSelect v-model="publish.product_id" endpoint="/api/fetchproducts" :params="scope()" placeholder="Product" aria-label="Product" />
                        <input v-model.number="publish.price" type="number" class="form-control form-control-sm" placeholder="Shop price (blank = list price)" aria-label="Shop price" />
                        <button type="button" class="btn btn-dark btn-sm" @click="send('post', '/api/storefront-admin/products', { ...publish, is_published: true }, 'Published')">Publish</button>
                    </div>
                    <ul class="small mb-0">
                        <li v-for="p in products" :key="p.id">
                            {{ p.name }} <span v-if="p.price !== null">at {{ money(p.price) }}</span> <span v-if="! p.is_published" class="text-muted">(hidden)</span>
                            <button v-if="canManage" type="button" class="btn btn-link btn-sm py-0" @click="send('post', '/api/storefront-admin/products', { product_id: p.product_id, variation_id: p.variation_id, price: p.price ?? '', is_published: ! p.is_published }, 'Updated')">{{ p.is_published ? 'Hide' : 'Show' }}</button>
                        </li>
                        <li v-if="! products.length" class="text-muted">Nothing is published.</li>
                    </ul>
                </div>
            </div>

            <div class="col-lg-7">
                <h6>Orders</h6>
                <table class="table table-sm">
                    <thead><tr><th>Order</th><th>Customer</th><th>Items</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="o in orders" :key="o.id">
                            <td>{{ o.order_no }}</td>
                            <td>{{ o.customer_name }}<div class="small text-muted">{{ o.phone }} {{ o.address }}</div></td>
                            <td class="small"><div v-for="l in o.items" :key="l.product_id + '-' + l.variation_id">{{ l.name }} x {{ l.quantity }}</div></td>
                            <td class="text-end">{{ money(o.total) }}</td><td class="text-capitalize">{{ o.status }}</td>
                            <td class="text-end">
                                <template v-if="o.status === 'new' && canManage">
                                    <div class="d-inline-block mb-1" style="width: 12rem"><LookupSelect v-model="customer[o.id]" endpoint="/api/fetchcustomers" :params="scope()" placeholder="Customer" aria-label="Customer for the sale" /></div>
                                    <button type="button" class="btn btn-dark btn-sm ms-1" @click="send('post', `/api/storefront-admin/orders/${o.id}/convert`, { contact_id: customer[o.id] ?? '' }, 'Draft sale made')">Make sale</button>
                                    <button type="button" class="btn btn-outline-danger btn-sm ms-1" @click="send('post', `/api/storefront-admin/orders/${o.id}/reject`, {}, 'Order rejected')">Reject</button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="! orders.length"><td colspan="6" class="text-center text-muted">No orders yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
