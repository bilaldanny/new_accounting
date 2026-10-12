<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref } from 'vue';

    const props = defineProps<{ code: string; title: string }>();

    type Product = { product_id: number; variation_id: number; name: string; unit: string | null; price: number };

    const products = ref<Product[]>([]);
    const intro = ref('');
    const cart = reactive<Record<string, number>>({});
    const form = reactive({ name: '', phone: '', address: '', note: '', website_url: '' });
    const message = ref('');
    const error = ref('');
    const busy = ref(false);

    const key = (p: Product) => `${p.product_id}:${p.variation_id}`;
    const lines = computed(() => products.value.filter((p) => (cart[key(p)] ?? 0) > 0).map((p) => ({ ...p, quantity: cart[key(p)] })));
    const total = computed(() => lines.value.reduce((sum, l) => sum + l.price * l.quantity, 0));
    const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    async function load() {
        try {
            const response = await window.axios.get(`/api/storefront/${props.code}`);
            products.value = response.data.data.products;
            intro.value = response.data.data.intro ?? '';
        } catch {
            error.value = 'The shop could not be loaded.';
        }
    }

    function add(p: Product, step: number) {
        cart[key(p)] = Math.max(0, Math.min(1000, (cart[key(p)] ?? 0) + step));
    }

    async function order() {
        busy.value = true;
        error.value = '';

        try {
            const response = await window.axios.post(`/api/storefront/${props.code}/orders`, {
                ...form, lines: lines.value.map((l) => ({ product_id: l.product_id, variation_id: l.variation_id, quantity: l.quantity })),
            });
            message.value = `${response.data.message} Your order number is ${response.data.data?.order_no ?? ''}.`;
            Object.keys(cart).forEach((k) => delete cart[k]);
        } catch (e: any) {
            error.value = e?.response?.data?.message ?? 'The order could not be sent. Check your name and phone number.';
        } finally {
            busy.value = false;
        }
    }

    onMounted(load);
</script>

<template>
    <Head :title="title" />

    <div class="container py-4" style="max-width: 960px">
        <h1 class="h3">{{ title }}</h1>
        <p v-if="intro" class="text-muted">{{ intro }}</p>
        <div v-if="message" class="alert alert-success">{{ message }}</div>
        <div v-if="error" class="alert alert-danger">{{ error }}</div>

        <div class="row g-4">
            <div class="col-md-7">
                <div v-for="p in products" :key="key(p)" class="d-flex justify-content-between align-items-center border rounded p-2 mb-2">
                    <div><div>{{ p.name }}</div><div class="small text-muted">{{ money(p.price) }} <span v-if="p.unit">per {{ p.unit }}</span></div></div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-dark btn-sm" :aria-label="`Remove one ${p.name}`" @click="add(p, -1)">-</button>
                        <span style="min-width: 1.5rem; text-align: center">{{ cart[key(p)] ?? 0 }}</span>
                        <button type="button" class="btn btn-dark btn-sm" :aria-label="`Add one ${p.name}`" @click="add(p, 1)">+</button>
                    </div>
                </div>
                <div v-if="! products.length && ! error" class="text-muted">Nothing is on sale right now.</div>
            </div>

            <div class="col-md-5">
                <div class="border rounded p-3">
                    <h2 class="h6">Your order</h2>
                    <ul class="small">
                        <li v-for="l in lines" :key="key(l)">{{ l.name }} x {{ l.quantity }} = {{ money(l.price * l.quantity) }}</li>
                        <li v-if="! lines.length" class="text-muted">The cart is empty.</li>
                    </ul>
                    <div class="mb-3"><strong>Total {{ money(total) }}</strong> <span class="small text-muted">(payment on delivery or on invoice)</span></div>
                    <input v-model="form.name" class="form-control form-control-sm mb-2" placeholder="Your name" aria-label="Your name" />
                    <input v-model="form.phone" class="form-control form-control-sm mb-2" placeholder="Phone number" aria-label="Phone number" />
                    <input v-model="form.address" class="form-control form-control-sm mb-2" placeholder="Delivery address" aria-label="Delivery address" />
                    <input v-model="form.note" class="form-control form-control-sm mb-2" placeholder="Note (optional)" aria-label="Note" />
                    <input v-model="form.website_url" class="d-none" tabindex="-1" autocomplete="off" aria-hidden="true" />
                    <button type="button" class="btn btn-dark w-100" :disabled="busy || ! lines.length || ! form.name || ! form.phone" @click="order">Send order</button>
                </div>
            </div>
        </div>
    </div>
</template>
