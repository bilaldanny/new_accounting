<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
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
            <ClientTable empty-text="Nothing is waiting for you." dusk="inbox" :columns="[{ key: 'c0', label: 'Type' }, { key: 'c1', label: 'Document' }, { key: 'c2', label: 'Amount' }, { key: 'c3', label: 'Level' }, { key: 'c4', label: 'Workflow' }, { key: 'c5', label: '' }]" :rows="(inbox).map((item) => ({ __s: { item }, c0: (item.label), c1: (item.number), c2: (money(item.amount)), c3: `${item.level} of ${item.of}: ${item.level_label}`, c4: (item.workflow), c5: '' }))" export-name="type">
<template #cell-c5="{ row: __m }"><template v-for="{ item } in [__m.__s]" :key="0"><button type="button" class="btn btn-dark btn-sm me-1" @click="act(item, 'approve')">Approve</button><button type="button" class="btn btn-outline-danger btn-sm" @click="act(item, 'reject')">Reject</button></template></template>
</ClientTable>
        </div>

        <div v-if="tab === 'outbox'">
            <ClientTable empty-text="None of your documents is in a workflow."  :columns="[{ key: 'c0', label: 'Type' }, { key: 'c1', label: 'Document' }, { key: 'c2', label: 'Amount' }, { key: 'c3', label: 'Progress' }, { key: 'c4', label: 'Status' }]" :rows="(outbox).map((row) => ({ __s: { row }, c0: (row.label), c1: (row.document_no), c2: (money(row.amount)), c3: `level ${row.current_level} of ${row.levels}`, c4: (row.status) }))" export-name="type">
</ClientTable>
        </div>

        <div v-if="tab === 'history'">
            <ClientTable empty-text="No approvals recorded yet."  :columns="[{ key: 'c0', label: 'When' }, { key: 'c1', label: 'Document' }, { key: 'c2', label: 'Level' }, { key: 'c3', label: 'Action' }, { key: 'c4', label: 'By' }, { key: 'c5', label: 'Comment' }]" :rows="(history).map((row) => ({ __s: { row }, c0: (row.created_at), c1: `${row.document_type} ${row.document_no}`, c2: (row.level_no), c3: (row.action), c4: '', c5: (row.comment) }))" export-name="when">
<template #cell-c4="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">{{ row.user_email }}<span v-if="row.on_behalf_of_email" class="text-muted"> for {{ row.on_behalf_of_email }}</span></template></template>
</ClientTable>
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
            <ClientTable empty-text="No workflows: every document keeps its single approval step."  :columns="[{ key: 'c0', label: 'Name' }, { key: 'c1', label: 'Type' }, { key: 'c2', label: 'Range' }, { key: 'c3', label: 'Levels' }, { key: 'c4', label: '' }]" :rows="(workflows).map((workflow) => ({ __s: { workflow }, c0: (workflow.name), c1: (workflow.document_type), c2: `${workflow.min_amount ?? 0} - ${workflow.max_amount ?? 'no limit'}`, c3: '', c4: '' }))" export-name="name">
<template #cell-c3="{ row: __m }"><template v-for="{ workflow } in [__m.__s]" :key="0"><div v-for="level in workflow.levels" :key="level.level_no" class="small">{{ level.level_no }}. {{ level.label }} ({{ level.mode }}): {{ level.approvers.map((a) => (a.type === 'role' ? directory.roles : directory.users).find((x) => x.id === a.id)?.name ?? `${a.type}:${a.id}`).join(', ') }}</div></template></template>
<template #cell-c4="{ row: __m }"><template v-for="{ workflow } in [__m.__s]" :key="0"><button v-if="can('/approvalcenter/manage')" type="button" class="btn btn-outline-danger btn-sm" @click="removeWorkflow(workflow)">Remove</button></template></template>
</ClientTable>
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
            <ClientTable empty-text="No delegations."  :columns="[{ key: 'c0', label: 'From user' }, { key: 'c1', label: 'Covered by' }, { key: 'c2', label: 'Period' }, { key: 'c3', label: 'Active' }, { key: 'c4', label: '' }]" :rows="(delegations).map((row) => ({ __s: { row }, c0: (row.from_user_id), c1: (row.to_user_id), c2: `${row.starts_on} to ${row.ends_on}`, c3: (row.is_active ? 'yes' : 'ended'), c4: '' }))" export-name="from-user">
<template #cell-c4="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><button v-if="row.is_active" type="button" class="btn btn-outline-danger btn-sm" @click="endDelegation(row)">End</button></template></template>
</ClientTable>
        </div>

        <div v-if="tab === 'discounts'">
            <div v-if="can('/approvalcenter/manage')" class="border rounded p-3 mb-3">
                <label class="small"><input v-model="discountApproval" type="checkbox" class="me-1" />New discount codes need approval before they can be used</label>
                <button type="button" class="btn btn-dark btn-sm ms-3" @click="saveDiscountSetting">Save</button>
            </div>
            <ClientTable empty-text="No discount code is waiting."  :columns="[{ key: 'c0', label: 'Name' }, { key: 'c1', label: 'Code' }, { key: 'c2', label: 'Value' }, { key: 'c3', label: '' }]" :rows="(pendingDiscounts).map((row) => ({ __s: { row }, c0: (row.name), c1: (row.code), c2: (row.value), c3: '' }))" export-name="name">
<template #cell-c3="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><template v-if="can('/approvalcenter/manage')"><button type="button" class="btn btn-dark btn-sm me-1" @click="decideDiscount(row.id, 'approve')">Approve</button><button type="button" class="btn btn-outline-danger btn-sm" @click="decideDiscount(row.id, 'reject')">Reject</button></template></template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
