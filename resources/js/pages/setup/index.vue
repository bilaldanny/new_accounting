<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { computed, onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Setup Wizard',
            subtitle: 'What is left to set up in this company',
            breadcrumbs: [{ title: 'Settings', href: 'NULL' }, { title: 'Setup Wizard', href: 'NULL' }],
        },
    });

    type Step = { key: string; label: string; done: boolean; skipped: boolean; link: string };
    type Wizard = { steps: Step[]; done: number; total: number; complete: boolean };

    const { companyId, isSuperadmin, scope, run } = useCloseout();
    const wizard = ref<Wizard | null>(null);
    const percent = computed(() => (wizard.value && wizard.value.total ? Math.round((wizard.value.done / wizard.value.total) * 100) : 0));

    async function load() {
        const response = await run(() => window.axios.get('/api/setup-wizard', { params: scope() }));
        wizard.value = response?.data?.data ?? null;
    }

    async function skip(step: Step, value: boolean) {
        const response = await run(() => window.axios.put(`/api/setup-wizard/${step.key}`, { ...scope(), skip: value }));
        wizard.value = response?.data?.data ?? wizard.value;
    }

    onMounted(load);
</script>

<template>
    <Head title="Setup Wizard" />
    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">

    <div class="container-fluid py-3" style="max-width: 46rem">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div v-if="wizard">
            <div class="progress mb-1" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-dark" :style="{ width: `${percent}%` }" /></div>
            <p class="small text-muted">{{ wizard.done }} of {{ wizard.total }} steps done<span v-if="wizard.complete"> - everything is set up or skipped.</span></p>

            <div v-for="step in wizard.steps" :key="step.key" class="d-flex align-items-center gap-2 border-bottom py-2">
                <span class="badge" :class="step.done ? 'text-bg-success' : step.skipped ? 'text-bg-secondary' : 'text-bg-warning'">{{ step.done ? 'Done' : step.skipped ? 'Skipped' : 'To do' }}</span>
                <span class="flex-grow-1">{{ step.label }}</span>
                <a v-if="!step.done" class="btn btn-outline-dark btn-sm py-0" :href="step.link">Open</a>
                <button v-if="!step.done && !step.skipped" type="button" class="btn btn-link btn-sm" @click="skip(step, true)">Skip</button>
                <button v-if="step.skipped" type="button" class="btn btn-link btn-sm" @click="skip(step, false)">Undo skip</button>
            </div>
        </div>
    </div>
    </div></div></div>
</template>
