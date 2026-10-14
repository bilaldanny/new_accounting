<script setup lang="ts">
    import { computed, reactive, ref } from 'vue';

    /**
     * The account statement of one party for the period shown on the ledger tab: download it as a PDF, email it (PDF attached) or send it
     * to WhatsApp (a link to the PDF). The recipient starts as the party's own email / mobile and can be changed for one send; the answer
     * of the server (sent, not configured, failed and why) is shown as it came.
     */
    const props = defineProps<{
        contactId: number | string | null | undefined;
        companyId?: number | string | null;
        branchId?: number | string | null;
        startDate?: string;
        endDate?: string;
        email?: string | null;
        mobile?: string | null;
        partyName?: string | null;
    }>();

    const open = ref<'' | 'email' | 'whatsapp'>('');
    const busy = ref(false);
    const downloading = ref(false);
    const recipient = ref('');
    const message = ref('');
    const result = reactive<{ ok: boolean; text: string }>({ ok: true, text: '' });

    const query = computed(() => ({
        contact_id: props.contactId,
        company_id: props.companyId || undefined,
        branch_id: props.branchId || undefined,
        start_date: props.startDate || undefined,
        end_date: props.endDate || undefined,
    }));

    const disabled = computed(() => ! props.contactId);

    function show(channel: 'email' | 'whatsapp') {
        result.text = '';

        if (open.value === channel) {
            open.value = '';

            return;
        }

        open.value = channel;
        recipient.value = String((channel === 'email' ? props.email : props.mobile) ?? '');
    }

    async function download() {
        downloading.value = true;
        result.text = '';

        try {
            const response = await window.axios.get('/api/fetchledger/pdf', { params: query.value, responseType: 'blob' });
            const header = String(response.headers['content-disposition'] ?? '');
            const name = /filename="?([^";]+)"?/i.exec(header)?.[1] ?? 'ledger-statement.pdf';
            const url = URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = name;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 5000);
        } catch (error: any) {
            result.ok = false;
            result.text = error?.response?.status === 403 ? 'You do not have permission to download this ledger.' : 'The PDF could not be made. Please try again.';
        } finally {
            downloading.value = false;
        }
    }

    async function send() {
        const channel = open.value;

        if (channel === '') {
            return;
        }

        busy.value = true;
        result.text = '';

        try {
            const response = await window.axios.post('/api/contact-ledger/send', {
                ...query.value,
                channel,
                recipient: recipient.value.trim() || undefined,
                message: message.value.trim() || undefined,
            });
            const data = response.data ?? {};

            result.ok = data.status === 'sent';
            result.text = data.status === 'sent'
                ? `Sent to ${data.recipient}.`
                : (data.error || 'It was not sent.');
        } catch (error: any) {
            const data = error?.response?.data ?? {};

            result.ok = false;
            result.text = data.error || data.message || 'It could not be sent. Please try again.';
        } finally {
            busy.value = false;
        }
    }
</script>

<template>
    <div class="ledger-statement-actions" data-test="ledger-statement-actions">
        <div class="las__row">
            <button type="button" class="las__btn" data-test="ledger-pdf" :disabled="disabled || downloading" @click="download">
                {{ downloading ? 'Making PDF...' : 'Download PDF' }}
            </button>
            <button type="button" class="las__btn" data-test="ledger-email" :disabled="disabled" :aria-expanded="open === 'email'" @click="show('email')">
                Email
            </button>
            <button type="button" class="las__btn las__btn--whatsapp" data-test="ledger-whatsapp" :disabled="disabled" :aria-expanded="open === 'whatsapp'" @click="show('whatsapp')">
                WhatsApp
            </button>
        </div>

        <form v-if="open !== ''" class="las__panel" data-test="ledger-send-panel" @submit.prevent="send">
            <label class="las__label" for="ledger-recipient">
                {{ open === 'email' ? 'Send the statement (PDF attached) to this email' : 'Send a link to the statement PDF to this WhatsApp number' }}
            </label>
            <div class="las__fields">
                <input
                    id="ledger-recipient" v-model="recipient" :type="open === 'email' ? 'email' : 'tel'" class="form-control form-control-sm"
                    style="max-width: 20rem" :placeholder="open === 'email' ? 'name@example.com' : '03001234567'" autocomplete="off"
                >
                <input v-model="message" type="text" class="form-control form-control-sm" style="max-width: 20rem" placeholder="A line for the party (optional)" maxlength="300" aria-label="Message">
                <button type="submit" class="las__send" data-test="ledger-send" :disabled="busy">{{ busy ? 'Sending...' : 'Send' }}</button>
            </div>
            <div class="las__hint">
                For {{ partyName || 'this party' }}, {{ startDate || 'start' }} to {{ endDate || 'today' }}. The link works for 14 days.
            </div>
        </form>

        <div v-if="result.text" class="las__result" :class="result.ok ? 'las__result--ok' : 'las__result--bad'" role="status" data-test="ledger-send-result">{{ result.text }}</div>
    </div>
</template>

<style scoped>
.ledger-statement-actions {
    --las-accent: #199683;
    --las-accent-dark: #0f766e;
    margin: 0.25rem 1.25rem 1rem;
}

.las__row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
}

.las__btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 1rem;
    border: 1px solid #dbe4ee;
    border-radius: 999px;
    background: #fff;
    color: #334155;
    font-size: 0.85rem;
    font-weight: 700;
    transition: border-color 0.15s, box-shadow 0.15s, color 0.15s;
}

.las__btn:hover:not(:disabled) {
    border-color: var(--las-accent);
    color: var(--las-accent-dark);
    box-shadow: 0 4px 12px rgba(25, 150, 131, 0.15);
}

.las__btn[aria-expanded='true'] {
    border-color: var(--las-accent);
    background: rgba(25, 150, 131, 0.1);
    color: var(--las-accent-dark);
}

.las__btn--whatsapp {
    color: #15803d;
}

.las__btn:disabled {
    opacity: 0.55;
}

.las__panel {
    margin-top: 0.6rem;
    padding: 0.8rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.95rem;
    background: #f8fafc;
}

.las__label {
    display: block;
    margin-bottom: 0.35rem;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
}

.las__fields {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.las__send {
    padding: 0.45rem 1.3rem;
    border: 0;
    border-radius: 999px;
    background: linear-gradient(135deg, #199683, #0f766e);
    color: #fff;
    font-weight: 700;
    box-shadow: 0 6px 16px rgba(15, 118, 110, 0.25);
}

.las__send:disabled {
    opacity: 0.6;
}

.las__hint {
    margin-top: 0.45rem;
    font-size: 0.8rem;
    color: #64748b;
}

.las__result {
    margin-top: 0.5rem;
    padding: 0.5rem 0.8rem;
    border-radius: 0.7rem;
    font-size: 0.85rem;
    font-weight: 600;
}

.las__result--ok {
    background: rgba(25, 150, 131, 0.1);
    color: var(--las-accent-dark);
}

.las__result--bad {
    background: #fee2e2;
    color: #b91c1c;
}
</style>
