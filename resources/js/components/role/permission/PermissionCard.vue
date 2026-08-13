<script setup>
import { computed, ref } from 'vue';
import { ChevronDown, SelectAll, SelectNone } from '@boxicons/vue';
import MenuIcon from '@/components/MenuIcon.vue';
import PermissionGroup from './PermissionGroup.vue';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    permissiondata: {
        type: Array,
        required: true,
    },
    searchQuery: {
        type: String,
        default: '',
    },
});

const emit = defineEmits([
    'check-subparent',
    'check-subsubparent',
]);

const isExpanded = ref(true);

const hasChildren = computed(() => (props.item.children?.length ?? 0) > 0);

const collectPermissionIds = (menuItem) => {
    const ids = [];
    for (const child of menuItem.children ?? []) {
        ids.push(child.id);
        for (const grandchild of child.children ?? []) {
            ids.push(grandchild.id);
        }
    }
    return ids;
};

const moduleStats = computed(() => {
    const ids = collectPermissionIds(props.item);
    const granted = ids.filter((id) => props.permissiondata.includes(id)).length;
    return { granted, total: ids.length };
});

const progressPercent = computed(() => {
    if (moduleStats.value.total === 0) {
        return 0;
    }
    return Math.round((moduleStats.value.granted / moduleStats.value.total) * 100);
});

const isFullyGranted = computed(() =>
    moduleStats.value.total > 0 && moduleStats.value.granted === moduleStats.value.total,
);

const allChildrenGranted = computed(() => {
    if (!hasChildren.value) {
        return false;
    }
    const childIds = [];
    for (const child of props.item.children ?? []) {
        childIds.push(child.id);
        for (const grandchild of child.children ?? []) {
            childIds.push(grandchild.id);
        }
    }
    return childIds.length > 0 && childIds.every((id) => props.permissiondata.includes(id));
});

const accentColor = computed(() => props.item.menu_color || '#199683');

const toggleExpanded = () => {
    if (hasChildren.value) {
        isExpanded.value = !isExpanded.value;
    }
};

const toggleSelectAll = (event) => {
    event.stopPropagation();
    const card = event.currentTarget.closest('.permission-accordion');
    if (!card) {
        return;
    }

    const inputs = card.querySelectorAll(
        '.my-subparent-list .form-check-input, .my-subsubparent-list .form-check-input',
    );
    const shouldGrant = !allChildrenGranted.value;

    inputs.forEach((input) => {
        if (input.checked !== shouldGrant) {
            input.click();
        }
    });
};

</script>

<template>
    <div
        class="permission-accordion my-parent-list"
        :class="{
            'permission-accordion--expanded': isExpanded,
            'permission-accordion--complete': isFullyGranted,
            'permission-accordion--simple': !hasChildren,
        }"
        :style="{ '--module-accent': accentColor }"
    >
        <div
            class="permission-accordion__header"
            :class="{ 'permission-accordion__header--clickable': hasChildren }"
            :role="hasChildren ? 'button' : undefined"
            :tabindex="hasChildren ? 0 : undefined"
            :aria-expanded="hasChildren ? isExpanded : undefined"
            @click="toggleExpanded"
            @keydown.enter.prevent="toggleExpanded"
            @keydown.space.prevent="toggleExpanded"
        >
            <div class="permission-accordion__header-start">
                <div class="permission-accordion__icon-wrap">
                    <MenuIcon :icon="item.icon" />
                </div>

                <h3 class="permission-accordion__title">
                    {{ item.name }}
                </h3>

                <div v-if="hasChildren" class="permission-accordion__meta">
                    <div class="permission-accordion__progress-track">
                        <div
                            class="permission-accordion__progress-fill"
                            :style="{ width: `${progressPercent}%` }"
                        />
                    </div>
                    <span class="permission-accordion__count">
                        {{ moduleStats.granted }}/{{ moduleStats.total }}
                    </span>
                </div>
            </div>

            <div class="permission-accordion__header-end">
                <button
                    v-if="hasChildren"
                    type="button"
                    class="btn btn-sm permission-accordion__select-all"
                    @click="toggleSelectAll"
                >
                    <SelectAll v-if="!allChildrenGranted" size="sm" class="permission-accordion__action-icon" />
                    <SelectNone v-else size="sm" class="permission-accordion__action-icon" />
                    {{ allChildrenGranted ? 'Clear all' : 'Select all' }}
                </button>

                <span
                    v-if="hasChildren"
                    class="permission-accordion__chevron-wrap"
                    aria-hidden="true"
                >
                    <ChevronDown
                        size="sm"
                        class="permission-accordion__chevron"
                        :class="{ 'permission-accordion__chevron--collapsed': !isExpanded }"
                    />
                </span>
            </div>
        </div>

        <div
            v-if="hasChildren"
            class="permission-accordion__panel"
            :class="{ 'permission-accordion__panel--open': isExpanded }"
        >
            <div class="permission-accordion__panel-inner">
                <PermissionGroup
                    :children="item.children"
                    :parent-name="item.name"
                    :permissiondata="permissiondata"
                    :search-query="searchQuery"
                    :expanded="isExpanded"
                    accordion
                    @check-subparent="(id, $event) => emit('check-subparent', id, $event)"
                    @check-subsubparent="(id, $event) => emit('check-subsubparent', id, $event)"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.permission-accordion {
    background: #fff;
    border: 1px solid #e8ecf0;
    border-radius: 0.875rem;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 4px 12px rgba(15, 23, 42, 0.03);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    height: 100%;
}

.permission-accordion:hover {
    border-color: rgba(25, 150, 131, 0.28);
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08);
}

.permission-accordion--expanded {
    border-color: rgba(25, 150, 131, 0.35);
}

.permission-accordion--complete {
    border-color: rgba(25, 150, 131, 0.4);
}

.permission-accordion__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.625rem;
    padding: 0.75rem 1rem;
    background: #fff;
    border-left: 4px solid var(--module-accent, #199683);
    transition: background-color 0.2s ease;
}

.permission-accordion__header--clickable {
    cursor: pointer;
}

.permission-accordion__header--clickable:hover {
    background: #f8fafc;
}

.permission-accordion--expanded .permission-accordion__header {
    background: linear-gradient(90deg, rgba(25, 150, 131, 0.05) 0%, #fff 100%);
    border-bottom: 1px solid #eef2f6;
}

.permission-accordion__header-start {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    flex: 1;
    min-width: 0;
}

.permission-accordion__icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    background: color-mix(in srgb, var(--module-accent, #199683) 12%, white);
    color: var(--module-accent, #199683);
    flex-shrink: 0;
}

.permission-accordion__icon-wrap :deep(.menu-boxicon) {
    width: 1.125rem;
    height: 1.125rem;
}

.permission-accordion__title {
    font-weight: 600;
    font-size: 0.8125rem;
    color: var(--text-main, #111827);
    margin: 0;
    line-height: 1.2;
    white-space: nowrap;
    flex-shrink: 0;
}

.permission-accordion__meta {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    flex: 1;
    min-width: 0;
    margin-left: 0.125rem;
}

.permission-accordion__progress-track {
    flex: 1;
    min-width: 2rem;
    max-width: 4.5rem;
    height: 4px;
    border-radius: 999px;
    background: #eef2f6;
    overflow: hidden;
}

.permission-accordion__progress-fill {
    height: 100%;
    border-radius: inherit;
    background: var(--module-accent, #199683);
    transition: width 0.3s ease;
}

.permission-accordion__count {
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--text-muted, #6b7280);
    white-space: nowrap;
    flex-shrink: 0;
}

.permission-accordion__header-end {
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 0.375rem;
    flex-shrink: 0;
}

.permission-accordion__select-all {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
    font-size: 0.625rem;
    font-weight: 600;
    padding: 0.2rem 0.4rem;
    border-radius: 0.4375rem;
    color: var(--accent-dark, #199683);
    background: rgba(25, 150, 131, 0.08);
    border: 1px solid rgba(25, 150, 131, 0.22);
    white-space: nowrap;
}

.permission-accordion__select-all:hover {
    color: #fff;
    background: var(--accent-dark, #199683);
    border-color: var(--accent-dark, #199683);
}

.permission-accordion__action-icon {
    width: 0.875rem;
    height: 0.875rem;
    fill: currentColor;
    flex-shrink: 0;
}

.permission-accordion__chevron-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.625rem;
    height: 1.625rem;
    border-radius: 0.4375rem;
    background: #f3f4f6;
    color: #6b7280;
    flex-shrink: 0;
}

.permission-accordion__chevron {
    width: 1rem;
    height: 1rem;
    fill: currentColor;
    transition: transform 0.25s ease;
}

.permission-accordion__chevron--collapsed {
    transform: rotate(-90deg);
}

.permission-accordion__panel {
    display: grid;
    grid-template-rows: 0fr;
    transition: grid-template-rows 0.3s ease;
}

.permission-accordion__panel--open {
    grid-template-rows: 1fr;
}

.permission-accordion__panel-inner {
    overflow: hidden;
}

.permission-accordion__panel--open .permission-accordion__panel-inner {
    overflow: visible;
}

@media (max-width: 767.98px) {
    .permission-accordion__header {
        flex-wrap: nowrap;
        padding: 0.625rem 0.75rem;
    }

    .permission-accordion__meta {
        display: none;
    }
}
</style>
