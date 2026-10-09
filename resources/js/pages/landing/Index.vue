<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Plan {
    code: string;
    name: string;
    billing_cycle: 'monthly' | 'quarterly' | 'annual' | string;
    price: number;
    trial_days: number;
    limits: {
        users: number;
        branches: number;
        warehouses: number;
        products: number;
        invoices_per_month: number;
        storage_mb: number;
    };
}

const props = defineProps<{
    plans: Plan[];
    brand: string;
    currencyLabel: string;
    contact: { email?: string; phone?: string; address?: string };
    registrationEnabled: boolean;
}>();

const description =
    'Sales, purchases, inventory, accounting, tax, CRM and reports for a growing business, with approvals and role-based access. Start a free trial.';

const menuOpen = ref(false);

/* Only things the product does today. */
const featureGroups = [
    {
        title: 'Sales & POS',
        icon: 'M3 7h18M5 7l1 12h12l1-12M9 7a3 3 0 0 1 6 0',
        items: ['Point of sale with cash shifts and a cash drawer log', 'Sales invoices, returns and payments', 'Customer cash collection'],
    },
    {
        title: 'Purchases',
        icon: 'M4 6h16v12H4zM4 10h16M9 14h2',
        items: ['Purchases, purchase returns and payments', 'Goods received notes (GRN)', 'Landed cost on purchases'],
    },
    {
        title: 'Inventory',
        icon: 'M3 8l9-5 9 5v8l-9 5-9-5zM3 8l9 5 9-5M12 13v8',
        items: ['Multi-warehouse stock view and locations', 'Serial and batch tracking with expiry', 'Stock transfers and adjustments'],
    },
    {
        title: 'Accounting',
        icon: 'M5 4h14v16H5zM8 8h8M8 12h8M8 16h4',
        items: ['Chart of accounts and journal entries', 'Bank reconciliation', 'Fixed assets with depreciation', 'Cost centers and budgets'],
    },
    {
        title: 'Tax',
        icon: 'M6 18L18 6M8 8h.01M16 16h.01',
        items: ['Tax rates and compound (grouped) tax', 'Customer tax exemptions', 'Withholding tax on sales and purchases'],
    },
    {
        title: 'CRM',
        icon: 'M4 5h16l-6 8v6l-4-2v-4z',
        items: ['Leads and lead sources', 'Sales pipeline and opportunities'],
    },
    {
        title: 'Reports',
        icon: 'M5 19V9M12 19V5M19 19v-7',
        items: ['Profit & loss and balance sheet', 'Ledgers and trial balance', 'Customer and supplier aging', 'Stock reports'],
    },
    {
        title: 'Approvals',
        icon: 'M5 12l4 4 10-10',
        items: ['Approve vouchers, purchases, sales and stock documents before they count'],
    },
    {
        title: 'Roles & permissions',
        icon: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z',
        items: ['Starter roles: Accountant, Cashier, Sales Representative, Manager, Warehouse Keeper', 'Menu-level permissions per role'],
    },
    {
        title: 'Public API & webhooks',
        icon: 'M8 8l-4 4 4 4M16 8l4 4-4 4M14 5l-4 14',
        items: ['Read-only public API with keys', 'Webhooks for events in your account'],
    },
    {
        title: 'Customer subscriptions',
        icon: 'M4 12a8 8 0 0 1 14-5l2-2v6h-6l2-2a5 5 0 1 0 1 4',
        items: ['Recurring plans and invoices for your own customers', 'Customer portal to view their ledger'],
    },
];

const roadmap = [
    { name: 'HR & Payroll', note: 'planned' },
    { name: 'Manufacturing', note: 'planned' },
    { name: 'FBR e-invoicing', note: 'roadmap: not available today' },
];

const faqs = [
    {
        q: 'Do I need to pay to try it?',
        a: 'No payment is taken to start. Plans that include trial days let you register and use the product for that many days. There is no card form: payment after the trial is arranged with us directly.',
    },
    {
        q: 'How do I pay for a plan?',
        a: 'Payment is manual, by bank transfer. We issue the invoice for your plan; there is no online payment gateway.',
    },
    {
        q: 'Does it support FBR e-invoicing?',
        a: 'Not yet. FBR e-invoicing is on the roadmap and is not live. Please confirm your own FBR registration requirements with your tax consultant.',
    },
    {
        q: 'Is my company data kept separate from other companies?',
        a: 'Yes. Every company has its own data, and inside a company access is controlled by roles and permissions.',
    },
    {
        q: 'Can my staff have limited access?',
        a: 'Yes. Start from the ready-made roles (Accountant, Cashier, Sales Representative, Manager, Warehouse Keeper) and adjust menu permissions as needed. Only the Manager role gets approval pages.',
    },
    {
        q: 'What are the limits of a plan?',
        a: 'Each plan sets the number of users, branches, warehouses, products, invoices per month and the storage. They are listed on each plan card above.',
    },
];

const cycleLabels: Record<string, string> = { monthly: 'Monthly', quarterly: 'Quarterly', annual: 'Annual' };
const cycleSuffix: Record<string, string> = { monthly: 'per month', quarterly: 'per quarter', annual: 'per year' };

const cycles = computed(() => {
    const order = ['monthly', 'quarterly', 'annual'];

    return order.filter((cycle) => props.plans.some((plan) => plan.billing_cycle === cycle));
});

const activeCycle = ref(cycles.value.includes('monthly') ? 'monthly' : (cycles.value[0] ?? 'monthly'));

const visiblePlans = computed(() => props.plans.filter((plan) => plan.billing_cycle === activeCycle.value));

const number = new Intl.NumberFormat('en', { maximumFractionDigits: 2 });

function priceText(plan: Plan): string {
    if (plan.price === 0) {
        return 'Free';
    }

    return [props.currencyLabel, number.format(plan.price)].filter(Boolean).join(' ');
}

function storageText(mb: number): string {
    return mb >= 1024 ? `${number.format(mb / 1024)} GB` : `${number.format(mb)} MB`;
}

function limitRows(plan: Plan): Array<[string, string]> {
    const l = plan.limits;

    return [
        ['Users', number.format(l.users)],
        ['Branches', number.format(l.branches)],
        ['Warehouses', number.format(l.warehouses)],
        ['Products', number.format(l.products)],
        ['Invoices per month', number.format(l.invoices_per_month)],
        ['Storage', storageText(l.storage_mb)],
    ];
}

const contactHref = computed(() => (props.contact.email ? `mailto:${props.contact.email}` : '#contact'));
</script>

<template>
    <Head :title="`${brand} | Sales, inventory, accounting and tax in one place`">
        <meta head-key="description" name="description" :content="description" />
        <meta head-key="og:title" property="og:title" :content="`${brand} | Sales, inventory, accounting and tax`" />
        <meta head-key="og:description" property="og:description" :content="description" />
        <meta head-key="og:type" property="og:type" content="website" />
        <meta head-key="og:site_name" property="og:site_name" :content="brand" />
    </Head>

    <div class="lp">
        <a class="lp-skip" href="#main">Skip to content</a>

        <header class="lp-nav">
            <div class="lp-wrap lp-nav-row">
                <a class="lp-logo" href="/" :aria-label="`${brand} home`">
                    <span class="lp-logo-mark" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20L12 4l8 16M8 14h8" /></svg>
                    </span>
                    {{ brand }}
                </a>
                <button class="lp-menu-btn" type="button" :aria-expanded="menuOpen" aria-controls="lp-links" @click="menuOpen = !menuOpen">
                    Menu
                </button>
                <nav id="lp-links" class="lp-links" :class="{ 'is-open': menuOpen }" aria-label="Main">
                    <a href="#features" @click="menuOpen = false">Features</a>
                    <a href="#pricing" @click="menuOpen = false">Pricing</a>
                    <a href="#faq" @click="menuOpen = false">FAQ</a>
                    <a href="/login">Login</a>
                    <Link v-if="registrationEnabled" class="lp-btn" href="/register">Register</Link>
                    <a v-else class="lp-btn" :href="contactHref">Contact us</a>
                </nav>
            </div>
        </header>

        <main id="main">
            <section class="lp-hero" aria-labelledby="hero-title">
                <div class="lp-wrap lp-hero-grid">
                    <div>
                        <h1 id="hero-title">Run sales, stock, accounts and tax from one place.</h1>
                        <p class="lp-lead">
                            {{ brand }} brings your point of sale, purchases, inventory, accounting and reports together, with approvals and
                            role-based access for your team.
                        </p>
                        <div class="lp-cta">
                            <Link v-if="registrationEnabled" class="lp-btn" href="/register">Register</Link>
                            <a v-else class="lp-btn" :href="contactHref">Contact us</a>
                            <a class="lp-btn lp-btn-ghost" href="/login">Login</a>
                        </div>
                        <p class="lp-note">Payment is arranged manually by bank transfer; no online payment gateway.</p>
                    </div>
                    <div class="lp-preview" aria-hidden="true">
                        <div class="lp-preview-bar"><i /><i /><i /></div>
                        <div class="lp-preview-kpis"><b /><b /><b /></div>
                        <div class="lp-preview-chart">
                            <span style="height: 40%" /><span style="height: 65%" /><span style="height: 50%" /><span style="height: 82%" /><span
                                style="height: 60%"
                            /><span style="height: 90%" /><span style="height: 72%" />
                        </div>
                        <div class="lp-preview-rows"><i /><i style="width: 80%" /><i style="width: 62%" /></div>
                    </div>
                </div>
            </section>

            <section id="features" class="lp-section lp-section-alt" aria-labelledby="features-title">
                <div class="lp-wrap">
                    <div class="lp-head">
                        <h2 id="features-title">What you get</h2>
                        <p>Everything below works in the product today.</p>
                    </div>
                    <div class="lp-groups">
                        <article v-for="group in featureGroups" :key="group.title" class="lp-card">
                            <div class="lp-icon" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="group.icon" /></svg>
                            </div>
                            <h3>{{ group.title }}</h3>
                            <ul>
                                <li v-for="item in group.items" :key="item">{{ item }}</li>
                            </ul>
                        </article>
                    </div>
                </div>
            </section>

            <section class="lp-section" aria-labelledby="roadmap-title">
                <div class="lp-wrap">
                    <div class="lp-head">
                        <h2 id="roadmap-title">Coming soon</h2>
                        <p>Not available yet. Listed so you know what we are working towards.</p>
                    </div>
                    <div class="lp-roadmap">
                        <span v-for="item in roadmap" :key="item.name">{{ item.name }} <small>({{ item.note }})</small></span>
                    </div>
                </div>
            </section>

            <section id="pricing" class="lp-section lp-section-alt" aria-labelledby="pricing-title">
                <div class="lp-wrap">
                    <div class="lp-head">
                        <h2 id="pricing-title">Pricing</h2>
                        <p>Pick the plan whose limits fit your business.</p>
                    </div>

                    <template v-if="plans.length > 0">
                        <div v-if="cycles.length > 1" class="lp-cycles" role="group" aria-label="Billing period">
                            <button v-for="cycle in cycles" :key="cycle" type="button" :aria-pressed="activeCycle === cycle" @click="activeCycle = cycle">
                                {{ cycleLabels[cycle] ?? cycle }}
                            </button>
                        </div>

                        <div class="lp-plans">
                            <article v-for="plan in visiblePlans" :key="plan.code" class="lp-card lp-plan" :data-test="`plan-${plan.code}`">
                                <h3>{{ plan.name }}</h3>
                                <div class="lp-price">
                                    {{ priceText(plan) }}
                                    <small v-if="plan.price > 0">{{ cycleSuffix[plan.billing_cycle] ?? plan.billing_cycle }}</small>
                                </div>
                                <p v-if="plan.trial_days > 0" class="lp-trial">{{ plan.trial_days }}-day free trial</p>
                                <p v-else class="lp-note" style="margin: 0">No trial on this plan</p>
                                <ul class="lp-limits">
                                    <li v-for="[label, value] in limitRows(plan)" :key="label">
                                        <span>{{ label }}</span><span>{{ value }}</span>
                                    </li>
                                </ul>
                                <Link v-if="plan.trial_days > 0 && registrationEnabled" class="lp-btn lp-btn-block" :href="`/register?plan=${plan.code}`">
                                    Start free trial
                                </Link>
                                <a v-else class="lp-btn lp-btn-ghost lp-btn-block" :href="contactHref">Contact us</a>
                            </article>
                        </div>
                    </template>
                    <div v-else class="lp-card">
                        <h3>Talk to us about pricing</h3>
                        <p class="lp-note">Plans are not published yet. Contact us and we will set one up for your business.</p>
                        <a class="lp-btn" :href="contactHref">Contact us</a>
                    </div>

                    <p class="lp-callout">
                        Payment is manual, by bank transfer. We send you the invoice for your plan; there is no online payment gateway.
                    </p>
                </div>
            </section>

            <section id="faq" class="lp-section" aria-labelledby="faq-title">
                <div class="lp-wrap">
                    <div class="lp-head"><h2 id="faq-title">Frequently asked questions</h2></div>
                    <div class="lp-faq">
                        <details v-for="item in faqs" :key="item.q">
                            <summary>{{ item.q }}</summary>
                            <p>{{ item.a }}</p>
                        </details>
                    </div>
                </div>
            </section>
        </main>

        <footer id="contact" class="lp-footer">
            <div class="lp-wrap lp-footer-grid">
                <div>
                    <a class="lp-logo" href="/">{{ brand }}</a>
                    <p style="margin-top: 8px">Sales, stock, accounts and tax for your business.</p>
                </div>
                <div>
                    <h3>Product</h3>
                    <ul>
                        <li><a href="#features">Features</a></li>
                        <li><a href="#pricing">Pricing</a></li>
                        <li><a href="#faq">FAQ</a></li>
                        <li><a href="/login">Login</a></li>
                        <li v-if="registrationEnabled"><Link href="/register">Register</Link></li>
                    </ul>
                </div>
                <div>
                    <h3>Contact</h3>
                    <ul v-if="contact.email || contact.phone || contact.address">
                        <li v-if="contact.email"><a :href="`mailto:${contact.email}`">{{ contact.email }}</a></li>
                        <li v-if="contact.phone"><a :href="`tel:${contact.phone.replace(/\s+/g, '')}`">{{ contact.phone }}</a></li>
                        <li v-if="contact.address">{{ contact.address }}</li>
                    </ul>
                    <p v-else>Contact details will be added here.</p>
                </div>
            </div>
            <div class="lp-wrap lp-legal">&copy; {{ new Date().getFullYear() }} {{ brand }}. FBR e-invoicing is on the roadmap and is not available today.</div>
        </footer>
    </div>
</template>
