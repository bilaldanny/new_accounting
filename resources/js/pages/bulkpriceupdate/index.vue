<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, reactive, ref } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Bulk Price Update',
            subtitle: 'Change a price of many products at once by a percentage, a fixed amount or a set value, and see the result before saving',
            breadcrumbs: [
                {
                    title: 'Bulk Price Update',
                    href: 'NULL',
                },
            ],
        },
    });

    type Row = { id: number; product_name: string; variation_name: string | null; sku: string; old_price: number | null; new_price: number | null; skipped: boolean };

    const { Notify } = useCommons();
    const { props } = usePage();

    const authUser = computed(() => props.auth?.user as { rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const canApply = computed(() => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes('/bulkpriceupdate/apply'));

    const fields = [
        { value: 'default_sell_price', label: 'Sell price' },
        { value: 'default_purchase_price', label: 'Purchase price' },
        { value: 'min_sell_price', label: 'Minimum selling price' },
        { value: 'max_sell_price', label: 'Maximum selling price' },
    ];

    const form = reactive({ company_id: '', field: 'default_sell_price', mode: 'percent', value: '', round_to: '', category_id: '', brand_id: '', search: '' });

    const modes = computed(() => [
        { value: 'percent', label: 'Adjust by a percentage (+/-)' },
        { value: 'fixed', label: 'Add or take off an amount (+/-)' },
        { value: 'set', label: 'Set to a value' },
        ...(form.field === 'min_sell_price' || form.field === 'max_sell_price' ? [{ value: 'percent_of_sell', label: 'Percentage of the sell price' }] : []),
    ]);

    const rows = ref<Row[]>([]);
    const summary = reactive({ total: 0, will_change: 0, skipped: 0 });
    const previewed = ref(false);
    const busy = ref(false);

    const money = (value: number | null): string => (value === null ? '-' : Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

    function body(): Record<string, string | number> {
        const result: Record<string, string | number> = { field: form.field, mode: form.mode, value: form.value };

        for (const key of ['company_id', 'round_to', 'category_id', 'brand_id', 'search'] as const) {
            if (form[key] !== '') {
                result[key] = form[key];
            }
        }

        return result;
    }

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    async function preview() {
        busy.value = true;

        try {
            const response = await window.axios.post('/api/bulk-price-update/preview', body());
            rows.value = response.data.data;
            summary.total = response.data.total;
            summary.will_change = response.data.will_change;
            summary.skipped = response.data.skipped;
            previewed.value = true;
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    async function apply() {
        if (! window.confirm(`Update ${summary.will_change} price(s)? Every change is kept in the Price & Cost History.`)) {
            return;
        }

        busy.value = true;

        try {
            const response = await window.axios.post('/api/bulk-price-update/apply', body());
            Notify(response.data.message, 'success');
            await preview();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }
</script>

<template>
    <Head title="Bulk Price Update" />

    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2" @submit.prevent="preview">
                <div v-if="isSuperadmin" class="col-md-2">
                    <label class="form-label">Company ID</label>
                    <input v-model="form.company_id" type="number" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Price to change</label>
                    <select v-model="form.field" class="form-select" @change="form.mode = 'percent'">
                        <option v-for="field in fields" :key="field.value" :value="field.value">{{ field.label }}</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">How</label>
                    <select v-model="form.mode" class="form-select"><option v-for="mode in modes" :key="mode.value" :value="mode.value">{{ mode.label }}</option></select>
                </div>
                <div class="col-md-2"><label class="form-label">Value</label><input v-model="form.value" type="number" step="0.01" class="form-control" required></div>
                <div class="col-md-2"><label class="form-label">Round to</label><input v-model="form.round_to" type="number" step="0.01" min="0.01" class="form-control" placeholder="0.01"></div>
                <div class="col-md-2"><label class="form-label">Category ID</label><input v-model="form.category_id" type="number" class="form-control"></div>
                <div class="col-md-2"><label class="form-label">Brand ID</label><input v-model="form.brand_id" type="number" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">Name or SKU contains</label><input v-model="form.search" class="form-control"></div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary" :disabled="busy">Preview</button>
                    <button v-if="canApply" type="button" class="btn btn-danger" :disabled="busy || ! previewed || ! summary.will_change" @click="apply">Apply to {{ summary.will_change }}</button>
                </div>
            </form>
        </div>
    </div>

    <div v-if="previewed" class="card">
        <div class="card-body">
            <p class="mb-2">{{ summary.total }} variation(s) match; {{ summary.will_change }} would change; {{ summary.skipped }} skipped (no current value to adjust). Showing the first {{ rows.length }}.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Product</th><th>SKU</th><th class="text-end">Now</th><th class="text-end">After</th></tr></thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" :class="{ 'text-muted': row.skipped }">
                            <td>{{ row.product_name }}<span v-if="row.variation_name"> - {{ row.variation_name }}</span></td>
                            <td>{{ row.sku }}</td>
                            <td class="text-end">{{ money(row.old_price) }}</td>
                            <td class="text-end fw-bold">{{ row.skipped ? 'skipped' : money(row.new_price) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
