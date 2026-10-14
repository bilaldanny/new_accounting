<script setup lang="ts">
    import { Link } from '@inertiajs/vue3';
    import { computed } from 'vue';

    /**
     * The two things a person looks for first on the dashboard: what needs attention now (pending approvals, low stock, cheques due,
     * overdue money, expiring batches: each with its count and a link to the page that fixes it) and the quick actions they use daily.
     * Both lists only contain what the user may open.
     */
    type Attention = { key: string; label: string; count: number; href: string; tone: 'danger' | 'warning' | 'info'; hint: string };

    const props = defineProps<{
        attention: Attention[];
        can: (path: string) => boolean;
        loading: boolean;
    }>();

    const ACTIONS = [
        { label: 'New sale', href: '/sell/add', permission: '/sell/add', hint: 'Invoice a customer' },
        { label: 'Point of sale', href: '/sell/pos/add', permission: '/sell/pos', hint: 'Scan and sell at the counter' },
        { label: 'New purchase', href: '/purchase/add', permission: '/purchase/add', hint: 'Record goods from a supplier' },
        { label: 'New customer', href: '/customer/add', permission: '/customer/add', hint: 'Add a customer account' },
        { label: 'New product', href: '/product/add', permission: '/product/add', hint: 'Add an item to the catalogue' },
        { label: 'Expense', href: '/expense/add', permission: '/expense/add', hint: 'Record a business expense' },
        { label: 'Journal entry', href: '/journalentry/add', permission: '/journalentry/add', hint: 'Post a manual voucher' },
        { label: 'Reports', href: '/report/trial-balance', permission: '/report/trial-balance', hint: 'Trial balance and statements' },
    ];

    const actions = computed(() => ACTIONS.filter((action) => props.can(action.permission)));
    const open = computed(() => props.attention.filter((item) => item.count > 0));
</script>

<template>
    <section class="dg" data-test="dashboard-glance">
        <article class="dg__card dg__card--attention">
            <header class="dg__head">
                <div><h3>Needs your attention</h3><p>{{ loading ? 'Checking...' : open.length === 0 ? 'Nothing is waiting for you.' : `${open.length} thing${open.length === 1 ? '' : 's'} to look at` }}</p></div>
            </header>
            <ul v-if="open.length" class="dg__list">
                <li v-for="item in open" :key="item.key">
                    <Link :href="item.href" class="dg__item" :class="`dg__item--${item.tone}`">
                        <span class="dg__count">{{ item.count }}</span>
                        <span class="dg__text"><strong>{{ item.label }}</strong><small>{{ item.hint }}</small></span>
                        <span class="dg__go" aria-hidden="true">&rsaquo;</span>
                    </Link>
                </li>
            </ul>
            <div v-else-if="! loading" class="dg__clear"><strong>All clear</strong><span>No approvals, stock alerts or due cheques right now.</span></div>
        </article>

        <article v-if="actions.length" class="dg__card">
            <header class="dg__head"><div><h3>Quick actions</h3><p>The things you do every day</p></div></header>
            <div class="dg__actions">
                <Link v-for="action in actions" :key="action.href" :href="action.href" class="dg__action" :title="action.hint">
                    <strong>{{ action.label }}</strong>
                    <small>{{ action.hint }}</small>
                </Link>
            </div>
        </article>
    </section>
</template>

<style scoped>
.dg {
    display: grid;
    grid-template-columns: minmax(18rem, 1fr) minmax(20rem, 1.4fr);
    gap: 1rem;
    margin-bottom: 1.25rem;
}

@media (max-width: 900px) {
    .dg {
        grid-template-columns: 1fr;
    }
}

.dg__card {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
    padding: 1rem 1.15rem;
    border: 1px solid var(--bs-border-color, #e2e8f0);
    border-radius: 1rem;
    background: var(--bs-body-bg, #fff);
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.dg__head h3 {
    margin: 0;
    font-size: 0.98rem;
    font-weight: 700;
}

.dg__head p {
    margin: 0.1rem 0 0;
    font-size: 0.8rem;
    color: #94a3b8;
}

.dg__list {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.dg__item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.55rem 0.7rem;
    border: 1px solid transparent;
    border-radius: 0.8rem;
    color: inherit;
    text-decoration: none;
    transition: transform 0.12s, box-shadow 0.12s;
}

.dg__item:hover {
    transform: translateX(2px);
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
}

.dg__item--danger { background: rgba(239, 68, 68, 0.08); border-color: rgba(239, 68, 68, 0.2); }
.dg__item--warning { background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.25); }
.dg__item--info { background: rgba(25, 150, 131, 0.08); border-color: rgba(25, 150, 131, 0.2); }

.dg__count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2.1rem;
    height: 2.1rem;
    padding: 0 0.4rem;
    border-radius: 0.65rem;
    background: #fff;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.dg__item--danger .dg__count { color: #b91c1c; }
.dg__item--warning .dg__count { color: #b45309; }
.dg__item--info .dg__count { color: #0f766e; }

.dg__text {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}

.dg__text small {
    color: #64748b;
}

.dg__go {
    font-size: 1.4rem;
    color: #94a3b8;
}

.dg__clear {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.2rem;
    padding: 1.4rem 1rem;
    border-radius: 0.9rem;
    background: rgba(25, 150, 131, 0.07);
    color: #0f766e;
    text-align: center;
}

.dg__clear span {
    font-size: 0.85rem;
    color: #64748b;
}

.dg__actions {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr));
    gap: 0.6rem;
}

.dg__action {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.75rem 0.85rem;
    border: 1px solid var(--bs-border-color, #e2e8f0);
    border-radius: 0.9rem;
    background: rgba(25, 150, 131, 0.04);
    color: inherit;
    text-decoration: none;
    transition: border-color 0.12s, box-shadow 0.12s, transform 0.12s;
}

.dg__action:hover {
    border-color: #199683;
    box-shadow: 0 8px 20px rgba(15, 118, 110, 0.14);
    transform: translateY(-1px);
}

.dg__action small {
    color: #94a3b8;
    font-size: 0.74rem;
    line-height: 1.25;
}
</style>
