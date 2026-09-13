<script setup lang="ts">
    import type { ChartOfAccountNode } from '@/composables/chartOfAccount';
    import { formatNumber } from '@/utils/numberFormat';
    import { ChevronDown, ChevronRight, Pencil, Plus } from '@lucide/vue';
    import ChildrenTree from './ChildrenTree.vue';

    const props = withDefaults(
        defineProps<{
            nodes: ChartOfAccountNode[];
            level?: number;
            collapsedIds?: number[];
        }>(),
        {
            level: 1,
            collapsedIds: () => [],
        },
    );

    const emit = defineEmits<{
        add: [parentId: number];
        edit: [id: number];
        toggle: [id: number];
    }>();

    function isSystemRootAccount(node: ChartOfAccountNode): boolean {
        const parentId = node.parent_id;

        return parentId === null || parentId === undefined || parentId === '';
    }

    function isCollapsed(id: number): boolean {
        return props.collapsedIds.includes(id);
    }

    function indentClass(level: number): string {
        if (level <= 1) {
            return '';
        }

        if (level === 2) {
            return 'pl-8';
        }

        if (level === 3) {
            return 'pl-16';
        }

        return 'pl-24';
    }
</script>

<template>
    <template v-for="node in nodes" :key="node.id">
        <tr
            class="coa-tree-row group transition-colors"
            :class="[
                level === 1
                    ? 'border-t border-slate-200/90 bg-slate-50/70 font-semibold'
                    : 'bg-white hover:bg-teal-50/20',
                { 'opacity-70': node.active === false },
            ]"
        >
            <td class="px-4 py-3 whitespace-nowrap">
                <span
                    class="inline-block rounded px-2 py-0.5 font-mono text-xs font-bold"
                    :class="level === 1 ? 'bg-slate-200/80 text-slate-900' : 'bg-slate-100 text-teal-800'"
                >
                    {{ node.code || '—' }}
                </span>
            </td>
            <td class="px-4 py-3">
                <div class="flex items-center gap-2" :class="indentClass(level)">
                    <span v-if="level > 1" class="mr-1 select-none font-mono text-sm leading-none text-slate-300">└─</span>
                    <button
                        v-if="node.children?.length"
                        type="button"
                        class="-ml-1 cursor-pointer rounded p-1 text-slate-400 transition-colors hover:bg-slate-200/60 hover:text-slate-700"
                        :title="isCollapsed(node.id) ? 'Expand branch' : 'Collapse branch'"
                        @click="emit('toggle', node.id)"
                    >
                        <ChevronRight v-if="isCollapsed(node.id)" class="h-3.5 w-3.5" />
                        <ChevronDown v-else class="h-3.5 w-3.5" />
                    </button>
                    <span v-else class="w-5"></span>
                    <span
                        class="tracking-tight"
                        :class="{
                            'text-sm font-bold text-slate-900': level === 1,
                            'text-xs font-bold text-slate-800': level === 2,
                            'text-xs font-semibold text-slate-800': level === 3,
                            'text-xs font-medium text-slate-700': level >= 4,
                        }"
                    >
                        {{ node.name }}
                    </span>
                    <span
                        class="rounded px-1.5 py-0.5 text-[9px] font-extrabold tracking-wide uppercase"
                        :class="node.acc_type === 'c'
                            ? 'border border-indigo-200/80 bg-indigo-50 text-indigo-700'
                            : 'border border-emerald-200/80 bg-emerald-50 text-emerald-700'"
                    >
                        {{ node.acc_type === 'c' ? 'Control' : 'Transactional' }}
                    </span>
                    <span
                        v-if="node.active === false"
                        class="rounded border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-[9px] font-extrabold tracking-wide text-slate-500 uppercase"
                    >
                        Inactive
                    </span>
                </div>
            </td>
            <td class="px-4 py-3 text-right font-mono text-xs font-semibold whitespace-nowrap text-slate-800">
                {{ formatNumber(node.opening_balance) }}
            </td>
            <td class="px-2.5 py-2.5 text-right whitespace-nowrap">
                <div class="flex items-center justify-end gap-1.5">
                    <button
                        v-if="node.acc_type === 'c'"
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-teal-200/80 bg-teal-50/70 px-2.5 py-1 text-[11px] font-semibold text-teal-700 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-all hover:bg-teal-100/80 hover:text-teal-800"
                        data-bs-toggle="modal"
                        data-bs-target="#AddModal"
                        :data-parent-id="node.id"
                        title="Add child account"
                        @click.capture="emit('add', node.id)"
                    >
                        <Plus class="h-3 w-3 text-teal-600" />
                        <span>Add account</span>
                    </button>
                    <button
                        v-if="! isSystemRootAccount(node)"
                        type="button"
                        class="cursor-pointer rounded-md border border-amber-200/80 bg-amber-50/80 p-1.5 text-amber-700 transition-colors hover:bg-amber-100/80 hover:text-amber-800"
                        data-bs-toggle="modal"
                        data-bs-target="#EditModal"
                        title="Edit account details"
                        aria-label="Edit account"
                        @click="emit('edit', node.id)"
                    >
                        <Pencil class="h-3 w-3" />
                    </button>
                </div>
            </td>
        </tr>
        <ChildrenTree
            v-if="node.children?.length && ! isCollapsed(node.id)"
            :nodes="node.children"
            :level="level + 1"
            :collapsed-ids="collapsedIds"
            @add="emit('add', $event)"
            @edit="emit('edit', $event)"
            @toggle="emit('toggle', $event)"
        />
    </template>
</template>
