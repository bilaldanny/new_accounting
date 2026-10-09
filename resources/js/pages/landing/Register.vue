<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import BrandMark from '@/components/landing/BrandMark.vue';

const props = defineProps<{
    branding: { name: string; logoUrl: string | null };
    contact: { email?: string; phone?: string; address?: string };
    plan: string | null;
    plans: Array<{ code: string; name: string; trial_days: number }>;
    old: Record<string, string>;
}>();

const brand = computed(() => props.branding.name);
const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);
const csrfToken = computed(() => String(page.props.csrfToken ?? ''));
const submitting = ref(false);

const wantedPlan = props.old.plan ?? props.plan ?? '';

/* Typed values live in local state, so a re-render never wipes them; the passwords are never filled back in. */
const fields = reactive({
    company_name: props.old.company_name ?? '',
    name: props.old.name ?? '',
    email: props.old.email ?? '',
    phone: props.old.phone ?? '',
    plan: props.plans.some((item) => item.code === wantedPlan) ? wantedPlan : '',
    password: '',
    password_confirmation: '',
    website: '',
});

/*
 * The form is posted the plain way (a full page load): a successful sign-up lands on the dashboard, which belongs to the main
 * application bundle, and the light public entry does not carry that. Validation errors come back on this page.
 */
</script>

<template>
    <Head :title="`Register | ${brand}`">
        <meta head-key="description" name="description" :content="`Create your ${brand} account and start a free trial.`" />
        <meta head-key="og:title" property="og:title" :content="`Register | ${brand}`" />
        <meta head-key="og:type" property="og:type" content="website" />
    </Head>

    <div class="lp lp-form-page">
        <main class="lp-form-card">
            <BrandMark :branding="branding" />
            <h1 style="font-size: 1.6rem; margin-top: 18px">Create your account</h1>
            <p class="lp-note" style="margin-top: 0">You become the administrator of a new company. Starter staff roles are set up for you.</p>

            <form method="post" action="/register" novalidate @submit="submitting = true">
                <input type="hidden" name="_token" :value="csrfToken" />

                <div class="lp-field">
                    <label for="company_name">Company name</label>
                    <input id="company_name" name="company_name" type="text" v-model="fields.company_name" autocomplete="organization" required :aria-invalid="!!errors.company_name" />
                    <p v-if="errors.company_name" class="lp-error" role="alert">{{ errors.company_name }}</p>
                </div>

                <div class="lp-field">
                    <label for="name">Your name</label>
                    <input id="name" name="name" type="text" v-model="fields.name" autocomplete="name" required :aria-invalid="!!errors.name" />
                    <p v-if="errors.name" class="lp-error" role="alert">{{ errors.name }}</p>
                </div>

                <div class="lp-two">
                    <div class="lp-field">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" v-model="fields.email" autocomplete="email" required :aria-invalid="!!errors.email" />
                        <p v-if="errors.email" class="lp-error" role="alert">{{ errors.email }}</p>
                    </div>
                    <div class="lp-field">
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" type="tel" v-model="fields.phone" autocomplete="tel" :aria-invalid="!!errors.phone" />
                        <p v-if="errors.phone" class="lp-error" role="alert">{{ errors.phone }}</p>
                    </div>
                </div>

                <div v-if="plans.length > 0" class="lp-field">
                    <label for="plan">Plan</label>
                    <select id="plan" v-model="fields.plan" name="plan">
                        <option value="">Default trial plan</option>
                        <option v-for="item in plans" :key="item.code" :value="item.code">
                            {{ item.name }} ({{ item.trial_days }}-day free trial)
                        </option>
                    </select>
                    <p v-if="errors.plan" class="lp-error" role="alert">{{ errors.plan }}</p>
                </div>

                <div class="lp-field">
                    <label for="password">Password</label>
                    <input id="password" v-model="fields.password" name="password" type="password" autocomplete="new-password" required :aria-invalid="!!errors.password" aria-describedby="password-hint" />
                    <p id="password-hint" class="lp-hint">At least 8 characters with an uppercase letter, a lowercase letter, a number and a special character.</p>
                    <p v-if="errors.password" class="lp-error" role="alert">{{ errors.password }}</p>
                </div>

                <div class="lp-field">
                    <label for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" v-model="fields.password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required />
                </div>

                <!-- Honeypot: invisible to people, tempting to bots. -->
                <div class="lp-hp" aria-hidden="true">
                    <label for="website">Leave this field empty</label>
                    <input id="website" v-model="fields.website" name="website" type="text" tabindex="-1" autocomplete="off" />
                </div>

                <button class="lp-btn lp-btn-block" type="submit" :disabled="submitting">
                    {{ submitting ? 'Creating your account...' : 'Create account' }}
                </button>
            </form>

            <p class="lp-note">
                Already have an account? <a href="/login">Login</a>. Need help? <a :href="contact.email ? `mailto:${contact.email}` : '/#contact'">Contact us</a>.
            </p>
        </main>
    </div>
</template>
