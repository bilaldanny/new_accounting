<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
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

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div v-if="loaded && !groups.length" class="text-muted">You are not the owner of a company group.</div>
        <div v-for="group in groups" :key="group.id" class="border rounded p-3 mb-3">
            <h6>{{ group.name }} <span class="text-muted small">{{ group.month }}</span></h6>
            <ClientTable  :columns="[{ key: 'c0', label: 'Company' }, { key: 'c1', label: 'Status' }, { key: 'c2', label: 'Branches' }, { key: 'c3', label: 'Net sales' }, { key: 'c4', label: 'Net purchases' }]" :rows="(group.companies).map((company) => ({ __s: { company }, c0: '', c1: (company.status), c2: (company.branches), c3: `${money(company.net_sales)} ${company.currency}`, c4: `${money(company.net_purchases)} ${company.currency}` }))" export-name="company">
<template #cell-c0="{ row: __m }"><template v-for="{ company } in [__m.__s]" :key="0">{{ company.name }} <span class="text-muted small">{{ company.code }}</span></template></template>
</ClientTable>
            <div v-if="!group.totals" class="small text-muted mt-2">The companies keep their books in different currencies, so no total is shown.</div>
        </div>
    </div></div></div>
</template>
