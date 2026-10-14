<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, onUnmounted, reactive, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'My Jobs',
            subtitle: 'Your service jobs: progress, parts used, customer signature and report',
            breadcrumbs: [{ title: 'Service', href: 'NULL' }, { title: 'My Jobs', href: 'NULL' }],
        },
    });

    type Job = { id: number; job_no: string; type: string; title: string; status: string; priority: string; scheduled_at: string | null; site_address: string | null; customer: string | null; is_warranty: boolean };

    const { run, scope, companyId, isSuperadmin } = useCloseout();
    const STORE_KEY = 'field-jobs-v1';
    const jobs = ref<Job[]>([]);
    const flow = ref<Record<string, string[]>>({});
    const products = ref<{ id: number; name: string }[]>([]);
    const offline = ref(false);
    const open = ref<number | null>(null);
    const detail = ref<Record<string, any> | null>(null);
    const part = reactive({ product_id: '' as number | '', quantity: 1 });
    const signer = ref('');
    const canvas = ref<HTMLCanvasElement | null>(null);
    let drawing = false;
    let dirty = false;

    function remember(list: Job[]) {
        try {
            localStorage.setItem(STORE_KEY, JSON.stringify({ at: Date.now(), jobs: list }));
        } catch {
            // private mode: the page just has no offline list
        }
    }

    async function load() {
        try {
            const response = await window.axios.get('/api/service/my-jobs', { params: scope() });
            jobs.value = response.data.data;
            offline.value = false;
            remember(jobs.value);
        } catch {
            const saved = (() => {
                try {
                    return JSON.parse(localStorage.getItem(STORE_KEY) ?? 'null');
                } catch {
                    return null;
                }
            })();
            jobs.value = saved?.jobs ?? [];
            offline.value = true;
        }

        const meta = (await run(() => window.axios.get('/api/service/meta', { params: scope() })))?.data?.data;
        flow.value = meta?.flow ?? {};
        products.value = meta?.products ?? [];
    }

    async function show(id: number) {
        open.value = id;
        detail.value = (await run(() => window.axios.get(`/api/service/jobs/${id}`, { params: scope() })))?.data?.data ?? null;
    }

    async function move(status: string) {
        if (open.value && (await run(() => window.axios.post(`/api/service/jobs/${open.value}/status`, { ...scope(), status }), 'Job updated'))) {
            await show(open.value);
            await load();
        }
    }

    async function addPart() {
        if (open.value && (await run(() => window.axios.post(`/api/service/jobs/${open.value}/parts`, { ...scope(), ...part }), 'Part added'))) {
            await show(open.value);
        }
    }

    function point(event: PointerEvent) {
        const box = canvas.value!.getBoundingClientRect();

        return { x: (event.clientX - box.left) * (canvas.value!.width / box.width), y: (event.clientY - box.top) * (canvas.value!.height / box.height) };
    }

    function start(event: PointerEvent) {
        const context = canvas.value!.getContext('2d')!;
        const { x, y } = point(event);
        drawing = true;
        context.lineWidth = 3;
        context.lineCap = 'round';
        context.beginPath();
        context.moveTo(x, y);
    }

    function move2(event: PointerEvent) {
        if (! drawing) {
            return;
        }

        const context = canvas.value!.getContext('2d')!;
        const { x, y } = point(event);
        context.lineTo(x, y);
        context.stroke();
        dirty = true;
    }

    const stop = () => {
        drawing = false;
    };

    function clearPad() {
        const element = canvas.value;
        element?.getContext('2d')?.clearRect(0, 0, element.width, element.height);
        dirty = false;
    }

    async function sign() {
        if (! open.value || ! canvas.value || ! dirty || ! signer.value.trim()) {
            return;
        }

        if (await run(() => window.axios.post(`/api/service/jobs/${open.value}/signature`, { ...scope(), name: signer.value, image: canvas.value!.toDataURL('image/png') }), 'Signature kept')) {
            clearPad();
            await show(open.value);
        }
    }

    function installable() {
        if (! document.querySelector('link[rel="manifest"]')) {
            const link = document.createElement('link');
            link.rel = 'manifest';
            link.href = '/manifest.webmanifest';
            document.head.appendChild(link);
        }

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/field-sw.js', { scope: '/service/' }).then((registration) => {
                registration.active?.postMessage({ type: 'precache', urls: ['/icons-field-192.png'] });
            }).catch(() => undefined);
        }
    }

    onMounted(() => {
        installable();
        void load();
    });

    onUnmounted(() => document.querySelector('link[rel="manifest"]')?.remove());
</script>

<template>
    <Head title="My Jobs" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        <div v-if="offline" class="alert alert-warning py-2 small">You are offline: this is the list as it was when you last had a connection.</div>

        <div class="row g-3">
            <div class="col-lg-5">
                <div v-for="job in jobs" :key="job.id" class="border rounded p-3 mb-2" :class="{ 'border-dark': open === job.id }" role="button" tabindex="0" @click="show(job.id)" @keyup.enter="show(job.id)">
                    <div class="d-flex justify-content-between"><strong>{{ job.job_no }}</strong><span class="badge text-bg-secondary text-capitalize">{{ job.status.replace('_', ' ') }}</span></div>
                    <div>{{ job.title }}</div>
                    <div class="small text-muted">{{ job.customer ?? 'No customer' }} <span v-if="job.scheduled_at">- {{ job.scheduled_at }}</span></div>
                    <div v-if="job.site_address" class="small">{{ job.site_address }}</div>
                </div>
                <div v-if="! jobs.length" class="text-muted">No jobs assigned to you.</div>
            </div>

            <div v-if="detail" class="col-lg-7 border rounded p-3">
                <h6>{{ detail.job_no }} - {{ detail.title }}</h6>
                <p class="small">{{ detail.description }}</p>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button v-for="next in (flow[detail.status] ?? []).filter((s) => ! ['cancelled'].includes(s))" :key="next" type="button" class="btn btn-dark btn-sm text-capitalize" @click="move(next)">{{ next.replace('_', ' ') }}</button>
                </div>

                <h6 class="small">Parts used</h6>
                <ul class="small"><li v-for="p in detail.parts" :key="p.id">{{ p.name }} x {{ p.quantity }}</li><li v-if="! detail.parts.length" class="text-muted">None</li></ul>
                <div class="d-flex gap-2 mb-3">
                    <select v-model="part.product_id" class="form-select form-select-sm" aria-label="Part"><option value="">Part</option><option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                    <input v-model.number="part.quantity" type="number" class="form-control form-control-sm" style="width: 6rem" aria-label="Part quantity" />
                    <button type="button" class="btn btn-outline-dark btn-sm" :disabled="! part.product_id" @click="addPart">Add part</button>
                </div>

                <h6 class="small">Customer signature</h6>
                <div v-if="detail.has_signature" class="small text-success mb-2">Signed by {{ detail.signed_by }} on {{ detail.signed_at }}.</div>
                <canvas ref="canvas" width="600" height="200" class="border rounded w-100 mb-2" style="touch-action: none; background: #fff" aria-label="Signature pad"
                    @pointerdown="start" @pointermove="move2" @pointerup="stop" @pointerleave="stop"></canvas>
                <div class="d-flex gap-2">
                    <input v-model="signer" class="form-control form-control-sm" placeholder="Name of the person signing" aria-label="Signer name" />
                    <button type="button" class="btn btn-outline-dark btn-sm" @click="clearPad">Clear</button>
                    <button type="button" class="btn btn-dark btn-sm" @click="sign">Save signature</button>
                </div>
                <a class="btn btn-link btn-sm px-0 mt-2" :href="`/api/service/jobs/${detail.id}/report`" target="_blank" rel="noopener">Service report</a>
            </div>
        </div>
    </div></div></div>
</template>
