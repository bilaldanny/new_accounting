<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, ListOrdered, Plus } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Pipeline Board',
            subtitle: 'Drag a card to move it to a different stage',
            breadcrumbs: [
                {
                    title: 'Opportunities',
                    href: '/opportunities',
                },
                {
                    title: 'Pipeline Board',
                    href: 'NULL',
                },
            ],
        },
    });

    type Stage = {
        id: number;
        name: string;
        color: string | null;
        is_won: boolean;
        is_lost: boolean;
    };

    type Card = {
        id: number;
        name: string;
        deal_value: string | number | null;
        expected_closing_date: string | null;
        pipeline_stage_id: number | null;
        assignee_name: string | null;
        status: string;
    };

    const page = usePage();
    const { Notify, fetchCompany, companiesdata } = useCommons();

    const loading = ref(true);
    const stages = ref<Stage[]>([]);
    const cards = ref<Card[]>([]);
    const draggingId = ref<number | null>(null);
    const dragOverStageId = ref<number | null>(null);
    const movingId = ref<number | null>(null);
    const selectedCompanyId = ref<string | number>('');

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
    } | null);

    const roleName = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, ''));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');

    const cardsByStage = computed(() => {
        const grouped: Record<number, Card[]> = {};

        for (const stage of stages.value) {
            grouped[stage.id] = [];
        }

        for (const card of cards.value) {
            if (card.pipeline_stage_id !== null && grouped[card.pipeline_stage_id]) {
                grouped[card.pipeline_stage_id].push(card);
            }
        }

        return grouped;
    });

    const unassignedCards = computed(() => cards.value.filter((card) => card.pipeline_stage_id === null || ! stages.value.some((stage) => stage.id === card.pipeline_stage_id)));

    function stageTotal(stageId: number): number {
        return (cardsByStage.value[stageId] ?? []).reduce((sum, card) => sum + Number(card.deal_value ?? 0), 0);
    }

    async function loadBoard() {
        loading.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.opportunitiesBoard, {
                params: { company_id: selectedCompanyId.value || undefined },
            });
            stages.value = response.data?.stages ?? [];
            cards.value = response.data?.opportunities ?? [];
        } catch {
            Notify('Unable to load the pipeline board.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    function onDragStart(card: Card, event: DragEvent) {
        draggingId.value = card.id;
        event.dataTransfer?.setData('text/plain', String(card.id));
        event.dataTransfer!.effectAllowed = 'move';
    }

    function onDragEnd() {
        draggingId.value = null;
        dragOverStageId.value = null;
    }

    function onDragOverColumn(stageId: number, event: DragEvent) {
        event.preventDefault();
        dragOverStageId.value = stageId;
    }

    function onDragLeaveColumn(stageId: number) {
        if (dragOverStageId.value === stageId) {
            dragOverStageId.value = null;
        }
    }

    async function onDropColumn(stage: Stage, event: DragEvent) {
        event.preventDefault();
        dragOverStageId.value = null;

        const idText = event.dataTransfer?.getData('text/plain');
        const id = idText ? Number(idText) : draggingId.value;
        draggingId.value = null;

        if (! id) {
            return;
        }

        const card = cards.value.find((c) => c.id === id);

        if (! card || card.pipeline_stage_id === stage.id) {
            return;
        }

        const previousStageId = card.pipeline_stage_id;
        card.pipeline_stage_id = stage.id;
        movingId.value = id;

        try {
            const response = await window.axios.post(`${API_ENDPOINTS.opportunities}/${id}/move-stage`, {
                pipeline_stage_id: stage.id,
            });
            card.status = response.data?.status ?? card.status;
        } catch {
            card.pipeline_stage_id = previousStageId;
            Notify('Unable to move this opportunity.', 'alert');
        } finally {
            movingId.value = null;
        }
    }

    onMounted(async () => {
        selectedCompanyId.value = authUser.value?.company_id ?? '';

        if (isSuperadmin.value) {
            await fetchCompany();
        }

        await loadBoard();
    });
</script>

<template>
    <Head title="Pipeline Board" />

    <div class="admin-list-page">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <Link href="/opportunities" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                <ArrowLeft class="h-4 w-4" />
                Back to opportunities
            </Link>

            <div class="d-flex flex-wrap align-items-center gap-2">
                <select
                    v-if="isSuperadmin"
                    class="form-select form-select-sm"
                    style="width: auto;"
                    v-model="selectedCompanyId"
                    @change="loadBoard"
                >
                    <option value="">All companies</option>
                    <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                        {{ company.text ?? company.name }}
                    </option>
                </select>

                <Link href="/pipelinestages" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
                    <ListOrdered class="h-4 w-4" />
                    Manage Stages
                </Link>
                <Link href="/opportunities/add" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1">
                    <Plus class="h-4 w-4" />
                    Add Opportunity
                </Link>
            </div>
        </div>

        <Loader v-if="loading" message="Loading pipeline board…" />

        <div v-else-if="stages.length === 0" class="admin-list-card p-4 text-center">
            <p class="mb-2">No pipeline stages yet.</p>
            <Link href="/pipelinestages/add" class="btn btn-sm btn-primary">Create your first stage</Link>
        </div>

        <div v-else class="pipeline-board">
            <div
                v-if="unassignedCards.length"
                class="pipeline-column"
                @dragover="onDragOverColumn(0, $event)"
                @dragleave="onDragLeaveColumn(0)"
            >
                <div class="pipeline-column__header" style="border-top-color: #94a3b8;">
                    <span>Unassigned</span>
                    <span class="badge bg-secondary-subtle text-secondary">{{ unassignedCards.length }}</span>
                </div>
                <div class="pipeline-column__body">
                    <Link
                        v-for="card in unassignedCards"
                        :key="card.id"
                        :href="`/opportunities/${card.id}/view`"
                        class="pipeline-card"
                        draggable="true"
                        :class="{ 'pipeline-card--dragging': draggingId === card.id, 'pipeline-card--moving': movingId === card.id }"
                        @dragstart="onDragStart(card, $event)"
                        @dragend="onDragEnd"
                    >
                        <div class="pipeline-card__title">{{ card.name }}</div>
                        <div class="pipeline-card__meta">
                            <span>{{ card.deal_value ?? 0 }}</span>
                            <span>{{ card.assignee_name || 'Unassigned' }}</span>
                        </div>
                    </Link>
                </div>
            </div>

            <div
                v-for="stage in stages"
                :key="stage.id"
                class="pipeline-column"
                :class="{ 'pipeline-column--over': dragOverStageId === stage.id }"
                @dragover="onDragOverColumn(stage.id, $event)"
                @dragleave="onDragLeaveColumn(stage.id)"
                @drop="onDropColumn(stage, $event)"
            >
                <div class="pipeline-column__header" :style="{ borderTopColor: stage.color || '#199683' }">
                    <span>{{ stage.name }}</span>
                    <span class="badge bg-secondary-subtle text-secondary">{{ (cardsByStage[stage.id] ?? []).length }}</span>
                </div>
                <div class="pipeline-column__total">{{ stageTotal(stage.id) }}</div>
                <div class="pipeline-column__body">
                    <Link
                        v-for="card in cardsByStage[stage.id]"
                        :key="card.id"
                        :href="`/opportunities/${card.id}/view`"
                        class="pipeline-card"
                        draggable="true"
                        :class="{ 'pipeline-card--dragging': draggingId === card.id, 'pipeline-card--moving': movingId === card.id }"
                        @dragstart="onDragStart(card, $event)"
                        @dragend="onDragEnd"
                    >
                        <div class="pipeline-card__title">{{ card.name }}</div>
                        <div class="pipeline-card__meta">
                            <span>{{ card.deal_value ?? 0 }}</span>
                            <span>{{ card.assignee_name || 'Unassigned' }}</span>
                        </div>
                        <div class="pipeline-card__date" v-if="card.expected_closing_date">{{ card.expected_closing_date }}</div>
                    </Link>

                    <p v-if="!(cardsByStage[stage.id] ?? []).length" class="pipeline-column__empty">No opportunities here</p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
    .pipeline-board {
        display: flex;
        gap: 1rem;
        overflow-x: auto;
        padding-bottom: 1rem;
        align-items: flex-start;
    }

    .pipeline-column {
        flex: 0 0 280px;
        background: #f8fafc;
        border-radius: 0.5rem;
        border: 1px solid #e2e8f0;
        display: flex;
        flex-direction: column;
        max-height: calc(100vh - 220px);
    }

    .pipeline-column--over {
        outline: 2px dashed #199683;
        outline-offset: -2px;
        background: #f0fdfa;
    }

    .pipeline-column__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem;
        font-weight: 600;
        font-size: 0.875rem;
        border-top: 4px solid #199683;
        border-radius: 0.5rem 0.5rem 0 0;
        background: #fff;
    }

    .pipeline-column__total {
        padding: 0 0.75rem 0.5rem;
        font-size: 0.75rem;
        color: #64748b;
    }

    .pipeline-column__body {
        padding: 0.5rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        overflow-y: auto;
        flex: 1;
    }

    .pipeline-column__empty {
        font-size: 0.8rem;
        color: #94a3b8;
        text-align: center;
        padding: 1rem 0;
        margin: 0;
    }

    .pipeline-card {
        display: block;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.375rem;
        padding: 0.625rem 0.75rem;
        cursor: grab;
        text-decoration: none;
        color: inherit;
        transition: box-shadow 0.15s, opacity 0.15s;
    }

    .pipeline-card:hover {
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
    }

    .pipeline-card--dragging {
        opacity: 0.4;
    }

    .pipeline-card--moving {
        opacity: 0.6;
        pointer-events: none;
    }

    .pipeline-card__title {
        font-weight: 600;
        font-size: 0.85rem;
        margin-bottom: 0.25rem;
    }

    .pipeline-card__meta {
        display: flex;
        justify-content: space-between;
        font-size: 0.75rem;
        color: #64748b;
    }

    .pipeline-card__date {
        margin-top: 0.25rem;
        font-size: 0.7rem;
        color: #94a3b8;
    }
</style>
