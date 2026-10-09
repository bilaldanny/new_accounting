<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { CheckCircle2, Send } from '@lucide/vue';
    import { ref } from 'vue';

    /**
     * The public, unauthenticated Lead Capture Form. No AppLayout wrapper (see the `layout` switch in
     * app.ts) — this is meant to be opened directly or embedded in an iframe on an external site, so it
     * renders nothing but the form itself.
     */
    const props = defineProps<{
        companyCode: string;
        companyName: string;
    }>();

    const form = ref({
        name: '',
        company_name: '',
        email: '',
        phone: '',
        notes: '',
        website_url: '', // honeypot — left blank by real visitors, hidden via CSS below
    });

    const submitting = ref(false);
    const submitted = ref(false);
    const errors = ref<Record<string, string[]>>({});

    async function submit() {
        if (submitting.value) {
            return;
        }

        submitting.value = true;
        errors.value = {};

        try {
            await window.axios.post('/api/leadcapture', {
                ...form.value,
                company_code: props.companyCode,
            });

            submitted.value = true;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error) && error.response?.status === 422) {
                errors.value = error.response.data?.errors ?? {};
            }
        } finally {
            submitting.value = false;
        }
    }
</script>

<template>
    <Head :title="`Contact ${companyName}`" />

    <div class="lead-capture">
        <div class="lead-capture__card">
            <template v-if="submitted">
                <div class="lead-capture__success">
                    <CheckCircle2 class="h-10 w-10 text-success" />
                    <h2>Thank you!</h2>
                    <p>We've received your details and will be in touch shortly.</p>
                </div>
            </template>

            <template v-else>
                <h2 class="lead-capture__title">Get in touch with {{ companyName }}</h2>

                <form @submit.prevent="submit" class="lead-capture__form">
                    <div class="lead-capture__field">
                        <label for="lc-name">Name *</label>
                        <input id="lc-name" v-model="form.name" type="text" required maxlength="200" />
                        <p v-if="errors.name" class="lead-capture__error">{{ errors.name[0] }}</p>
                    </div>

                    <div class="lead-capture__field">
                        <label for="lc-company">Business Name</label>
                        <input id="lc-company" v-model="form.company_name" type="text" maxlength="200" />
                    </div>

                    <div class="lead-capture__field">
                        <label for="lc-email">Email</label>
                        <input id="lc-email" v-model="form.email" type="email" maxlength="200" />
                        <p v-if="errors.email" class="lead-capture__error">{{ errors.email[0] }}</p>
                    </div>

                    <div class="lead-capture__field">
                        <label for="lc-phone">Phone</label>
                        <input id="lc-phone" v-model="form.phone" type="text" maxlength="30" />
                        <p v-if="errors.phone" class="lead-capture__error">{{ errors.phone[0] }}</p>
                    </div>

                    <div class="lead-capture__field">
                        <label for="lc-notes">Message</label>
                        <textarea id="lc-notes" v-model="form.notes" rows="3" maxlength="2000"></textarea>
                    </div>

                    <!-- Honeypot: hidden from real visitors, off-screen rather than display:none (some bots skip display:none fields) -->
                    <div class="lead-capture__honeypot" aria-hidden="true">
                        <label for="lc-website">Website</label>
                        <input id="lc-website" v-model="form.website_url" type="text" tabindex="-1" autocomplete="off" />
                    </div>

                    <button type="submit" class="lead-capture__submit" :disabled="submitting">
                        <Send class="h-4 w-4" />
                        {{ submitting ? 'Sending…' : 'Send' }}
                    </button>
                </form>
            </template>
        </div>
    </div>
</template>

<style scoped>
    .lead-capture {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        background: #f8fafc;
        font-family: inherit;
    }

    .lead-capture__card {
        width: 100%;
        max-width: 420px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 1.75rem;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    }

    .lead-capture__title {
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 1.25rem;
        color: #0f172a;
    }

    .lead-capture__form {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .lead-capture__field label {
        display: block;
        font-size: 0.8rem;
        font-weight: 600;
        color: #334155;
        margin-bottom: 0.25rem;
    }

    .lead-capture__field input,
    .lead-capture__field textarea {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 0.375rem;
        padding: 0.5rem 0.65rem;
        font-size: 0.9rem;
        color: #0f172a;
    }

    .lead-capture__field input:focus,
    .lead-capture__field textarea:focus {
        outline: none;
        border-color: #199683;
        box-shadow: 0 0 0 3px rgba(25, 150, 131, 0.15);
    }

    .lead-capture__error {
        color: #dc2626;
        font-size: 0.75rem;
        margin-top: 0.25rem;
    }

    .lead-capture__honeypot {
        position: absolute;
        left: -9999px;
        width: 1px;
        height: 1px;
        overflow: hidden;
    }

    .lead-capture__submit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        background: #199683;
        color: #fff;
        border: none;
        border-radius: 0.375rem;
        padding: 0.6rem 1rem;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
    }

    .lead-capture__submit:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .lead-capture__success {
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        padding: 1rem 0;
    }

    .lead-capture__success h2 {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0;
        color: #0f172a;
    }

    .lead-capture__success p {
        color: #64748b;
        margin: 0;
    }
</style>
