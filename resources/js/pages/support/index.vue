<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Support Tickets',
            subtitle: 'Ask for help and follow the answer',
            breadcrumbs: [{ title: 'Support Tickets', href: 'NULL' }],
        },
    });

    type Ticket = { id: number; subject: string; category: string; priority: string; status: string; created_at: string; company: string };
    type Message = { id: number; is_staff: boolean; body: string; created_at: string; email: string | null };

    const { isSuperadmin, run } = useCloseout();
    const tickets = ref<Ticket[]>([]);
    const open = ref<(Ticket & { messages: Message[] }) | null>(null);
    const form = ref({ subject: '', body: '', category: 'general', priority: 'normal' });
    const reply = ref('');

    async function load() {
        tickets.value = (await run(() => window.axios.get('/api/support-tickets')))?.data?.data ?? [];
    }

    async function create() {
        if (await run(() => window.axios.post('/api/support-tickets', form.value), 'Ticket opened')) {
            form.value.subject = '';
            form.value.body = '';
            await load();
        }
    }

    async function show(ticket: Ticket) {
        open.value = (await run(() => window.axios.get(`/api/support-tickets/${ticket.id}`)))?.data?.data ?? null;
    }

    async function send(close = false) {
        if (open.value && await run(() => window.axios.post(`/api/support-tickets/${open.value!.id}/reply`, { body: reply.value || '(closed)', close }), 'Sent')) {
            reply.value = '';
            await show(open.value);
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Support Tickets" />

    <div class="container-fluid py-3">
        <div v-if="! isSuperadmin" class="border rounded p-3 mb-4">
            <h6>New ticket</h6>
            <div class="row g-2">
                <div class="col-md-5"><input v-model="form.subject" class="form-control form-control-sm" placeholder="Subject" aria-label="Subject" /></div>
                <div class="col-md-2"><select v-model="form.priority" class="form-select form-select-sm" aria-label="Priority"><option>low</option><option>normal</option><option>high</option></select></div>
                <div class="col-12"><textarea v-model="form.body" rows="3" class="form-control form-control-sm" placeholder="What do you need help with?" aria-label="Message" /></div>
                <div class="col-12"><button type="button" class="btn btn-dark btn-sm" @click="create">Open ticket</button></div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <table class="table table-sm">
                    <thead><tr><th>Subject</th><th v-if="isSuperadmin">Company</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="ticket in tickets" :key="ticket.id"><td>{{ ticket.subject }}</td><td v-if="isSuperadmin">{{ ticket.company }}</td><td class="text-capitalize">{{ ticket.status }}</td><td class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="show(ticket)">Open</button></td></tr>
                        <tr v-if="! tickets.length"><td colspan="4" class="text-center text-muted">No tickets.</td></tr>
                    </tbody>
                </table>
            </div>
            <div v-if="open" class="col-md-6">
                <div class="border rounded p-3">
                    <h6>{{ open.subject }} <span class="badge text-bg-secondary text-capitalize">{{ open.status }}</span></h6>
                    <div v-for="message in open.messages" :key="message.id" class="mb-2 small" :class="message.is_staff ? 'text-primary' : ''"><strong>{{ message.is_staff ? 'Support' : message.email }}</strong> <span class="text-muted">{{ message.created_at }}</span><div>{{ message.body }}</div></div>
                    <textarea v-model="reply" rows="2" class="form-control form-control-sm mb-2" aria-label="Reply" />
                    <button type="button" class="btn btn-dark btn-sm me-2" @click="send(false)">Send</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="send(true)">Send and close</button>
                </div>
            </div>
        </div>
    </div>
</template>
