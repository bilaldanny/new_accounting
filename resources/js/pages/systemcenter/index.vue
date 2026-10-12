<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, reactive, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'System Center',
            subtitle: 'Logs, backups, push keys, demo companies, franchise groups, help articles and retention (platform administrator)',
            breadcrumbs: [{ title: 'Platform', href: 'NULL' }, { title: 'System Center', href: 'NULL' }],
        },
    });

    type LogEntry = { time: string; level: string; message: string; trace: string };
    type Group = { id: number; name: string; owner_user_id: number; company_ids: number[] };
    type Article = { id: number; title: string; category: string; body: string; is_published: boolean };
    type Backup = { id: number; file_name: string; status: string; size_bytes: number | null; file_exists: boolean };

    const { run, Notify } = useCloseout();
    const tab = ref<'logs' | 'backups' | 'push' | 'demo' | 'groups' | 'help' | 'retention'>('logs');

    const logs = ref<LogEntry[]>([]);
    const logFile = ref<string | null>(null);
    const level = ref('');
    const search = ref('');
    const backups = ref<Backup[]>([]);
    const disks = ref<string[]>([]);
    const disk = ref('');
    const restoreText = reactive<Record<number, string>>({});
    const push = ref<{ public_key: string | null; subject: string | null; subscriptions: number } | null>(null);
    const subject = ref('');
    const demos = ref<{ id: number; code: string; name: string; is_active: boolean }[]>([]);
    const demoName = ref('');
    const created = ref<{ code: string; email: string; password: string } | null>(null);
    const groups = ref<Group[]>([]);
    const group = reactive({ id: 0, name: '', owner_user_id: 0, company_ids: '' });
    const articles = ref<Article[]>([]);
    const article = reactive({ id: 0, title: '', category: 'general', body: '', is_published: true });
    const retention = ref<{ entity: string; keep_days: number; archive: boolean }[]>([]);
    const rule = reactive({ entity: 'audit_logs', keep_days: 365, archive: true });
    const entities = ref<{ entity: string; label: string }[]>([]);

    async function loadLogs() {
        const response = await run(() => window.axios.get('/api/system/logs', { params: { level: level.value || undefined, search: search.value || undefined } }));
        logs.value = response?.data?.data?.entries ?? [];
        logFile.value = response?.data?.data?.file ?? null;
    }

    async function loadBackups() {
        const [list, remote] = await Promise.all([run(() => window.axios.get('/api/backups')), run(() => window.axios.get('/api/system/backup-remote'))]);
        backups.value = list?.data?.data?.data ?? [];
        disks.value = remote?.data?.data?.disks ?? [];
        disk.value = remote?.data?.data?.disk ?? '';
    }

    async function loadAll() {
        await loadLogs();
        await loadBackups();
        push.value = (await run(() => window.axios.get('/api/system/push')))?.data?.data ?? null;
        subject.value = push.value?.subject ?? '';
        demos.value = (await run(() => window.axios.get('/api/system/demo-companies')))?.data?.data ?? [];
        groups.value = (await run(() => window.axios.get('/api/system/franchise-groups')))?.data?.data ?? [];
        articles.value = (await run(() => window.axios.get('/api/system/help-articles')))?.data?.data ?? [];
        const r = await run(() => window.axios.get('/api/system/retention'));
        retention.value = r?.data?.data ?? [];
        entities.value = r?.data?.entities ?? [];
    }

    async function chooseDisk() {
        await run(() => window.axios.put('/api/system/backup-remote', { disk: disk.value || null }), 'Remote backup disk saved');
    }

    async function copy(backup: Backup) {
        await run(() => window.axios.post(`/api/system/backups/${backup.id}/copy`), 'Copied to the remote disk');
    }

    async function restore(backup: Backup) {
        await run(() => window.axios.post(`/api/system/backups/${backup.id}/restore`, { confirm: restoreText[backup.id] ?? '' }), 'Backup restored');
    }

    async function keys() {
        await run(() => window.axios.post('/api/system/push/keys'), 'Done');
        await loadAll();
    }

    async function saveSubject() {
        await run(() => window.axios.put('/api/system/push/subject', { subject: subject.value }), 'Saved');
    }

    async function createDemo() {
        const response = await run(() => window.axios.post('/api/system/demo-companies', { name: demoName.value || undefined }));
        created.value = response?.data?.data ?? null;
        await loadAll();
    }

    async function removeDemo(id: number) {
        await run(() => window.axios.delete(`/api/system/demo-companies/${id}`), 'Demo company removed');
        await loadAll();
    }

    async function saveGroup() {
        const body = { name: group.name, owner_user_id: group.owner_user_id, company_ids: group.company_ids.split(/[,\s]+/).filter(Boolean).map(Number) };
        const response = group.id ? await run(() => window.axios.put(`/api/system/franchise-groups/${group.id}`, body), 'Group saved') : await run(() => window.axios.post('/api/system/franchise-groups', body), 'Group saved');

        if (response) {
            Object.assign(group, { id: 0, name: '', owner_user_id: 0, company_ids: '' });
            await loadAll();
        }
    }

    async function removeGroup(id: number) {
        await run(() => window.axios.delete(`/api/system/franchise-groups/${id}`), 'Group removed');
        await loadAll();
    }

    async function saveArticle() {
        const response = article.id ? await run(() => window.axios.put(`/api/system/help-articles/${article.id}`, article), 'Article saved') : await run(() => window.axios.post('/api/system/help-articles', article), 'Article saved');

        if (response) {
            Object.assign(article, { id: 0, title: '', body: '' });
            await loadAll();
        }
    }

    async function removeArticle(id: number) {
        await run(() => window.axios.delete(`/api/system/help-articles/${id}`), 'Article removed');
        await loadAll();
    }

    async function saveRule() {
        await run(() => window.axios.put('/api/system/retention', rule), 'Platform rule saved');
        await loadAll();
    }

    onMounted(() => {
        void loadAll().catch(() => Notify('Could not load the System Center.', 'alert'));
    });
</script>

<template>
    <Head title="System Center" />

    <div class="container-fluid py-3">
        <ul class="nav nav-tabs mb-3">
            <li v-for="item in [['logs', 'Logs'], ['backups', 'Backups'], ['push', 'Push keys'], ['demo', 'Demo companies'], ['groups', 'Franchise groups'], ['help', 'Help articles'], ['retention', 'Retention']]" :key="item[0]" class="nav-item">
                <button type="button" class="nav-link" :class="{ active: tab === item[0] }" @click="tab = item[0] as typeof tab">{{ item[1] }}</button>
            </li>
        </ul>

        <div v-if="tab === 'logs'">
            <form class="d-flex gap-2 mb-3" @submit.prevent="loadLogs">
                <select v-model="level" class="form-select form-select-sm w-auto" aria-label="Level"><option value="">All levels</option><option v-for="l in ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']" :key="l" :value="l">{{ l }}</option></select>
                <input v-model="search" type="search" class="form-control form-control-sm w-auto" placeholder="Search text" aria-label="Search the log" />
                <button type="submit" class="btn btn-dark btn-sm">Show</button>
                <span class="small text-muted align-self-center">{{ logFile ?? 'no log file' }}</span>
            </form>
            <div v-for="(entry, index) in logs" :key="index" class="border-bottom py-1 small"><strong :class="['error', 'critical', 'alert', 'emergency'].includes(entry.level) ? 'text-danger' : ''">{{ entry.level }}</strong> {{ entry.time }} - {{ entry.message }}<pre v-if="entry.trace" class="mb-0 text-muted">{{ entry.trace }}</pre></div>
            <div v-if="!logs.length" class="text-muted small">Nothing in the log for this filter.</div>
        </div>

        <div v-if="tab === 'backups'">
            <div class="d-flex gap-2 mb-3 align-items-center">
                <label class="small" for="sc-disk">Remote disk</label>
                <select id="sc-disk" v-model="disk" class="form-select form-select-sm w-auto"><option value="">None</option><option v-for="d in disks" :key="d" :value="d">{{ d }}</option></select>
                <button type="button" class="btn btn-outline-dark btn-sm" @click="chooseDisk">Save</button>
                <span v-if="!disks.length" class="small text-muted">No remote disk is configured in config/filesystems.php (S3 needs its adapter package).</span>
            </div>
            <div v-for="backup in backups" :key="backup.id" class="border-bottom py-2">
                <div class="d-flex gap-2 align-items-center"><span class="flex-grow-1">{{ backup.file_name }} <span class="badge text-bg-secondary">{{ backup.status }}</span></span>
                    <button v-if="backup.file_exists && disk" type="button" class="btn btn-outline-dark btn-sm py-0" @click="copy(backup)">Copy to remote</button></div>
                <div v-if="backup.file_exists" class="d-flex gap-2 mt-1">
                    <input v-model="restoreText[backup.id]" type="text" class="form-control form-control-sm" :placeholder="`Type RESTORE ${backup.file_name} to restore`" :aria-label="`Restore confirmation for ${backup.file_name}`" />
                    <button type="button" class="btn btn-outline-danger btn-sm" @click="restore(backup)">Restore</button>
                </div>
            </div>
            <div class="small text-muted mt-2">A restore overwrites all companies' live data. A backup of the current data is made first and the restore stops if that fails.</div>
        </div>

        <div v-if="tab === 'push' && push" style="max-width: 36rem">
            <p class="small">Public key: <code>{{ push.public_key ?? 'not generated' }}</code><br>Subscribed browsers: {{ push.subscriptions }}</p>
            <button type="button" class="btn btn-dark btn-sm mb-3" @click="keys">{{ push.public_key ? 'Keys exist' : 'Generate keys' }}</button>
            <label class="form-label small" for="sc-subject">Contact for push services (mailto: or https:)</label>
            <div class="d-flex gap-2"><input id="sc-subject" v-model="subject" type="text" class="form-control form-control-sm" placeholder="mailto:admin@example.com" /><button type="button" class="btn btn-outline-dark btn-sm" @click="saveSubject">Save</button></div>
        </div>

        <div v-if="tab === 'demo'" style="max-width: 40rem">
            <div class="d-flex gap-2 mb-3"><input v-model="demoName" type="text" class="form-control form-control-sm" placeholder="Demo company name" aria-label="Demo company name" /><button type="button" class="btn btn-dark btn-sm" @click="createDemo">Create</button></div>
            <div v-if="created" class="alert alert-warning small">Sign in as <strong>{{ created.email }}</strong> with password <strong>{{ created.password }}</strong> - it is shown only now.</div>
            <div v-for="demo in demos" :key="demo.id" class="d-flex gap-2 align-items-center border-bottom py-1"><span class="flex-grow-1">{{ demo.name }} <span class="text-muted small">{{ demo.code }}</span></span><button type="button" class="btn btn-outline-danger btn-sm py-0" @click="removeDemo(demo.id)">Remove</button></div>
            <div v-if="!demos.length" class="text-muted small">No demo companies.</div>
        </div>

        <div v-if="tab === 'groups'" class="row g-3">
            <div class="col-lg-5"><div class="border rounded p-3">
                <h6>Group</h6>
                <label class="form-label small" for="fg-name">Name</label><input id="fg-name" v-model="group.name" type="text" class="form-control form-control-sm mb-2" />
                <label class="form-label small" for="fg-owner">Owner user id</label><input id="fg-owner" v-model.number="group.owner_user_id" type="number" class="form-control form-control-sm mb-2" />
                <label class="form-label small" for="fg-companies">Company ids (comma separated)</label><input id="fg-companies" v-model="group.company_ids" type="text" class="form-control form-control-sm mb-2" />
                <button type="button" class="btn btn-dark btn-sm" :disabled="group.name.trim().length < 2" @click="saveGroup">Save group</button>
            </div></div>
            <div class="col-lg-7"><div class="border rounded p-3">
                <div v-for="g in groups" :key="g.id" class="d-flex gap-2 align-items-center border-bottom py-1"><span class="flex-grow-1">{{ g.name }} <span class="text-muted small">owner {{ g.owner_user_id }}, companies {{ g.company_ids.join(', ') }}</span></span>
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0" @click="Object.assign(group, { id: g.id, name: g.name, owner_user_id: g.owner_user_id, company_ids: g.company_ids.join(', ') })">Edit</button>
                    <button type="button" class="btn btn-outline-danger btn-sm py-0" @click="removeGroup(g.id)">Delete</button></div>
                <div v-if="!groups.length" class="text-muted small">No groups.</div>
            </div></div>
        </div>

        <div v-if="tab === 'help'" class="row g-3">
            <div class="col-lg-6"><div class="border rounded p-3">
                <h6>Article</h6>
                <label class="form-label small" for="ha-title">Title</label><input id="ha-title" v-model="article.title" type="text" class="form-control form-control-sm mb-2" />
                <label class="form-label small" for="ha-category">Topic</label><input id="ha-category" v-model="article.category" type="text" class="form-control form-control-sm mb-2" />
                <label class="form-label small" for="ha-body">Text</label><textarea id="ha-body" v-model="article.body" rows="8" class="form-control form-control-sm mb-2" />
                <label class="form-check small mb-2"><input v-model="article.is_published" type="checkbox" class="form-check-input" /> Published</label>
                <button type="button" class="btn btn-dark btn-sm" :disabled="article.title.trim().length < 3 || article.body.trim().length < 3" @click="saveArticle">Save article</button>
            </div></div>
            <div class="col-lg-6"><div class="border rounded p-3">
                <div v-for="a in articles" :key="a.id" class="d-flex gap-2 align-items-center border-bottom py-1"><span class="flex-grow-1">{{ a.title }} <span class="text-muted small">{{ a.category }} {{ a.is_published ? '' : '(draft)' }}</span></span>
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0" @click="Object.assign(article, a)">Edit</button>
                    <button type="button" class="btn btn-outline-danger btn-sm py-0" @click="removeArticle(a.id)">Delete</button></div>
                <div v-if="!articles.length" class="text-muted small">No articles.</div>
            </div></div>
        </div>

        <div v-if="tab === 'retention'" style="max-width: 40rem">
            <p class="small text-muted">Platform default for every company that has no rule of its own. Business documents, vouchers and stock are never covered.</p>
            <div class="row g-2 mb-2">
                <div class="col-5"><select v-model="rule.entity" class="form-select form-select-sm" aria-label="Log"><option v-for="e in entities" :key="e.entity" :value="e.entity">{{ e.label }}</option></select></div>
                <div class="col-3"><input v-model.number="rule.keep_days" type="number" min="30" class="form-control form-control-sm" aria-label="Keep days" /></div>
                <div class="col-4"><label class="form-check small"><input v-model="rule.archive" type="checkbox" class="form-check-input" /> Archive first</label></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mb-3" @click="saveRule">Save rule</button>
            <div v-for="r in retention" :key="r.entity" class="small border-bottom py-1">{{ r.entity }} - {{ r.keep_days }} days {{ r.archive ? '(archived)' : '' }}</div>
        </div>
    </div>
</template>
