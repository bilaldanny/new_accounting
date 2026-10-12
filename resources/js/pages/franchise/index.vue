<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Franchise Overview',
            subtitle: 'The month at a glance for each company of your group',
            breadcrumbs: [{ title: 'Reports', href: 'NULL' }, { title: 'Franchise Overview', href: 'NULL' }],
        },
    });

    type Group = {
        id: number; name: string; month: string;
        companies: { company_id: number; name: string; code: string; status: string; currency: string | null; branches: number; net_sales: number; net_purchases: number }[];
        totals: { net_sales: number; net_purchases: number; currency: string } | null;
    };

    const { money, run } = useCloseout();
    const groups = ref<Group[]>([]);
    const loaded = ref(false);

    onMounted(async () => {
        const response = await run(() => window.axios.get('/api/franchise/overview'));
        groups.value = response?.data?.data ?? [];
        loaded.value = true;
    });
</script>

<template>
    <Head title="Franchise Overview" />

    <div class="container-fluid py-3">
        <div v-if="loaded && !groups.length" class="text-muted">You are not the owner of a company group.</div>
        <div v-for="group in groups" :key="group.id" class="border rounded p-3 mb-3">
            <h6>{{ group.name }} <span class="text-muted small">{{ group.month }}</span></h6>
            <table class="table table-sm mb-0">
                <thead><tr><th>Company</th><th>Status</th><th class="text-end">Branches</th><th class="text-end">Net sales</th><th class="text-end">Net purchases</th></tr></thead>
                <tbody>
                    <tr v-for="company in group.companies" :key="company.company_id"><td>{{ company.name }} <span class="text-muted small">{{ company.code }}</span></td><td>{{ company.status }}</td><td class="text-end">{{ company.branches }}</td><td class="text-end">{{ money(company.net_sales) }} {{ company.currency }}</td><td class="text-end">{{ money(company.net_purchases) }} {{ company.currency }}</td></tr>
                    <tr v-if="group.totals" class="fw-bold"><td colspan="3">Total ({{ group.totals.currency }})</td><td class="text-end">{{ money(group.totals.net_sales) }}</td><td class="text-end">{{ money(group.totals.net_purchases) }}</td></tr>
                </tbody>
            </table>
            <div v-if="!group.totals" class="small text-muted mt-2">The companies keep their books in different currencies, so no total is shown.</div>
        </div>
    </div>
</template>
