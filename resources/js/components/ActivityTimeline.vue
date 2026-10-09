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
    <div class="activity-timeline">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="mb-0 d-flex align-items-center gap-2">
                <CalendarCheck class="h-4 w-4" />
                Activity Timeline
            </h6>
            <Link v-if="canAdd" :href="addHref" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1">
                <Plus class="h-3.5 w-3.5" />
                Log Activity
            </Link>
        </div>

        <p v-if="loading" class="text-muted small mb-0">Loading…</p>
        <p v-else-if="!items.length" class="text-muted small mb-0">No activities logged yet.</p>

        <ul v-else class="list-unstyled mb-0 d-flex flex-column gap-2">
            <li v-for="item in items" :key="item.id" class="activity-timeline__item border rounded p-2">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <span class="badge bg-secondary-subtle text-secondary text-capitalize me-1">{{ item.type.replace('_', ' ') }}</span>
                        <span class="fw-semibold">{{ item.subject }}</span>
                        <div class="text-muted small">
                            <span v-if="item.due_at">Due {{ item.due_at }} · </span>
                            <span>{{ item.assignee_name || item.creator_name || 'Unassigned' }}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="btn btn-sm btn-link p-0"
                        :title="item.completed_at ? 'Reopen' : 'Mark done'"
                        @click="toggle(item)"
                    >
                        <CheckCircle2 v-if="item.completed_at" class="h-4 w-4 text-success" />
                        <Circle v-else class="h-4 w-4 text-muted" />
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
