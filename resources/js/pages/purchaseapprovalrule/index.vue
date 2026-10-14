<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Purchase Approval Rules',
            subtitle: 'Who must approve a purchase, by its amount',
            breadcrumbs: [{ title: 'Purchase Approval Rules', href: 'NULL' }],
        },
    });

    type Level = { label: string; role_id: number | null };
    type Rule = { id: number; name: string; min_amount: number; max_amount: number | null; levels: Level[]; forbid_creator: boolean; priority: number; is_active: boolean };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const rows = ref<Rule[]>([]);
    const editing = ref<number | null>(null);
    const blank = () => ({ name: '', min_amount: 0, max_amount: '' as number | '', levels: [{ label: 'Level 1', role_id: '' as number | '' }], forbid_creator: true, priority: 0, is_active: true });
    const form = ref(blank());

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/purchase-approval-rules', { params: scope() })))?.data?.data ?? [];
    }

    function edit(rule: Rule) {
        editing.value = rule.id;
        form.value = {
            name: rule.name, min_amount: rule.min_amount, max_amount: rule.max_amount ?? '', forbid_creator: rule.forbid_creator, priority: rule.priority, is_active: rule.is_active,
            levels: rule.levels.map((level) => ({ label: level.label, role_id: level.role_id ?? '' })),
        };
    }

    async function save() {
        const body = {
            ...scope(), ...form.value, max_amount: form.value.max_amount === '' ? null : form.value.max_amount,
            levels: form.value.levels.map((level) => ({ label: level.label, role_id: level.role_id === '' ? null : level.role_id })),
        };
        const done = await run(
            () => (editing.value === null ? window.axios.post('/api/purchase-approval-rules', body) : window.axios.put(`/api/purchase-approval-rules/${editing.value}`, body)),
            'Saved',
        );

        if (done) {
            editing.value = null;
            form.value = blank();
            await load();
        }
    }

    async function remove(rule: Rule) {
        if (window.confirm(`Delete ${rule.name}?`) && await run(() => window.axios.delete(`/api/purchase-approval-rules/${rule.id}`), 'Deleted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Purchase Approval Rules" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div v-if="can('/purchaseapprovalrule/add') || editing !== null" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label small" for="par-name">Name</label><input id="par-name" v-model="form.name" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="par-min">From amount</label><input id="par-min" v-model.number="form.min_amount" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="par-max">Up to (empty: no limit)</label><input id="par-max" v-model.number="form.max_amount" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-1"><label class="form-label small" for="par-priority">Priority</label><input id="par-priority" v-model.number="form.priority" type="number" min="0" class="form-control form-control-sm" /></div>
                <div class="col-md-3 d-flex align-items-end gap-3">
                    <label class="small"><input v-model="form.forbid_creator" type="checkbox" class="me-1" />Creator cannot approve</label>
                    <label class="small"><input v-model="form.is_active" type="checkbox" class="me-1" />Active</label>
                </div>
            </div>
            <ClientTable  :columns="[{ key: 'c0', label: 'Level' }, { key: 'c1', label: 'Name' }, { key: 'c2', label: 'Role id (empty: any approver)' }, { key: 'c3', label: '' }]" :rows="(form.levels).map((level, index) => ({ __s: { level, index }, c0: (index + 1), c1: '', c2: '', c3: '' }))" export-name="level">
<template #cell-c1="{ row: __m }"><template v-for="{ level, index } in [__m.__s]" :key="0"><input v-model="level.label" class="form-control form-control-sm" aria-label="Level name" /></template></template>
<template #cell-c2="{ row: __m }"><template v-for="{ level, index } in [__m.__s]" :key="0"><input v-model.number="level.role_id" type="number" class="form-control form-control-sm" aria-label="Role id" /></template></template>
<template #cell-c3="{ row: __m }"><template v-for="{ level, index } in [__m.__s]" :key="0"><button v-if="form.levels.length > 1" type="button" class="btn btn-link btn-sm text-danger" @click="form.levels.splice(index, 1)">Remove</button></template></template>
</ClientTable>
            <button v-if="form.levels.length < 5" type="button" class="btn btn-outline-dark btn-sm me-2" @click="form.levels.push({ label: `Level ${form.levels.length + 1}`, role_id: '' })">Add level</button>
            <button type="button" class="btn btn-dark btn-sm" @click="save">{{ editing === null ? 'Add rule' : 'Save changes' }}</button>
            <button v-if="editing !== null" type="button" class="btn btn-link btn-sm" @click="editing = null; form = blank()">Cancel</button>
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="No rules: every purchase is approved in one step."  :columns="[{ key: 'c0', label: 'Rule' }, { key: 'c1', label: 'From' }, { key: 'c2', label: 'Up to' }, { key: 'c3', label: 'Levels' }, { key: 'c4', label: 'Active' }, { key: 'c5', label: '' }]" :rows="(rows).map((rule) => ({ __s: { rule }, c0: (rule.name), c1: (money(rule.min_amount)), c2: (rule.max_amount === null ? 'no limit' : money(rule.max_amount)), c3: (rule.levels.map((level) => level.label).join(' > ')), c4: (rule.is_active ? 'Yes' : 'No'), c5: '' }))" export-name="rule">
<template #cell-c5="{ row: __m }"><template v-for="{ rule } in [__m.__s]" :key="0">
                            <button v-if="can('/purchaseapprovalrule/:id/edit')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="edit(rule)">Edit</button>
                            <button v-if="can('/purchaseapprovalrule/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(rule)">Delete</button>
                        </template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
