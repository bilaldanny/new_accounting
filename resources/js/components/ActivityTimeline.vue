<script setup lang="ts">
    import { Link } from '@inertiajs/vue3';
    import { CalendarCheck, CheckCircle2, Circle, Plus } from '@lucide/vue';
    import { computed, onMounted, ref, watch } from 'vue';
    import useActivities from '@/composables/activity';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    /**
     * A compact, read-only activity timeline for a Lead/Opportunity/Contact detail page — the real
     * backing for both Step 4 ("Activities create/list/complete working against Leads and
     * Opportunities") and Step 5 ("a timeline renders real activity rows... on Lead, Opportunity and
     * Contact detail pages").
     */
    const props = defineProps<{
        leadId?: number | string | null;
        opportunityId?: number | string | null;
        contactId?: number | string | null;
        canAdd?: boolean;
    }>();

    type TimelineItem = {
        id: number;
        type: string;
        subject: string;
        due_at: string | null;
        completed_at: string | null;
        assignee_name: string | null;
        creator_name: string | null;
        created_at: string;
    };

    const { Notify } = useCommons();
    const { completeActivity } = useActivities();

    const loading = ref(true);
    const items = ref<TimelineItem[]>([]);

    const addHref = computed(() => {
        const params = new URLSearchParams();

        if (props.leadId) {
            params.set('lead_id', String(props.leadId));
        }

        if (props.opportunityId) {
            params.set('opportunity_id', String(props.opportunityId));
        }

        if (props.contactId) {
            params.set('contact_id', String(props.contactId));
        }

        return `/activities/add?${params.toString()}`;
    });

    async function load() {
        if (! props.leadId && ! props.opportunityId && ! props.contactId) {
            items.value = [];
            loading.value = false;

            return;
        }

        loading.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.activitiesTimeline, {
                params: {
                    lead_id: props.leadId || undefined,
                    opportunity_id: props.opportunityId || undefined,
                    contact_id: props.contactId || undefined,
                },
            });
            items.value = response.data?.data ?? [];
        } catch {
            Notify('Unable to load the activity timeline.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function toggle(item: TimelineItem) {
        try {
            const response = await completeActivity(item.id);
            item.completed_at = response.data?.completed_at ?? null;
        } catch {
            Notify('Unable to update this activity.', 'alert');
        }
    }

    watch(() => [props.leadId, props.opportunityId, props.contactId], load);
    onMounted(load);
</script>

<template>
    <section class="at" data-test="activity-timeline">
        <header class="at__head">
            <div>
                <h4 class="at__title"><CalendarCheck class="h-4 w-4" /> Activity timeline</h4>
                <p class="at__subtitle">Calls, meetings, tasks and notes logged for this account</p>
            </div>
            <Link v-if="canAdd" :href="addHref" class="at__add"><Plus class="h-3.5 w-3.5" /> Log activity</Link>
        </header>

        <div v-if="loading" class="at__empty">Loading...</div>
        <div v-else-if="!items.length" class="at__empty">
            <strong>No activities logged yet</strong>
            <span>Log a call, a visit or a follow-up and it will show here in order.</span>
        </div>

        <ol v-else class="at__list">
            <li v-for="item in items" :key="item.id" class="at__item" :class="{ 'at__item--done': item.completed_at }">
                <span class="at__dot" aria-hidden="true" />
                <div class="at__body">
                    <div class="at__line">
                        <span class="at__type">{{ item.type.replace('_', ' ') }}</span>
                        <strong class="at__subject">{{ item.subject }}</strong>
                    </div>
                    <div class="at__meta">
                        <span v-if="item.due_at">Due {{ item.due_at }}</span>
                        <span>{{ item.assignee_name || item.creator_name || 'Unassigned' }}</span>
                        <span v-if="item.completed_at" class="at__done">Done</span>
                    </div>
                </div>
                <button type="button" class="at__toggle" :title="item.completed_at ? 'Reopen' : 'Mark done'" :aria-label="item.completed_at ? 'Reopen' : 'Mark done'" @click="toggle(item)">
                    <CheckCircle2 v-if="item.completed_at" class="h-5 w-5" />
                    <Circle v-else class="h-5 w-5" />
                </button>
            </li>
        </ol>
    </section>
</template>

<style scoped>
.at {
    --at-accent: #199683;
    --at-accent-dark: #0f766e;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.at__head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
}

.at__title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
}

.at__subtitle {
    margin: 0.15rem 0 0;
    font-size: 0.85rem;
    color: #64748b;
}

.at__add {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.45rem 1rem;
    border-radius: 999px;
    background: linear-gradient(135deg, #199683, #0f766e);
    color: #fff;
    font-size: 0.85rem;
    font-weight: 700;
    text-decoration: none;
    box-shadow: 0 6px 16px rgba(15, 118, 110, 0.25);
}

.at__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    padding: 2rem 1rem;
    border: 1px dashed #cbd5e1;
    border-radius: 0.95rem;
    background: #f8fafc;
    color: #64748b;
    text-align: center;
}

.at__list {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    margin: 0;
    padding: 0 0 0 1.1rem;
    list-style: none;
}

.at__list::before {
    content: '';
    position: absolute;
    top: 0.5rem;
    bottom: 0.5rem;
    left: 0.28rem;
    width: 2px;
    background: #e2e8f0;
}

.at__item {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.7rem 0.9rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.95rem;
    background: #fff;
    transition: border-color 0.15s, box-shadow 0.15s;
}

.at__item:hover {
    border-color: rgba(25, 150, 131, 0.45);
    box-shadow: 0 6px 18px rgba(15, 118, 110, 0.1);
}

.at__dot {
    position: absolute;
    left: -1.1rem;
    top: 50%;
    width: 0.65rem;
    height: 0.65rem;
    margin-top: -0.32rem;
    border-radius: 50%;
    background: var(--at-accent);
    border: 2px solid #fff;
    box-shadow: 0 0 0 1px var(--at-accent);
}

.at__item--done .at__dot {
    background: #94a3b8;
    box-shadow: 0 0 0 1px #94a3b8;
}

.at__item--done .at__subject {
    color: #94a3b8;
    text-decoration: line-through;
}

.at__body {
    flex: 1;
    min-width: 0;
}

.at__line {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

.at__type {
    padding: 0.1rem 0.55rem;
    border-radius: 999px;
    background: rgba(25, 150, 131, 0.12);
    color: var(--at-accent-dark);
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.at__subject {
    color: #0f172a;
    font-size: 0.9rem;
}

.at__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem 0.9rem;
    margin-top: 0.2rem;
    font-size: 0.8rem;
    color: #64748b;
}

.at__done {
    color: var(--at-accent-dark);
    font-weight: 700;
}

.at__toggle {
    padding: 0.15rem;
    border: 0;
    background: none;
    color: #94a3b8;
}

.at__item--done .at__toggle,
.at__toggle:hover {
    color: var(--at-accent);
}
</style>
