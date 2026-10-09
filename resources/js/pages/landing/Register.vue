<script setup lang="ts">
import type { Branding } from '@/components/landing/BrandMark.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import BrandMark from '@/components/landing/BrandMark.vue';

const props = defineProps<{
    branding: Branding;
    contact: { email?: string; phone?: string; address?: string };
    plan: string | null;
    countries: Array<{ id: number; name: string }>;
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
    company_email: props.old.company_email ?? '',
    company_phone: props.old.company_phone ?? '',
    address: props.old.address ?? '',
    country_id: props.old.country_id ?? '',
    zipcode: props.old.zipcode ?? '',
    ntn_no: props.old.ntn_no ?? '',
    strn_no: props.old.strn_no ?? '',
    first_name: props.old.first_name ?? '',
    last_name: props.old.last_name ?? '',
    username: props.old.username ?? '',
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
        <main class="lp-form-card lp-register">
            <aside class="lp-register-side">
                <BrandMark :branding="branding" />
                <h1>Create your account</h1>
                <p>Start a free trial. You become the administrator of a new company and can invite your team afterwards.</p>
                <ul class="lp-register-points">
                    <li>Your own company, first branch and chart of accounts</li>
                    <li>Starter roles ready: Accountant, Cashier, Manager, Sales Representative, Warehouse Keeper</li>
                    <li>Pick a plan now, change it later</li>
                </ul>
                <p class="lp-register-login">Already have an account? <a href="/login">Login</a></p>
            </aside>

            <div class="lp-register-body">
            <form method="post" action="/register" novalidate @submit="submitting = true">
                <input type="hidden" name="_token" :value="csrfToken" />

                <h2 class="lp-legend">Company</h2>

                <div class="lp-field">
                    <label for="company_name">Company name</label>
                    <input id="company_name" name="company_name" type="text" v-model="fields.company_name" autocomplete="organization" required :aria-invalid="!!errors.company_name" />
                    <p v-if="errors.company_name" class="lp-error" role="alert">{{ errors.company_name }}</p>
                </div>

                <div class="lp-two">
                    <div class="lp-field">
                        <label for="company_email">Company email</label>
                        <input id="company_email" name="company_email" type="email" v-model="fields.company_email" autocomplete="off" :aria-invalid="!!errors.company_email" />
                        <p v-if="errors.company_email" class="lp-error" role="alert">{{ errors.company_email }}</p>
                    </div>
                    <div class="lp-field">
                        <label for="company_phone">Company phone</label>
                        <input id="company_phone" name="company_phone" type="tel" v-model="fields.company_phone" autocomplete="off" :aria-invalid="!!errors.company_phone" />
                        <p v-if="errors.company_phone" class="lp-error" role="alert">{{ errors.company_phone }}</p>
                    </div>
                </div>

                <div class="lp-field">
                    <label for="address">Address</label>
                    <input id="address" name="address" type="text" v-model="fields.address" autocomplete="street-address" :aria-invalid="!!errors.address" />
                    <p v-if="errors.address" class="lp-error" role="alert">{{ errors.address }}</p>
                </div>

                <div class="lp-two">
                    <div v-if="countries.length > 0" class="lp-field">
                        <label for="country_id">Country</label>
                        <select id="country_id" name="country_id" v-model="fields.country_id">
                            <option value="">Select country</option>
                            <option v-for="country in countries" :key="country.id" :value="String(country.id)">{{ country.name }}</option>
                        </select>
                        <p v-if="errors.country_id" class="lp-error" role="alert">{{ errors.country_id }}</p>
                    </div>
                    <div class="lp-field">
                        <label for="zipcode">Zip code</label>
                        <input id="zipcode" name="zipcode" type="text" v-model="fields.zipcode" autocomplete="postal-code" :aria-invalid="!!errors.zipcode" />
                        <p v-if="errors.zipcode" class="lp-error" role="alert">{{ errors.zipcode }}</p>
                    </div>
                </div>

                <div class="lp-two">
                    <div class="lp-field">
                        <label for="ntn_no">NTN</label>
                        <input id="ntn_no" name="ntn_no" type="text" v-model="fields.ntn_no" autocomplete="off" :aria-invalid="!!errors.ntn_no" />
                        <p v-if="errors.ntn_no" class="lp-error" role="alert">{{ errors.ntn_no }}</p>
                    </div>
                    <div class="lp-field">
                        <label for="strn_no">STRN</label>
                        <input id="strn_no" name="strn_no" type="text" v-model="fields.strn_no" autocomplete="off" :aria-invalid="!!errors.strn_no" />
                        <p v-if="errors.strn_no" class="lp-error" role="alert">{{ errors.strn_no }}</p>
                    </div>
                </div>

                <h2 class="lp-legend">Administrator</h2>

                <div class="lp-two">
                    <div class="lp-field">
                        <label for="first_name">First name</label>
                        <input id="first_name" name="first_name" type="text" v-model="fields.first_name" autocomplete="given-name" required :aria-invalid="!!errors.first_name" />
                        <p v-if="errors.first_name" class="lp-error" role="alert">{{ errors.first_name }}</p>
                    </div>
                    <div class="lp-field">
                        <label for="last_name">Last name</label>
                        <input id="last_name" name="last_name" type="text" v-model="fields.last_name" autocomplete="family-name" :aria-invalid="!!errors.last_name" />
                        <p v-if="errors.last_name" class="lp-error" role="alert">{{ errors.last_name }}</p>
                    </div>
                </div>

                <div class="lp-field">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" v-model="fields.username" autocomplete="username" required :aria-invalid="!!errors.username" />
                    <p v-if="errors.username" class="lp-error" role="alert">{{ errors.username }}</p>
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

                <h2 class="lp-legend">Account</h2>

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

                <div class="lp-two">
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
                Need help? <a :href="contact.email ? `mailto:${contact.email}` : '/#contact'">Contact us</a>.
            </p>
            </div>
        </main>
    </div>
</template>
