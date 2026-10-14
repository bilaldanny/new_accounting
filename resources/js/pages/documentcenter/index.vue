<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { computed, onMounted, reactive, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Document Center',
            subtitle: 'Print templates, message templates, reminders, delivery and custom fields',
            breadcrumbs: [{ title: 'Settings', href: 'NULL' }, { title: 'Document Center', href: 'NULL' }],
        },
    });

    type Block = { type: string; props: Record<string, unknown> };
    type Template = { id: number; name: string; doc_type: string; language: string; paper: string; branch_id: number | null; is_default: boolean; is_active: boolean; blocks: Block[]; branding: Record<string, string | number> };
    type Message = { id: number; name: string; event: string; channel: string; language: string; subject: string | null; body: string; is_default: boolean; is_active: boolean };
    type Rule = { id: number; name: string; trigger: string; days: number; repeat_every_days: number | null; max_sends: number; channel: string; language: string; message_template_id: number | null; is_active: boolean; last_run_at: string | null };
    type Field = { id: number; entity: string; field_key: string; label: string; type: string; options: string[]; is_required: boolean; show_on_print: boolean; is_active: boolean };
    type Meta = {
        doc_types: string[]; languages: string[]; papers: string[]; fonts: string[]; line_columns: string[]; blocks: Record<string, string[]>; events: string[]; channels: string[];
        placeholders: string[]; codes: { code: string; group: string; label: string }[]; triggers: string[]; entities: string[]; field_types: string[]; defaults: Record<string, Block[]>; default_branding: Record<string, string | number>;
    };

    const { companyId, isSuperadmin, scope, can, run } = useCloseout();
    const canManage = computed(() => can('/documentcenter/manage'));
    const tab = ref<'templates' | 'messages' | 'reminders' | 'delivery' | 'fields'>('templates');

    const meta = ref<Meta | null>(null);
    const templates = ref<Template[]>([]);
    const messages = ref<Message[]>([]);
    const rules = ref<Rule[]>([]);
    const log = ref<{ id: number; sent_on: string; channel: string; recipient: string; status: string; invoice_no: string; error: string | null }[]>([]);
    const fields = ref<Field[]>([]);
    const delivery = reactive({ auto_send_sale: false, channel: 'email', language: 'en' });
    const previewHtml = ref('');

    const blank = (): Template => ({ id: 0, name: '', doc_type: 'invoice', language: 'en', paper: 'a4', branch_id: null, is_default: false, is_active: true, blocks: [], branding: {} });
    const form = reactive<Template>(blank());
    const message = reactive<Message>({ id: 0, name: '', event: 'invoice_created', channel: 'email', language: 'en', subject: '', body: '', is_default: false, is_active: true });
    const rule = reactive({ id: 0, name: '', trigger: 'after_due', days: 1, repeat_every_days: 7 as number | null, max_sends: 3, channel: 'email', language: 'en', message_template_id: null as number | null, is_active: true });
    const field = reactive({ id: 0, entity: 'sale', label: '', type: 'text', options: '', is_required: false, show_on_print: true, is_active: true, sort_order: 0 });
    const optionsText = ref('');

    const addable = computed(() => Object.keys(meta.value?.blocks ?? {}));

    async function load() {
        const m = await run(() => window.axios.get('/api/document-center/meta'));
        meta.value = m?.data?.data ?? null;
        const [t, msg, rem, del, fl] = await Promise.all([
            run(() => window.axios.get('/api/document-templates', { params: scope() })),
            run(() => window.axios.get('/api/message-templates', { params: scope() })),
            run(() => window.axios.get('/api/document-reminders', { params: scope() })),
            run(() => window.axios.get('/api/document-delivery', { params: scope() })),
            run(() => window.axios.get('/api/custom-fields', { params: scope() })),
        ]);
        templates.value = t?.data?.data ?? [];
        messages.value = msg?.data?.data ?? [];
        rules.value = rem?.data?.data ?? [];
        log.value = rem?.data?.log ?? [];
        Object.assign(delivery, del?.data?.data ?? {});
        fields.value = fl?.data?.data ?? [];
    }

    function newTemplate() {
        Object.assign(form, blank(), { blocks: JSON.parse(JSON.stringify(meta.value?.defaults.invoice ?? [])), branding: { ...(meta.value?.default_branding ?? {}) } });
        previewHtml.value = '';
    }

    function editTemplate(item: Template) {
        Object.assign(form, JSON.parse(JSON.stringify(item)));
        previewHtml.value = '';
    }

    function changeType() {
        if (!form.id) {
            form.blocks = JSON.parse(JSON.stringify(meta.value?.defaults[form.doc_type] ?? []));
        }
    }

    function addBlock(type: string) {
        form.blocks.push({ type, props: {} });
    }

    /** Drag and drop: the block being dragged and the one it is over. */
    const dragFrom = ref<number | null>(null);
    const dragOver = ref<number | null>(null);

    function dropBlock(target: number) {
        const from = dragFrom.value;
        dragFrom.value = null;
        dragOver.value = null;

        if (from === null || from === target || from < 0 || target < 0 || from >= form.blocks.length || target >= form.blocks.length) {
            return;
        }

        const [moved] = form.blocks.splice(from, 1);
        form.blocks.splice(target, 0, moved);
    }

    function moveBlock(index: number, by: -1 | 1) {
        const target = index + by;

        if (target < 0 || target >= form.blocks.length) {
            return;
        }

        [form.blocks[index], form.blocks[target]] = [form.blocks[target], form.blocks[index]];
    }

    const copied = ref('');

    async function copyCode(code: string) {
        try {
            await navigator.clipboard.writeText(code);
        } catch {
            // no clipboard permission: the code is shown on the button, it can be typed
        }

        copied.value = code;
        window.setTimeout(() => {
            copied.value = '';
        }, 1500);
    }

    const codeGroups = computed(() => {
        const groups: Record<string, { code: string; label: string }[]> = {};

        for (const item of meta.value?.codes ?? []) {
            (groups[item.group] ??= []).push(item);
        }

        return groups;
    });

    function propKind(name: string): 'bool' | 'text' | 'number' | 'align' | 'columns' | 'labels' | 'content' | 'size' {
        if (name.startsWith('show_') || name === 'bold') {
return 'bool';
}

        if (name === 'align') {
return 'align';
}

        if (name === 'columns') {
return 'columns';
}

        if (name === 'labels') {
return 'labels';
}

        if (name === 'content') {
return 'content';
}

        if (name === 'size') {
            return 'size';
        }

        if (name === 'size_px' || name === 'height_mm') {
return 'number';
}

        return 'text';
    }

    function toggleColumn(block: Block, column: string) {
        const current = (block.props.columns as string[] | undefined) ?? [];
        block.props.columns = current.includes(column) ? current.filter((item) => item !== column) : [...current, column];
    }

    async function preview() {
        const response = await run(() => window.axios.post('/api/document-templates/preview', { ...scope(), doc_type: form.doc_type, language: form.language, paper: form.paper, blocks: form.blocks, branding: form.branding }));
        previewHtml.value = response?.data?.html ?? '';
    }

    async function saveTemplate(makeDefault = false) {
        const body = { ...scope(), name: form.name, doc_type: form.doc_type, language: form.language, paper: form.paper, branch_id: form.branch_id, blocks: form.blocks, branding: form.branding, is_default: makeDefault || form.is_default, is_active: form.is_active };
        const response = form.id
            ? await run(() => window.axios.put(`/api/document-templates/${form.id}`, body), 'Template saved')
            : await run(() => window.axios.post('/api/document-templates', body), 'Template saved');

        if (response) {
            Object.assign(form, blank());
            await load();
        }
    }

    async function setDefault(item: Template) {
        await run(() => window.axios.post(`/api/document-templates/${item.id}/default`, { ...scope() }), 'Default template set');
        await load();
    }

    async function removeTemplate(item: Template) {
        await run(() => window.axios.delete(`/api/document-templates/${item.id}`, { params: scope() }), 'Template deleted');
        await load();
    }

    async function saveMessage() {
        const body = { ...scope(), ...message };
        const response = message.id ? await run(() => window.axios.put(`/api/message-templates/${message.id}`, body), 'Message saved') : await run(() => window.axios.post('/api/message-templates', body), 'Message saved');

        if (response) {
            Object.assign(message, { id: 0, name: '', subject: '', body: '', is_default: false });
            await load();
        }
    }

    async function removeMessage(item: Message) {
        await run(() => window.axios.delete(`/api/message-templates/${item.id}`, { params: scope() }), 'Message deleted');
        await load();
    }

    async function saveRule() {
        const body = { ...scope(), ...rule };
        const response = rule.id ? await run(() => window.axios.put(`/api/document-reminders/${rule.id}`, body), 'Rule saved') : await run(() => window.axios.post('/api/document-reminders', body), 'Rule saved');

        if (response) {
            Object.assign(rule, { id: 0, name: '' });
            await load();
        }
    }

    async function removeRule(item: Rule) {
        await run(() => window.axios.delete(`/api/document-reminders/${item.id}`, { params: scope() }), 'Rule deleted');
        await load();
    }

    async function runRules() {
        const response = await run(() => window.axios.post('/api/document-reminders/run', { ...scope() }));

        if (response) {
            await load();
        }
    }

    async function saveDelivery() {
        await run(() => window.axios.put('/api/document-delivery', { ...scope(), ...delivery }), 'Delivery setting saved');
    }

    async function saveField() {
        const body = { ...scope(), ...field, options: optionsText.value.split(',').map((item) => item.trim()).filter(Boolean) };
        const response = await run(() => window.axios.post('/api/custom-fields', body), 'Field saved');

        if (response) {
            Object.assign(field, { label: '' });
            optionsText.value = '';
            await load();
        }
    }

    async function removeField(item: Field) {
        await run(() => window.axios.delete(`/api/custom-fields/${item.id}`, { params: scope() }), 'Field deleted');
        await load();
    }

    onMounted(load);
</script>

<template>
    <Head title="Document Center" />

    <div class="container-fluid py-3">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <ul class="nav nav-tabs mb-3">
            <li v-for="item in [['templates', 'Print templates'], ['messages', 'Messages'], ['reminders', 'Reminders'], ['delivery', 'Delivery'], ['fields', 'Custom fields']]" :key="item[0]" class="nav-item">
                <button type="button" class="nav-link" :class="{ active: tab === item[0] }" @click="tab = item[0] as typeof tab">{{ item[1] }}</button>
            </li>
        </ul>

        <!-- templates -->
        <div v-if="tab === 'templates' && meta" class="row g-3">
            <div class="col-lg-5">
                <div class="border rounded p-3">
                    <div class="d-flex justify-content-between mb-2"><h6 class="mb-0">{{ form.id ? 'Edit template' : 'Template' }}</h6><button v-if="canManage" type="button" class="btn btn-outline-dark btn-sm" @click="newTemplate">New from built-in design</button></div>
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label small" for="dc-name">Name</label><input id="dc-name" v-model="form.name" type="text" class="form-control form-control-sm" maxlength="120" /></div>
                        <div class="col-4"><label class="form-label small" for="dc-type">Document</label><select id="dc-type" v-model="form.doc_type" class="form-select form-select-sm" :disabled="form.id > 0" @change="changeType"><option v-for="t in meta.doc_types" :key="t" :value="t">{{ t }}</option></select></div>
                        <div class="col-4"><label class="form-label small" for="dc-lang">Language</label><select id="dc-lang" v-model="form.language" class="form-select form-select-sm"><option v-for="l in meta.languages" :key="l" :value="l">{{ l }}</option></select></div>
                        <div class="col-4"><label class="form-label small" for="dc-paper">Paper</label><select id="dc-paper" v-model="form.paper" class="form-select form-select-sm"><option v-for="p in meta.papers" :key="p" :value="p">{{ p }}</option></select></div>
                    </div>

                    <h6 class="mt-3">Blocks</h6>
                    <div
                        v-for="(block, index) in form.blocks" :key="index" class="border rounded p-2 mb-2" :class="{ 'border-primary': dragOver === index && dragFrom !== index, 'opacity-50': dragFrom === index }"
                        :data-block-index="index" @dragover.prevent="dragOver = index" @drop.prevent="dropBlock(index)"
                    >
                        <div class="d-flex gap-1 align-items-center">
                            <span class="text-muted me-1" style="cursor: grab" draggable="true" :aria-label="`Drag block ${index + 1}`" title="Drag to move this block" @dragstart="dragFrom = index" @dragend="dragFrom = null; dragOver = null">::</span>
                            <strong class="flex-grow-1 text-capitalize">{{ block.type.replace('_', ' ') }}</strong>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0" :aria-label="`Move block ${index + 1} up`" @click="moveBlock(index, -1)">up</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0" :aria-label="`Move block ${index + 1} down`" @click="moveBlock(index, 1)">down</button>
                            <button type="button" class="btn btn-outline-danger btn-sm py-0" :aria-label="`Remove block ${index + 1}`" @click="form.blocks.splice(index, 1)">x</button>
                        </div>
                        <div v-for="name in meta.blocks[block.type] ?? []" :key="name" class="mt-1">
                            <template v-if="propKind(name) === 'bool'">
                                <label class="form-check small"><input v-model="block.props[name]" type="checkbox" class="form-check-input" /> {{ name.replace('show_', 'Show ').replace('_', ' ') }}</label>
                            </template>
                            <template v-else-if="propKind(name) === 'align'">
                                <select v-model="block.props[name]" class="form-select form-select-sm" :aria-label="`Align of block ${index + 1}`"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select>
                            </template>
                            <template v-else-if="propKind(name) === 'columns'">
                                <label v-for="column in meta.line_columns" :key="column" class="form-check form-check-inline small"><input type="checkbox" class="form-check-input" :checked="((block.props.columns as string[]) ?? []).includes(column)" @change="toggleColumn(block, column)" /> {{ column }}</label>
                            </template>
                            <template v-else-if="propKind(name) === 'labels'">
                                <input :value="((block.props.labels as string[]) ?? []).join(', ')" type="text" class="form-control form-control-sm" placeholder="Prepared by, Received by" @input="block.props.labels = ($event.target as HTMLInputElement).value.split(',').map((x) => x.trim()).filter(Boolean)" />
                            </template>
                            <template v-else-if="propKind(name) === 'content'">
                                <select v-model="block.props[name]" class="form-select form-select-sm"><option value="link">Share link</option><option value="invoice_no">Document number</option></select>
                            </template>
                            <template v-else-if="propKind(name) === 'size'">
                                <select v-model="block.props[name]" class="form-select form-select-sm" :aria-label="`Text size of block ${index + 1}`"><option value="small">Small</option><option value="normal">Normal</option><option value="large">Large</option><option value="xlarge">Extra large</option></select>
                            </template>
                            <template v-else-if="propKind(name) === 'number'">
                                <input v-model.number="block.props[name]" type="number" class="form-control form-control-sm" :aria-label="name" />
                            </template>
                            <template v-else>
                                <textarea v-model="block.props[name] as string" class="form-control form-control-sm" rows="2" :aria-label="`Text of block ${index + 1}`" />
                            </template>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mb-3">
                        <button v-for="type in addable" :key="type" type="button" class="btn btn-outline-secondary btn-sm py-0" @click="addBlock(type)">+ {{ type.replace('_', ' ') }}</button>
                    </div>

                    <h6>Codes</h6>
                    <p class="small text-muted mb-1">Type these codes into a Text block, the title, the terms or the footer. Each one is replaced with the real value when the document prints (click a code to copy it).</p>
                    <div class="border rounded p-2 mb-3" data-test="document-codes">
                        <div v-for="(items, group) in codeGroups" :key="group" class="mb-1">
                            <strong class="small me-1">{{ group }}:</strong>
                            <button v-for="item in items" :key="item.code" type="button" class="btn btn-outline-secondary btn-sm py-0 me-1 mb-1" :title="item.label" @click="copyCode(item.code)">
                                <code>{{ item.code }}</code><span v-if="copied === item.code" class="ms-1 text-success">copied</span>
                            </button>
                        </div>
                    </div>

                    <h6>Branding</h6>
                    <div class="row g-2">
                        <div class="col-4"><label class="form-label small" for="br-primary">Heading colour</label><input id="br-primary" v-model="form.branding.primary_color" type="color" class="form-control form-control-sm form-control-color" /></div>
                        <div class="col-4"><label class="form-label small" for="br-accent">Table colour</label><input id="br-accent" v-model="form.branding.accent_color" type="color" class="form-control form-control-sm form-control-color" /></div>
                        <div class="col-4"><label class="form-label small" for="br-text">Text colour</label><input id="br-text" v-model="form.branding.text_color" type="color" class="form-control form-control-sm form-control-color" /></div>
                        <div class="col-4"><label class="form-label small" for="br-font">Font</label><select id="br-font" v-model="form.branding.font_family" class="form-select form-select-sm"><option v-for="f in meta.fonts" :key="f" :value="f">{{ f }}</option></select></div>
                        <div class="col-4"><label class="form-label small" for="br-size">Font size (pt)</label><input id="br-size" v-model.number="form.branding.font_size_pt" type="number" min="7" max="16" class="form-control form-control-sm" /></div>
                        <div class="col-4"><label class="form-label small" for="br-logo">Logo height (px)</label><input id="br-logo" v-model.number="form.branding.logo_height_px" type="number" min="20" max="140" class="form-control form-control-sm" /></div>
                        <div class="col-6"><label class="form-label small" for="br-border">Border</label><select id="br-border" v-model="form.branding.border_style" class="form-select form-select-sm"><option value="none">None</option><option value="thin">Thin</option><option value="box">Box</option></select></div>
                        <div class="col-6"><label class="form-label small" for="br-table">Table</label><select id="br-table" v-model="form.branding.table_style" class="form-select form-select-sm"><option value="plain">Plain</option><option value="striped">Striped</option><option value="grid">Grid</option></select></div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button id="dc-preview" type="button" class="btn btn-outline-dark btn-sm" @click="preview">Preview</button>
                        <button v-if="canManage" type="button" class="btn btn-dark btn-sm" :disabled="form.name.trim().length < 2 || !form.blocks.length" @click="saveTemplate(false)">Save</button>
                        <button v-if="canManage" type="button" class="btn btn-outline-dark btn-sm" :disabled="form.name.trim().length < 2 || !form.blocks.length" @click="saveTemplate(true)">Save as default</button>
                    </div>
                </div>

                <div class="border rounded p-3 mt-3">
                    <h6>Saved templates</h6>
                    <div v-for="item in templates" :key="item.id" class="d-flex gap-2 align-items-center mb-1">
                        <span class="flex-grow-1">{{ item.name }} <span class="text-muted small">{{ item.doc_type }} / {{ item.language }} / {{ item.paper }}</span> <span v-if="item.is_default" class="badge text-bg-success">default</span></span>
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0" @click="editTemplate(item)">Edit</button>
                        <button v-if="canManage && !item.is_default" type="button" class="btn btn-outline-dark btn-sm py-0" @click="setDefault(item)">Make default</button>
                        <button v-if="canManage" type="button" class="btn btn-outline-danger btn-sm py-0" @click="removeTemplate(item)">Delete</button>
                    </div>
                    <div v-if="!templates.length" class="text-muted small">No templates yet: documents print with the built-in design.</div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="border rounded p-2">
                    <div v-if="!previewHtml" class="text-muted small p-3">Press Preview to see the document with sample data.</div>
                    <iframe v-else title="Document preview" class="w-100 border-0" style="height: 80vh" sandbox="" :srcdoc="previewHtml" />
                </div>
            </div>
        </div>

        <!-- messages -->
        <div v-if="tab === 'messages' && meta" class="row g-3">
            <div v-if="canManage" class="col-lg-5">
                <div class="border rounded p-3">
                    <h6>Message template</h6>
                    <label class="form-label small" for="mt-name">Name</label><input id="mt-name" v-model="message.name" type="text" class="form-control form-control-sm mb-2" />
                    <div class="row g-2 mb-2">
                        <div class="col-4"><label class="form-label small" for="mt-event">Event</label><select id="mt-event" v-model="message.event" class="form-select form-select-sm"><option v-for="e in meta.events" :key="e" :value="e">{{ e }}</option></select></div>
                        <div class="col-4"><label class="form-label small" for="mt-channel">Channel</label><select id="mt-channel" v-model="message.channel" class="form-select form-select-sm"><option v-for="c in meta.channels" :key="c" :value="c">{{ c }}</option></select></div>
                        <div class="col-4"><label class="form-label small" for="mt-lang">Language</label><select id="mt-lang" v-model="message.language" class="form-select form-select-sm"><option v-for="l in meta.languages" :key="l" :value="l">{{ l }}</option></select></div>
                    </div>
                    <template v-if="message.channel === 'email'"><label class="form-label small" for="mt-subject">Subject</label><input id="mt-subject" v-model="message.subject" type="text" class="form-control form-control-sm mb-2" /></template>
                    <label class="form-label small" for="mt-body">Text</label><textarea id="mt-body" v-model="message.body" rows="6" class="form-control form-control-sm" />
                    <div class="small text-muted mt-1">Placeholders: <code v-for="p in meta.placeholders" :key="p" class="me-1">{{ '{' + p + '}' }}</code></div>
                    <label class="form-check small mt-2"><input v-model="message.is_default" type="checkbox" class="form-check-input" /> Default for this event and channel</label>
                    <button type="button" class="btn btn-dark btn-sm mt-2" :disabled="message.name.trim().length < 2 || message.body.trim().length < 2" @click="saveMessage">Save</button>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="border rounded p-3">
                    <h6>Templates</h6>
                    <div v-for="item in messages" :key="item.id" class="border-bottom py-2">
                        <div class="d-flex gap-2 align-items-center"><strong class="flex-grow-1">{{ item.name }}</strong><span class="badge text-bg-secondary">{{ item.event }} / {{ item.channel }} / {{ item.language }}</span><span v-if="item.is_default" class="badge text-bg-success">default</span>
                            <button v-if="canManage" type="button" class="btn btn-outline-secondary btn-sm py-0" @click="Object.assign(message, item)">Edit</button>
                            <button v-if="canManage" type="button" class="btn btn-outline-danger btn-sm py-0" @click="removeMessage(item)">Delete</button></div>
                        <div class="small text-muted" style="white-space: pre-line">{{ item.body }}</div>
                    </div>
                    <div v-if="!messages.length" class="text-muted small">No message templates yet: the built-in wording is used.</div>
                </div>
            </div>
        </div>

        <!-- reminders -->
        <div v-if="tab === 'reminders' && meta" class="row g-3">
            <div v-if="canManage" class="col-lg-5">
                <div class="border rounded p-3">
                    <h6>Reminder rule</h6>
                    <label class="form-label small" for="rr-name">Name</label><input id="rr-name" v-model="rule.name" type="text" class="form-control form-control-sm mb-2" />
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small" for="rr-trigger">When</label><select id="rr-trigger" v-model="rule.trigger" class="form-select form-select-sm"><option value="before_due">Before the due date</option><option value="on_due">On the due date</option><option value="after_due">After the due date</option></select></div>
                        <div v-if="rule.trigger !== 'on_due'" class="col-6"><label class="form-label small" for="rr-days">Days</label><input id="rr-days" v-model.number="rule.days" type="number" min="0" max="365" class="form-control form-control-sm" /></div>
                        <div v-if="rule.trigger === 'after_due'" class="col-6"><label class="form-label small" for="rr-repeat">Repeat every (days)</label><input id="rr-repeat" v-model.number="rule.repeat_every_days" type="number" min="1" class="form-control form-control-sm" /></div>
                        <div v-if="rule.trigger === 'after_due'" class="col-6"><label class="form-label small" for="rr-max">At most</label><input id="rr-max" v-model.number="rule.max_sends" type="number" min="1" max="12" class="form-control form-control-sm" /></div>
                        <div class="col-6"><label class="form-label small" for="rr-channel">Channel</label><select id="rr-channel" v-model="rule.channel" class="form-select form-select-sm"><option v-for="c in meta.channels" :key="c" :value="c">{{ c }}</option></select></div>
                        <div class="col-6"><label class="form-label small" for="rr-msg">Message</label><select id="rr-msg" v-model="rule.message_template_id" class="form-select form-select-sm"><option :value="null">Built-in wording</option><option v-for="m in messages.filter((x) => x.channel === rule.channel)" :key="m.id" :value="m.id">{{ m.name }}</option></select></div>
                    </div>
                    <button type="button" class="btn btn-dark btn-sm" :disabled="rule.name.trim().length < 2" @click="saveRule">Save rule</button>
                    <button type="button" class="btn btn-outline-dark btn-sm ms-2" @click="runRules">Run now</button>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="border rounded p-3 mb-3">
                    <h6>Rules</h6>
                    <div v-for="item in rules" :key="item.id" class="d-flex gap-2 align-items-center mb-1"><span class="flex-grow-1">{{ item.name }} <span class="text-muted small">{{ item.trigger }} {{ item.days }}d via {{ item.channel }}</span></span>
                        <button v-if="canManage" type="button" class="btn btn-outline-danger btn-sm py-0" @click="removeRule(item)">Delete</button></div>
                    <div v-if="!rules.length" class="text-muted small">No rules: nothing is sent automatically.</div>
                </div>
                <div class="border rounded p-3">
                    <h6>Recent reminders</h6>
                    <table class="table table-sm mb-0"><tbody>
                        <tr v-for="entry in log" :key="entry.id"><td>{{ entry.sent_on }}</td><td>{{ entry.invoice_no }}</td><td>{{ entry.channel }}</td><td>{{ entry.recipient }}</td><td>{{ entry.status }}</td></tr>
                        <tr v-if="!log.length"><td class="text-muted">Nothing sent yet.</td></tr>
                    </tbody></table>
                </div>
            </div>
        </div>

        <!-- delivery -->
        <div v-if="tab === 'delivery' && meta" class="border rounded p-3" style="max-width: 32rem">
            <h6>Instant delivery</h6>
            <p class="small text-muted">When on, a posted sale is sent to its customer as soon as it is saved, with the message template of the event "invoice_created". A failed delivery never stops the sale.</p>
            <label class="form-check mb-2"><input v-model="delivery.auto_send_sale" type="checkbox" class="form-check-input" :disabled="!canManage" /> Send the invoice when a sale is saved</label>
            <div class="row g-2 mb-2">
                <div class="col-6"><label class="form-label small" for="dl-channel">Channel</label><select id="dl-channel" v-model="delivery.channel" class="form-select form-select-sm" :disabled="!canManage"><option v-for="c in meta.channels" :key="c" :value="c">{{ c }}</option></select></div>
                <div class="col-6"><label class="form-label small" for="dl-lang">Language</label><select id="dl-lang" v-model="delivery.language" class="form-select form-select-sm" :disabled="!canManage"><option v-for="l in meta.languages" :key="l" :value="l">{{ l }}</option></select></div>
            </div>
            <button v-if="canManage" type="button" class="btn btn-dark btn-sm" @click="saveDelivery">Save</button>
        </div>

        <!-- custom fields -->
        <div v-if="tab === 'fields' && meta" class="row g-3">
            <div v-if="canManage" class="col-lg-5">
                <div class="border rounded p-3">
                    <h6>New custom field</h6>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label small" for="cf-entity">On</label><select id="cf-entity" v-model="field.entity" class="form-select form-select-sm"><option v-for="e in meta.entities" :key="e" :value="e">{{ e }}</option></select></div>
                        <div class="col-6"><label class="form-label small" for="cf-type">Type</label><select id="cf-type" v-model="field.type" class="form-select form-select-sm"><option v-for="t in meta.field_types" :key="t" :value="t">{{ t }}</option></select></div>
                        <div class="col-12"><label class="form-label small" for="cf-label">Label</label><input id="cf-label" v-model="field.label" type="text" class="form-control form-control-sm" /></div>
                        <div v-if="field.type === 'select'" class="col-12"><label class="form-label small" for="cf-options">Options (comma separated)</label><input id="cf-options" v-model="optionsText" type="text" class="form-control form-control-sm" /></div>
                    </div>
                    <label class="form-check small mt-2"><input v-model="field.show_on_print" type="checkbox" class="form-check-input" /> Print on documents</label>
                    <label class="form-check small"><input v-model="field.is_required" type="checkbox" class="form-check-input" /> Required</label>
                    <button type="button" class="btn btn-dark btn-sm mt-2" :disabled="field.label.trim().length < 2" @click="saveField">Save field</button>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="border rounded p-3">
                    <h6>Custom fields</h6>
                    <div v-for="item in fields" :key="item.id" class="d-flex gap-2 align-items-center mb-1"><span class="flex-grow-1">{{ item.label }} <span class="text-muted small">{{ item.entity }} / {{ item.type }} / {{ item.field_key }}</span></span>
                        <button v-if="canManage" type="button" class="btn btn-outline-danger btn-sm py-0" @click="removeField(item)">Delete</button></div>
                    <div v-if="!fields.length" class="text-muted small">No custom fields yet.</div>
                </div>
            </div>
        </div>
    </div>
</template>
