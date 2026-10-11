<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Inventory Close',
            subtitle: 'Lock stock documents through a period end',
            breadcrumbs: [{ title: 'Inventory Close', href: 'NULL' }],
        },
    });

    type Entry = { id: number; branch_id: number | null; locked_through: string | null; action: string; reason: string | null; created_at: string };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const lockedThrough = ref<string | null>(null);
    const history = ref<Entry[]>([]);
    const closeDate = ref('');
    const closeReason = ref('');
    const reopenDate = ref('');
    const reopenReason = ref('');

    async function load() {
        const response = await run(() => window.axios.get('/api/inventory-close', { params: scope() }));
        lockedThrough.value = response?.data?.locked_through ?? null;
        history.value = response?.data?.history ?? [];
    }

    async function close() {
        if (await run(() => window.axios.post('/api/inventory-close', { ...scope(), locked_through: closeDate.value, reason: closeReason.value }), 'Inventory closed')) {
            closeReason.value = '';
            await load();
        }
    }

    async function reopen() {
        const body = { ...scope(), locked_through: reopenDate.value === '' ? null : reopenDate.value, reason: reopenReason.value };

        if (await run(() => window.axios.post('/api/inventory-close/reopen', body), 'Inventory reopened')) {
            reopenReason.value = '';
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Inventory Close" />

    <div class="container-fluid py-3">
        <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm mb-3" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />

        <div class="border rounded p-3 mb-4">
            <div class="text-muted small">Inventory is closed through</div>
            <div class="fs-4 fw-semibold" data-test="locked-through">{{ lockedThrough ?? 'not closed' }}</div>
            <div class="small text-muted">Stock documents dated on or before this day cannot be created, changed, deleted or restored. The books lock is separate (Financial Year).</div>
        </div>

        <div class="row g-3 mb-4">
            <div v-if="can('/inventoryclose/add')" class="col-md-6">
                <div class="border rounded p-3">
                    <h6>Close inventory</h6>
                    <label class="form-label small" for="close-date">Through</label>
                    <input id="close-date" v-model="closeDate" type="date" class="form-control form-control-sm mb-2" />
                    <label class="form-label small" for="close-reason">Note</label>
                    <input id="close-reason" v-model="closeReason" class="form-control form-control-sm mb-2" />
                    <button type="button" class="btn btn-dark btn-sm" @click="close">Close inventory</button>
                </div>
            </div>
            <div v-if="can('/inventoryclose/reopen') && lockedThrough" class="col-md-6">
                <div class="border rounded p-3">
                    <h6>Reopen</h6>
                    <label class="form-label small" for="reopen-date">Move the close back to (empty opens everything)</label>
                    <input id="reopen-date" v-model="reopenDate" type="date" class="form-control form-control-sm mb-2" />
                    <label class="form-label small" for="reopen-reason">Reason (required)</label>
                    <input id="reopen-reason" v-model="reopenReason" class="form-control form-control-sm mb-2" />
                    <button type="button" class="btn btn-outline-danger btn-sm" @click="reopen">Reopen</button>
                </div>
            </div>
        </div>

        <h6>History</h6>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>When</th><th>Action</th><th>Through</th><th>Reason</th></tr></thead>
                <tbody>
                    <tr v-for="entry in history" :key="entry.id"><td>{{ entry.created_at.slice(0, 16).replace('T', ' ') }}</td><td class="text-capitalize">{{ entry.action }}</td><td>{{ entry.locked_through ?? 'open' }}</td><td>{{ entry.reason }}</td></tr>
                    <tr v-if="! history.length"><td colspan="4" class="text-center text-muted">Never closed.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
