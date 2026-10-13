<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref } from 'vue';
    import LookupSelect from '@/components/LookupSelect.vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Milestones and Tasks',
            subtitle: 'The plan of a project: milestones with what they bill, tasks with owners and hours, progress against budget',
            breadcrumbs: [{ title: 'Projects', href: 'NULL' }, { title: 'Milestones and Tasks', href: 'NULL' }],
        },
    });

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const projects = ref<{ id: number; code: string; name: string }[]>([]);
    const projectId = ref<number | ''>('');
    const data = ref<Record<string, any> | null>(null);
    const milestone = reactive({ name: '', due_date: '', amount: 0 });
    const task = reactive({ title: '', milestone_id: '' as number | '', assignee_id: '' as number | '', due_date: '', estimated_hours: 0 });
    const canManage = computed(() => can('/project/work/manage'));

    async function loadProjects() {
        projects.value = (await run(() => window.axios.get('/api/projects', { params: scope() })))?.data?.data ?? [];
    }

    async function load() {
        data.value = projectId.value === '' ? null : ((await run(() => window.axios.get(`/api/project-work/${projectId.value}`, { params: scope() })))?.data?.data ?? null);
    }

    async function send(method: 'post' | 'put' | 'delete', path: string, body: Record<string, unknown> = {}, ok?: string) {
        const url = `/api/project-work/${projectId.value}${path}`;
        const result = await run(() => (method === 'delete' ? window.axios.delete(url, { params: scope() }) : window.axios[method](url, { ...scope(), ...body })), ok);

        if (result) {
            await load();
        }
    }

    const clean = (body: Record<string, unknown>) => Object.fromEntries(Object.entries(body).filter(([, v]) => v !== ''));

    onMounted(loadProjects);
</script>

<template>
    <Head title="Milestones and Tasks" />

    <div class="container-fluid py-3">
        <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm mb-3" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="loadProjects" />
        <select v-model="projectId" class="form-select form-select-sm mb-3" style="width: 22rem" aria-label="Project" @change="load">
            <option value="">Choose a project</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} - {{ p.name }}</option>
        </select>

        <div v-if="data">
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Task progress</div><strong>{{ data.progress.percent }} %</strong> <span class="small">({{ data.progress.done }} of {{ data.progress.tasks }})</span></div></div>
                <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Hours spent / estimated</div><strong>{{ data.progress.spent_hours }}</strong> / {{ data.progress.estimated_hours }}</div></div>
                <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Milestones planned / billed</div><strong>{{ money(data.billing.planned) }}</strong> / {{ money(data.billing.billed) }}</div></div>
                <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Budget revenue / cost</div>{{ money(data.project.budget_revenue) }} / {{ money(data.project.budget_cost) }}</div></div>
            </div>
            <div v-if="data.financials" class="small mb-3">Revenue so far {{ money(data.financials.revenue) }}, direct cost {{ money(data.financials.cost) }}, margin {{ money(data.financials.margin) }} (from what is tagged to this project).</div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <h6>Milestones</h6>
                    <div v-if="canManage" class="d-flex gap-2 mb-2">
                        <input v-model="milestone.name" class="form-control form-control-sm" placeholder="Milestone" aria-label="Milestone name" />
                        <input v-model="milestone.due_date" type="date" class="form-control form-control-sm" style="width: 9rem" aria-label="Due date" />
                        <input v-model.number="milestone.amount" type="number" class="form-control form-control-sm" style="width: 8rem" placeholder="Amount" aria-label="Milestone amount" />
                        <button type="button" class="btn btn-dark btn-sm" @click="send('post', '/milestones', clean({ ...milestone }), 'Milestone saved')">Add</button>
                    </div>
                    <table class="table table-sm">
                        <thead><tr><th>Milestone</th><th>Due</th><th class="text-end">Amount</th><th>Tasks</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="m in data.milestones" :key="m.id" :class="{ 'table-warning': m.overdue }">
                                <td>{{ m.name }}</td><td>{{ m.due_date ? String(m.due_date).slice(0, 10) : '-' }}</td><td class="text-end">{{ money(m.amount) }}</td><td>{{ m.tasks_done }}/{{ m.tasks }}</td><td class="text-capitalize">{{ m.status }}</td>
                                <td class="text-end">
                                    <button v-if="m.status === 'pending'" type="button" class="btn btn-outline-dark btn-sm" @click="send('post', `/milestones/${m.id}/status`, { status: 'done' }, 'Milestone done')">Done</button>
                                    <button v-if="m.status === 'done'" type="button" class="btn btn-outline-dark btn-sm" @click="send('post', `/milestones/${m.id}/status`, { status: 'billed' }, 'Milestone billed')">Billed</button>
                                    <button v-if="canManage" type="button" class="btn btn-outline-danger btn-sm ms-1" @click="send('delete', `/milestones/${m.id}`, {}, 'Deleted')">x</button>
                                </td>
                            </tr>
                            <tr v-if="! data.milestones.length"><td colspan="6" class="text-center text-muted">No milestones yet.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="col-lg-6">
                    <h6>Tasks</h6>
                    <div v-if="canManage" class="row g-2 mb-2">
                        <div class="col-5"><input v-model="task.title" class="form-control form-control-sm" placeholder="Task" aria-label="Task title" /></div>
                        <div class="col-4"><select v-model="task.milestone_id" class="form-select form-select-sm" aria-label="Task milestone"><option value="">No milestone</option><option v-for="m in data.milestones" :key="m.id" :value="m.id">{{ m.name }}</option></select></div>
                        <div class="col-3"><input v-model.number="task.estimated_hours" type="number" class="form-control form-control-sm" placeholder="Hours" aria-label="Estimated hours" /></div>
                        <div class="col-3"><LookupSelect v-model="task.assignee_id" endpoint="/api/fetchusers" :params="scope()" placeholder="Assignee" aria-label="Assignee" /></div>
                        <div class="col-3"><input v-model="task.due_date" type="date" class="form-control form-control-sm" aria-label="Task due date" /></div>
                        <div class="col-2"><button type="button" class="btn btn-dark btn-sm" @click="send('post', '/tasks', clean({ ...task }), 'Task saved')">Add</button></div>
                    </div>
                    <table class="table table-sm">
                        <thead><tr><th>Task</th><th>Who</th><th>Hours</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="t in data.tasks" :key="t.id" :class="{ 'table-warning': t.overdue }">
                                <td>{{ t.title }}</td><td>{{ t.assignee || '-' }}</td><td>{{ t.spent_hours }} / {{ t.estimated_hours }}</td><td class="text-capitalize">{{ t.status }}</td>
                                <td class="text-end">
                                    <button v-if="t.status !== 'doing' && t.status !== 'done'" type="button" class="btn btn-outline-dark btn-sm" @click="send('post', `/tasks/${t.id}/status`, { status: 'doing' })">Start</button>
                                    <button v-if="t.status !== 'done'" type="button" class="btn btn-outline-dark btn-sm ms-1" @click="send('post', `/tasks/${t.id}/status`, { status: 'done' }, 'Task done')">Done</button>
                                    <button v-if="t.status !== 'done'" type="button" class="btn btn-outline-dark btn-sm ms-1" @click="send('post', `/tasks/${t.id}/status`, { add_hours: 1 })">+1 h</button>
                                    <button v-if="canManage" type="button" class="btn btn-outline-danger btn-sm ms-1" @click="send('delete', `/tasks/${t.id}`, {}, 'Deleted')">x</button>
                                </td>
                            </tr>
                            <tr v-if="! data.tasks.length"><td colspan="5" class="text-center text-muted">No tasks yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div v-else class="text-muted">Choose a project to see its plan.</div>
    </div>
</template>
