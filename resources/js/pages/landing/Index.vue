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

/* Every line below is something the product does today (checked against the feature audit). */
const pillars = [
    {
        title: 'Double-entry accounting',
        items: [
            'Chart of accounts, journal entries and vouchers that must balance',
            'Approval before a voucher counts, with cost centers and attachments',
            'Trial balance, profit & loss and balance sheet for a period',
            'Bank reconciliation with statement matching',
        ],
    },
    {
        title: 'Purchasing and stock',
        items: [
            'Purchase orders with an approval step and purchase returns',
            'Goods received notes (GRN) and landed cost',
            'Multi-warehouse stock view, transfers and adjustments',
            'Serial and batch tracking with expiry',
        ],
    },
    {
        title: 'Branches, roles and access',
        items: [
            'Company data kept separate, branch-level users scoped to their branch',
            'Permissions per menu and per button, starter roles included',
            'Audit log with old and new values',
            'Two-factor sign-in with an authenticator app',
        ],
    },
    {
        title: 'Visibility and control',
        items: [
            'Dashboard figures for sales, purchases, profit, cash and bank',
            'Receivable and payable aging, consolidated branch report',
            'Pending approvals with counts across document types',
            'Budgets and budget vs actual',
        ],
    },
];

const featureGroups = [
    {
        title: 'Sales & POS',
        icon: 'M3 7h18M5 7l1 12h12l1-12M9 7a3 3 0 0 1 6 0',
        items: ['Point of sale with cash shifts and a cash drawer log', 'Sales invoices, returns and payments', 'Customer cash collection'],
    },
    {
        title: 'Tax',
        icon: 'M6 18L18 6M8 8h.01M16 16h.01',
        items: ['Tax rates and compound (grouped) tax', 'Customer tax exemptions', 'Withholding tax on sales and purchases'],
    },
    {
        title: 'Fixed assets',
        icon: 'M5 4h14v16H5zM8 8h8M8 12h8M8 16h4',
        items: ['Asset register with straight-line and declining balance depreciation', 'Revaluation, impairment and disposal with approval'],
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
        title: 'Public API & webhooks',
        icon: 'M8 8l-4 4 4 4M16 8l4 4-4 4M14 5l-4 14',
        items: ['Read-only public API with keys', 'Webhooks for events in your account'],
    },
    {
        title: 'Customer subscriptions',
        icon: 'M4 12a8 8 0 0 1 14-5l2-2v6h-6l2-2a5 5 0 1 0 1 4',
        items: ['Recurring plans and invoices for your own customers', 'Customer portal to view their ledger'],
    },
    {
        title: 'Approvals',
        icon: 'M5 12l4 4 10-10',
        items: ['Approve vouchers, purchases, sales and stock documents before they count'],
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

/* A tiny client-side demo of the rule every voucher follows: debit equals credit. Nothing is sent anywhere. */
const demoDebit = ref(25000);
const demoCredit = ref(25000);
const demoDiff = computed(() => Math.round((Number(demoDebit.value) - Number(demoCredit.value)) * 100) / 100);

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
                        <p class="lp-eyebrow">Sales, stock, accounts and tax</p>
                        <h1 id="hero-title">
                            Run your whole business
                            <span class="lp-grad">from one ledger.</span>
                        </h1>
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
                        <ul class="lp-chips">
                            <li>Approvals on vouchers</li>
                            <li>Roles and permissions</li>
                            <li>Two-factor sign-in</li>
                            <li>Audit log</li>
                        </ul>
                    </div>

                    <div class="lp-voucher" role="img" aria-label="Sample journal voucher with balanced debit and credit lines">
                        <div class="lp-voucher-bar"><i /><i /><i /><span>Sample data</span></div>
                        <div class="lp-voucher-body">
                            <div class="lp-voucher-head">
                                <span>Journal voucher JV-0001</span>
                                <small>Balanced</small>
                            </div>
                            <table class="lp-table" aria-hidden="true">
                                <thead>
                                    <tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Cash at bank</td><td class="num">25,000.00</td><td class="num">-</td></tr>
                                    <tr><td>Sales</td><td class="num">-</td><td class="num">21,186.44</td></tr>
                                    <tr><td>Output tax</td><td class="num">-</td><td class="num">3,813.56</td></tr>
                                </tbody>
                                <tfoot>
                                    <tr><td>Total</td><td class="num">25,000.00</td><td class="num">25,000.00</td></tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section class="lp-section lp-section-alt" aria-labelledby="pillars-title">
                <div class="lp-wrap">
                    <div class="lp-head">
                        <p class="lp-eyebrow">What it covers</p>
                        <h2 id="pillars-title">Four areas of financial control</h2>
                        <p>Each point below works in the product today.</p>
                    </div>
                    <div class="lp-pillars">
                        <article v-for="(pillar, index) in pillars" :key="pillar.title" class="lp-card">
                            <h3><span class="lp-num">0{{ index + 1 }}</span>{{ pillar.title }}</h3>
                            <ul class="lp-checks">
                                <li v-for="item in pillar.items" :key="item">{{ item }}</li>
                            </ul>
                        </article>
                    </div>
                </div>
            </section>

            <section id="features" class="lp-section" aria-labelledby="features-title">
                <div class="lp-wrap">
                    <div class="lp-head">
                        <p class="lp-eyebrow">And more</p>
                        <h2 id="features-title">Everything else in the box</h2>
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

            <section class="lp-section lp-section-alt" aria-labelledby="demo-title">
                <div class="lp-wrap lp-demo">
                    <div>
                        <p class="lp-eyebrow">Demo</p>
                        <h2 id="demo-title">A voucher only counts when it balances</h2>
                        <p class="lp-note" style="margin-top: 0">
                            Change an amount to see the rule every journal voucher follows. This is a local demo: nothing is saved or sent.
                        </p>
                    </div>
                    <div class="lp-card">
                        <div class="lp-field">
                            <label for="demo-debit">Debit total</label>
                            <input id="demo-debit" v-model.number="demoDebit" type="number" min="0" step="0.01" />
                        </div>
                        <div class="lp-field">
                            <label for="demo-credit">Credit total</label>
                            <input id="demo-credit" v-model.number="demoCredit" type="number" min="0" step="0.01" />
                        </div>
                        <div class="lp-demo-status" role="status" aria-live="polite">
                            <span v-if="demoDiff === 0" class="lp-ok">Balanced: it can be posted</span>
                            <span v-else class="lp-bad">Out of balance by {{ number.format(Math.abs(demoDiff)) }}: it cannot be posted</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="lp-section" aria-labelledby="roadmap-title">
                <div class="lp-wrap">
                    <div class="lp-head">
                        <p class="lp-eyebrow">Roadmap</p>
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
                        <p class="lp-eyebrow">Pricing</p>
                        <h2 id="pricing-title">Pick the plan whose limits fit your business</h2>
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
                    <div class="lp-head">
                        <p class="lp-eyebrow">FAQ</p>
                        <h2 id="faq-title">Frequently asked questions</h2>
                    </div>
                    <div class="lp-faq">
                        <details v-for="item in faqs" :key="item.q">
                            <summary>{{ item.q }}</summary>
                            <p>{{ item.a }}</p>
                        </details>
                    </div>
                </div>
            </section>

            <section class="lp-section lp-final" aria-labelledby="final-title">
                <div class="lp-wrap">
                    <h2 id="final-title">Ready to try it with your own numbers?</h2>
                    <p class="lp-lead" style="margin-inline: auto">Create an account, or log in if you already have one.</p>
                    <div class="lp-cta">
                        <Link v-if="registrationEnabled" class="lp-btn" href="/register">Register</Link>
                        <a v-else class="lp-btn" :href="contactHref">Contact us</a>
                        <a class="lp-btn lp-btn-ghost" href="/login">Login</a>
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
                    </ul>
                </div>
                <div>
                    <h3>Account</h3>
                    <ul>
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
