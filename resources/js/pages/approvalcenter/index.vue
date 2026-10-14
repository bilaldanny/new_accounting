<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Approval Center',
            subtitle: 'Workflows, inbox, outbox, history and delegation',
            breadcrumbs: [{ title: 'Approval Center', href: 'NULL' }],
        },
    });

    type Inbox = { type: string; label: string; id: number; number: string; amount: number; level: number; of: number; level_label: string; workflow: string; approve_url: string };
    type Outbox = { id: number; label: string; document_no: string; amount: number; status: string; current_level: number; levels: number };
    type Level = { level_no: number; label: string; mode: string; approvers: { type: string; id: number }[] };
    type Workflow = { id: number; name: string; document_type: string; min_amount: number | null; max_amount: number | null; priority: number; is_active: boolean; levels: Level[] };
    type Action = { id: number; document_type: string; document_no: string; level_no: number; action: string; comment: string | null; created_at: string; user_email: string; on_behalf_of_email: string | null };
    type Delegation = { id: number; from_user_id: number; to_user_id: number; starts_on: string; ends_on: string; is_active: boolean };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const tab = ref<'inbox' | 'outbox' | 'history' | 'workflows' | 'delegation' | 'discounts'>('inbox');
    const inbox = ref<Inbox[]>([]);
    const outbox = ref<Outbox[]>([]);
    const history = ref<Action[]>([]);
    const workflows = ref<Workflow[]>([]);
    const delegations = ref<Delegation[]>([]);
    const types = ref<{ type: string; label: string }[]>([]);
    const pendingDiscounts = ref<{ id: number; name: string; code: string; value: number }[]>([]);
    const discountApproval = ref(false);
    type FormLevel = { label: string; mode: string; users: number[]; roles: number[] };
    const directory = ref<{ users: { id: number; name: string }[]; roles: { id: number; name: string }[]; departments: { id: number; name: string }[] }>({ users: [], roles: [], departments: [] });
    const form = ref<{ name: string; document_type: string; min_amount: number | ''; max_amount: number | ''; department_id: number | ''; levels: FormLevel[] }>({
        name: '', document_type: 'journal', min_amount: '', max_amount: '', department_id: '', levels: [{ label: 'Level 1', mode: 'any', users: [], roles: [] }],
    });
    const delegate = ref({ to_user_id: '' as number | '', starts_on: new Date().toISOString().slice(0, 10), ends_on: new Date().toISOString().slice(0, 10), reason: '' });

    async function load() {
        const get = (url: string) => run(() => window.axios.get(url, { params: scope() }));
        const [i, o, h, w, d, t, s, pd, dir] = await Promise.all([
            get('/api/approval-center/inbox'), get('/api/approval-center/outbox'), get('/api/approval-center/history'), get('/api/approval-center/workflows'),
            get('/api/approval-center/delegations'), get('/api/approval-center/types'), get('/api/approval-center/settings'), get('/api/approval-center/discount-codes'),
            get('/api/approval-center/directory'),
        ]);
        directory.value = dir?.data?.data ?? directory.value;
        inbox.value = i?.data?.data ?? [];
        outbox.value = o?.data?.data ?? [];
        history.value = h?.data?.data ?? [];
        workflows.value = w?.data?.data ?? [];
        delegations.value = d?.data?.data ?? [];
        types.value = t?.data?.data ?? [];
        discountApproval.value = s?.data?.data?.discount_code_approval ?? false;
        pendingDiscounts.value = pd?.data?.data ?? [];
    }

    async function act(item: Inbox, action: 'approve' | 'reject') {
        const url = item.approve_url.replace(/approve$/, action);
        const comment = action === 'reject' ? window.prompt('Reason for rejecting', '') : '';

        if (action === 'reject' && comment === null) {
            return;
        }

        const response = await run(() => window.axios.post(url, { reason: comment || undefined, ...scope() }), action === 'approve' ? 'Approved' : 'Rejected');

        if (response) {
            await load();
        }
    }

    async function saveWorkflow() {
        const body = {
            ...scope(), name: form.value.name, document_type: form.value.document_type, min_amount: form.value.min_amount === '' ? null : form.value.min_amount,
            max_amount: form.value.max_amount === '' ? null : form.value.max_amount, department_id: form.value.department_id === '' ? null : form.value.department_id,
            levels: form.value.levels.map((level) => ({
                label: level.label, mode: level.mode,
                approvers: [...level.users.map((id) => ({ type: 'user', id })), ...level.roles.map((id) => ({ type: 'role', id }))],
            })),
        };

        if (await run(() => window.axios.post('/api/approval-center/workflows', body), 'Workflow saved')) {
            form.value.name = '';
            await load();
        }
    }

    async function removeWorkflow(workflow: Workflow) {
        if (await run(() => window.axios.delete(`/api/approval-center/workflows/${workflow.id}`, { params: scope() }), 'Done')) {
            await load();
        }
    }

    async function saveDelegation() {
        if (await run(() => window.axios.post('/api/approval-center/delegations', { ...scope(), ...delegate.value }), 'Delegation saved')) {
            await load();
        }
    }

    async function endDelegation(row: Delegation) {
        if (await run(() => window.axios.post(`/api/approval-center/delegations/${row.id}/end`, scope()), 'Ended')) {
            await load();
        }
    }

    async function saveDiscountSetting() {
        await run(() => window.axios.put('/api/approval-center/settings', { ...scope(), discount_code_approval: discountApproval.value }), 'Saved');
    }

    async function decideDiscount(id: number, decision: 'approve' | 'reject') {
        if (await run(() => window.axios.post(`/api/approval-center/discount-codes/${id}/${decision}`, scope()), 'Done')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Approval Center" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <ul class="nav nav-tabs mb-3">
            <li v-for="item in ['inbox', 'outbox', 'history', 'workflows', 'delegation', 'discounts']" :key="item" class="nav-item">
                <button type="button" class="nav-link text-capitalize" :class="{ active: tab === item }" :dusk="`tab-${item}`" @click="tab = item as typeof tab">{{ item === 'inbox' ? `Inbox (${inbox.length})` : item }}</button>
            </li>
        </ul>

        <div v-if="tab === 'inbox'">
            <table class="table table-sm" dusk="inbox">
                <thead><tr><th>Type</th><th>Document</th><th class="text-end">Amount</th><th>Level</th><th>Workflow</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="item in inbox" :key="item.type + item.id">
                        <td>{{ item.label }}</td><td>{{ item.number }}</td><td class="text-end">{{ money(item.amount) }}</td><td>{{ item.level }} of {{ item.of }}: {{ item.level_label }}</td><td>{{ item.workflow }}</td>
                        <td class="text-end"><button type="button" class="btn btn-dark btn-sm me-1" @click="act(item, 'approve')">Approve</button><button type="button" class="btn btn-outline-danger btn-sm" @click="act(item, 'reject')">Reject</button></td>
                    </tr>
                    <tr v-if="! inbox.length"><td colspan="6" class="text-center text-muted">Nothing is waiting for you.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="tab === 'outbox'">
            <table class="table table-sm">
                <thead><tr><th>Type</th><th>Document</th><th class="text-end">Amount</th><th>Progress</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="row in outbox" :key="row.id"><td>{{ row.label }}</td><td>{{ row.document_no }}</td><td class="text-end">{{ money(row.amount) }}</td><td>level {{ row.current_level }} of {{ row.levels }}</td><td class="text-capitalize">{{ row.status }}</td></tr>
                    <tr v-if="! outbox.length"><td colspan="5" class="text-center text-muted">None of your documents is in a workflow.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="tab === 'history'">
            <table class="table table-sm">
                <thead><tr><th>When</th><th>Document</th><th>Level</th><th>Action</th><th>By</th><th>Comment</th></tr></thead>
                <tbody>
                    <tr v-for="row in history" :key="row.id"><td>{{ row.created_at }}</td><td>{{ row.document_type }} {{ row.document_no }}</td><td>{{ row.level_no }}</td><td class="text-capitalize">{{ row.action }}</td><td>{{ row.user_email }}<span v-if="row.on_behalf_of_email" class="text-muted"> for {{ row.on_behalf_of_email }}</span></td><td>{{ row.comment }}</td></tr>
                    <tr v-if="! history.length"><td colspan="6" class="text-center text-muted">No approvals recorded yet.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="tab === 'workflows'">
            <div v-if="can('/approvalcenter/manage')" class="border rounded p-3 mb-3">
                <h6>New workflow</h6>
                <div class="row g-2 mb-2">
                    <div class="col-md-3"><input v-model="form.name" class="form-control form-control-sm" placeholder="Name" aria-label="Name" /></div>
                    <div class="col-md-2"><select v-model="form.document_type" class="form-select form-select-sm" aria-label="Document type"><option v-for="type in types" :key="type.type" :value="type.type">{{ type.label }}</option></select></div>
                    <div class="col-md-2"><input v-model.number="form.min_amount" type="number" class="form-control form-control-sm" placeholder="Amount from" aria-label="Amount from" /></div>
                    <div class="col-md-2"><input v-model.number="form.max_amount" type="number" class="form-control form-control-sm" placeholder="Amount to" aria-label="Amount to" /></div>
                    <div class="col-md-3"><select v-model="form.department_id" class="form-select form-select-sm" aria-label="Department of the person who made it"><option value="">Any department</option><option v-for="d in directory.departments" :key="d.id" :value="d.id">{{ d.name }}</option></select></div>
                </div>
                <div v-for="(level, index) in form.levels" :key="index" class="row g-2 mb-1">
                    <div class="col-md-3"><input v-model="level.label" class="form-control form-control-sm" aria-label="Level label" /></div>
                    <div class="col-md-2"><select v-model="level.mode" class="form-select form-select-sm" aria-label="Mode"><option value="any">Any one approver</option><option value="all">All approvers</option></select></div>
                    <div class="col-md-3"><select v-model="level.users" multiple class="form-select form-select-sm" size="3" aria-label="Approver users"><option v-for="u in directory.users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
                    <div class="col-md-2"><select v-model="level.roles" multiple class="form-select form-select-sm" size="3" aria-label="Approver roles"><option v-for="r in directory.roles" :key="r.id" :value="r.id">Role: {{ r.name }}</option></select></div>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm me-2" @click="form.levels.push({ label: `Level ${form.levels.length + 1}`, mode: 'any', users: [], roles: [] })">Add level</button>
                <button type="button" class="btn btn-dark btn-sm" @click="saveWorkflow">Save workflow</button>
            </div>
            <table class="table table-sm">
                <thead><tr><th>Name</th><th>Type</th><th>Range</th><th>Levels</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="workflow in workflows" :key="workflow.id" :class="{ 'text-muted': ! workflow.is_active }">
                        <td>{{ workflow.name }}</td><td>{{ workflow.document_type }}</td><td>{{ workflow.min_amount ?? 0 }} - {{ workflow.max_amount ?? 'no limit' }}</td>
                        <td><div v-for="level in workflow.levels" :key="level.level_no" class="small">{{ level.level_no }}. {{ level.label }} ({{ level.mode }}): {{ level.approvers.map((a) => (a.type === 'role' ? directory.roles : directory.users).find((x) => x.id === a.id)?.name ?? `${a.type}:${a.id}`).join(', ') }}</div></td>
                        <td class="text-end"><button v-if="can('/approvalcenter/manage')" type="button" class="btn btn-outline-danger btn-sm" @click="removeWorkflow(workflow)">Remove</button></td>
                    </tr>
                    <tr v-if="! workflows.length"><td colspan="5" class="text-center text-muted">No workflows: every document keeps its single approval step.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="tab === 'delegation'">
            <div class="border rounded p-3 mb-3">
                <h6>Cover me while I am away</h6>
                <div class="row g-2">
                    <div class="col-md-2"><input v-model.number="delegate.to_user_id" type="number" class="form-control form-control-sm" placeholder="Delegate user id" aria-label="Delegate user id" /></div>
                    <div class="col-md-2"><input v-model="delegate.starts_on" type="date" class="form-control form-control-sm" aria-label="From" /></div>
                    <div class="col-md-2"><input v-model="delegate.ends_on" type="date" class="form-control form-control-sm" aria-label="To" /></div>
                    <div class="col-md-3"><input v-model="delegate.reason" class="form-control form-control-sm" placeholder="Reason" aria-label="Reason" /></div>
                    <div class="col-md-1"><button type="button" class="btn btn-dark btn-sm" @click="saveDelegation">Save</button></div>
                </div>
            </div>
            <table class="table table-sm">
                <thead><tr><th>From user</th><th>Covered by</th><th>Period</th><th>Active</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in delegations" :key="row.id"><td>{{ row.from_user_id }}</td><td>{{ row.to_user_id }}</td><td>{{ row.starts_on }} to {{ row.ends_on }}</td><td>{{ row.is_active ? 'yes' : 'ended' }}</td>
                        <td class="text-end"><button v-if="row.is_active" type="button" class="btn btn-outline-danger btn-sm" @click="endDelegation(row)">End</button></td></tr>
                    <tr v-if="! delegations.length"><td colspan="5" class="text-center text-muted">No delegations.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="tab === 'discounts'">
            <div v-if="can('/approvalcenter/manage')" class="border rounded p-3 mb-3">
                <label class="small"><input v-model="discountApproval" type="checkbox" class="me-1" />New discount codes need approval before they can be used</label>
                <button type="button" class="btn btn-dark btn-sm ms-3" @click="saveDiscountSetting">Save</button>
            </div>
            <table class="table table-sm">
                <thead><tr><th>Name</th><th>Code</th><th class="text-end">Value</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in pendingDiscounts" :key="row.id"><td>{{ row.name }}</td><td>{{ row.code }}</td><td class="text-end">{{ row.value }}</td>
                        <td class="text-end"><template v-if="can('/approvalcenter/manage')"><button type="button" class="btn btn-dark btn-sm me-1" @click="decideDiscount(row.id, 'approve')">Approve</button><button type="button" class="btn btn-outline-danger btn-sm" @click="decideDiscount(row.id, 'reject')">Reject</button></template></td></tr>
                    <tr v-if="! pendingDiscounts.length"><td colspan="4" class="text-center text-muted">No discount code is waiting.</td></tr>
                </tbody>
            </table>
        </div>
    </div></div></div>
</template>
