<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, CalendarCheck, CheckCircle2, Circle, Pencil } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import useActivities from '@/composables/activity';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'View Activity',
            subtitle: 'Activity details',
            breadcrumbs: [
                {
                    title: 'Activities',
                    href: '/activities',
                },
                {
                    title: 'View Activity',
                    href: 'NULL',
                },
            ],
        },
    });

    const routeProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
    });

    const page = usePage();
    const { Notify } = useCommons();
    const { completeActivity } = useActivities();

    type ActivityDetail = {
        id?: number;
        type?: string;
        subject?: string;
        description?: string | null;
        due_at?: string | null;
        completed_at?: string | null;
        company?: { name?: string } | null;
        lead?: { name?: string } | null;
        opportunity?: { name?: string } | null;
        contact?: { first_name?: string; last_name?: string; business_name?: string } | null;
        assignee?: { first_name?: string; last_name?: string } | null;
        creator?: { first_name?: string; last_name?: string } | null;
    };

    const loading = ref(true);
    const toggling = ref(false);
    const activity = ref<ActivityDetail>({});

    const permissionPaths = computed(() => (page.props.auth?.user as { permission_paths?: string[] } | null)?.permission_paths ?? []);
    const canEdit = computed(() => permissionPaths.value.includes('/activities/:id/edit'));
    const canComplete = computed(() => permissionPaths.value.includes('/activities/:id/complete'));

    const assigneeName = computed(() => {
        const assignee = activity.value.assignee;

        if (! assignee) {
            return 'Unassigned';
        }

        return [assignee.first_name, assignee.last_name].filter(Boolean).join(' ') || 'Unassigned';
    });

    async function loadActivity() {
        loading.value = true;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.activities}/${routeProps.id}`);
            activity.value = response.data;
        } catch {
            Notify('Unable to load activity details.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function toggleComplete() {
        if (! activity.value.id || toggling.value) {
            return;
        }

        toggling.value = true;

        try {
            const response = await completeActivity(activity.value.id);
            activity.value.completed_at = response.data?.completed_at ?? null;
            Notify(activity.value.completed_at ? 'Marked as done.' : 'Reopened.', 'success');
        } catch {
            Notify('Unable to update this activity.', 'alert');
        } finally {
            toggling.value = false;
        }
    }

    onMounted(loadActivity);
</script>

<template>
    <Head title="View Activity" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/activities" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to activities
                </Link>

                <div class="d-flex align-items-center gap-2">
                    <button
                        v-if="canComplete && activity.id"
                        type="button"
                        class="btn btn-sm d-inline-flex align-items-center gap-1"
                        :class="activity.completed_at ? 'btn-outline-secondary' : 'btn-outline-success'"
                        :disabled="toggling"
                        @click="toggleComplete"
                    >
                        <CheckCircle2 v-if="!activity.completed_at" class="h-4 w-4" />
                        <Circle v-else class="h-4 w-4" />
                        {{ activity.completed_at ? 'Reopen' : 'Mark Done' }}
                    </button>

                    <Link
                        v-if="canEdit && activity.id"
                        :href="`/activities/${activity.id}/edit`"
                        class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                    >
                        <Pencil class="h-4 w-4" />
                        Edit
                    </Link>
                </div>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading activity details…" />

                <div v-else class="row g-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1 text-capitalize">{{ (activity.type || 'task').replace('_', ' ') }}</p>
                                <h4 class="mb-0 d-flex align-items-center gap-2">
                                    <CalendarCheck class="h-5 w-5" />
                                    {{ activity.subject || 'Activity' }}
                                </h4>
                            </div>
                            <span
                                class="badge"
                                :class="activity.completed_at ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning'"
                            >
                                {{ activity.completed_at ? 'Done' : 'Pending' }}
                            </span>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3">Schedule</h6>
                            <dl class="mb-0 row">
                                <dt class="col-6 text-muted">Due</dt>
                                <dd class="col-6">{{ activity.due_at || '—' }}</dd>
                                <dt class="col-6 text-muted">Completed</dt>
                                <dd class="col-6">{{ activity.completed_at || '—' }}</dd>
                                <dt class="col-6 text-muted">Assigned To</dt>
                                <dd class="col-6">{{ assigneeName }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3">Related To</h6>
                            <dl class="mb-0 row">
                                <dt class="col-6 text-muted">Lead</dt>
                                <dd class="col-6">{{ activity.lead?.name || '—' }}</dd>
                                <dt class="col-6 text-muted">Opportunity</dt>
                                <dd class="col-6">{{ activity.opportunity?.name || '—' }}</dd>
                                <dt class="col-6 text-muted">Customer</dt>
                                <dd class="col-6">{{ activity.contact?.business_name || [activity.contact?.first_name, activity.contact?.last_name].filter(Boolean).join(' ') || '—' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4" v-if="activity.description">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3">Description</h6>
                            <p class="mb-0 text-body">{{ activity.description }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
