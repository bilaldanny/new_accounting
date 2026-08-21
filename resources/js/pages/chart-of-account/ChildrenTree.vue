<script setup lang="ts">
    import type { ChartOfAccountNode } from '@/composables/chartOfAccount';
    import { formatNumber } from '@/utils/numberFormat';
    import { Edit, Plus } from '@boxicons/vue';
    import ChildrenTree from './ChildrenTree.vue';

    withDefaults(
        defineProps<{
            nodes: ChartOfAccountNode[];
            leftSpacing?: number;
            nested?: boolean;
        }>(),
        {
            leftSpacing: 0,
            nested: false,
        },
    );

    const emit = defineEmits<{
        add: [parentId: number];
        edit: [id: number];
    }>();

    function isSystemRootAccount(node: ChartOfAccountNode): boolean {
        const parentId = node.parent_id;

        return parentId === null || parentId === undefined || parentId === '';
    }
</script>

<template>
    <div class="coa-tree" :class="{ 'coa-tree--nested': nested }">
        <div
            v-for="node in nodes"
            :key="node.id"
            class="coa-tree__node"
        >
            <div
                class="coa-tree__row"
                :class="{
                    'coa-tree__row--control': node.acc_type === 'c',
                    'coa-tree__row--inactive': node.active === false,
                }"
            >
                <div class="coa-tree__cell coa-tree__cell--code">
                    <span
                        class="coa-code"
                        :style="{ marginLeft: `${leftSpacing}px` }"
                        :title="node.code"
                    >
                        {{ node.code || '—' }}
                    </span>
                </div>

                <div class="coa-tree__cell coa-tree__cell--name">
                    <div class="coa-tree__name-wrap">
                        <span class="coa-tree__name">{{ node.name }}</span>
                        <span
                            class="coa-badge"
                            :class="node.acc_type === 'c' ? 'coa-badge--control' : 'coa-badge--transaction'"
                        >
                            {{ node.acc_type === 'c' ? 'Control' : 'Transactional' }}
                        </span>
                        <span v-if="node.active === false" class="coa-badge coa-badge--inactive">
                            Inactive
                        </span>
                    </div>
                </div>

                <div class="coa-tree__cell coa-tree__cell--balance">
                    <span class="coa-balance">{{ formatNumber(node.opening_balance) }}</span>
                </div>

                <div class="coa-tree__cell coa-tree__cell--actions">
                    <div class="coa-tree__actions">
                        <button
                            v-if="node.acc_type === 'c'"
                            type="button"
                            class="btn coa-action-btn coa-action-btn--add"
                            data-bs-toggle="modal"
                            data-bs-target="#AddModal"
                            :data-parent-id="node.id"
                            title="Add child account"
                            @click.capture="emit('add', node.id)"
                        >
                            <Plus size="sm" class="coa-action-btn__icon" aria-hidden="true" />
                            <span>Add account</span>
                        </button>
                        <button
                            v-if="! isSystemRootAccount(node)"
                            type="button"
                            class="btn coa-action-btn coa-action-btn--edit"
                            data-bs-toggle="modal"
                            data-bs-target="#EditModal"
                            title="Edit account"
                            aria-label="Edit account"
                            @click="emit('edit', node.id)"
                        >
                            <Edit size="sm" class="coa-action-btn__icon" aria-hidden="true" />
                        </button>
                    </div>
                </div>
            </div>

            <ChildrenTree
                v-if="node.children?.length"
                :nodes="node.children"
                :left-spacing="leftSpacing + 12"
                nested
                @add="emit('add', $event)"
                @edit="emit('edit', $event)"
            />
        </div>
    </div>
</template>
