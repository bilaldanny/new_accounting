<script setup>
const props = defineProps({
    children: {
        type: Array,
        required: true,
    },
    parentName: {
        type: String,
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
    expanded: {
        type: Boolean,
        default: true,
    },
    accordion: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['check-subparent', 'check-subsubparent']);

const matchesQuery = (name) => {
    const query = props.searchQuery.trim().toLowerCase();

    if (!query) {
        return true;
    }

    const nameLower = String(name).toLowerCase();
    const parentLower = String(props.parentName).toLowerCase();

    return nameLower.includes(query) || parentLower.includes(query);
};

const isChildVisible = (name) => matchesQuery(name);
</script>

<template>
    <div
        class="permission-group"
        :class="{
            'permission-group--collapsed': !expanded,
            'permission-group--accordion': accordion,
        }"
    >
        <div class="permission-group__list">
            <div
                v-for="item1 in children"
                :key="item1.id"
                class="child1 permission-group__block"
            >
                <div
                    v-show="isChildVisible(item1.name)"
                    class="form-check my-subparent-list permission-group__row"
                >
                    <div class="permission-group__checkbox-wrap">
                        <input
                            class="form-check-input permission-group__checkbox permission-child-checkbox"
                            type="checkbox"
                            :id="'flexCheckDefault' + item1.id"
                            :checked="permissiondata.includes(item1.id)"
                            @click="emit('check-subparent', item1.id, $event)"
                        >
                        <div class="b-spinner spinner-border text-primary" role="status" />
                    </div>
                    <label
                        class="form-check-label permission-group__label"
                        :for="'flexCheckDefault' + item1.id"
                    >
                        {{ item1.name }}
                    </label>
                </div>

                <template v-if="item1.children?.length">
                    <div
                        v-for="item2 in item1.children"
                        :key="item2.id"
                        class="child2 permission-group__nested"
                    >
                        <div
                            v-show="isChildVisible(item2.name)"
                            class="form-check my-subsubparent-list permission-group__row permission-group__row--nested"
                        >
                            <div class="permission-group__checkbox-wrap">
                                <input
                                    class="form-check-input permission-group__checkbox permission-child-checkbox"
                                    type="checkbox"
                                    :id="'flexCheckDefault' + item2.id"
                                    :checked="permissiondata.includes(item2.id)"
                                    @click="emit('check-subsubparent', item2.id, $event)"
                                >
                                <div class="b-spinner spinner-border text-primary" role="status" />
                            </div>
                            <label
                                class="form-check-label permission-group__label"
                                :for="'flexCheckDefault' + item2.id"
                            >
                                {{ item2.name }}
                            </label>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
.permission-group {
    transition: opacity 0.2s ease;
}

.permission-group--collapsed {
    opacity: 0;
    pointer-events: none;
}

.permission-group--accordion {
    margin: 0;
    padding: 0;
    border: 0;
    max-height: none;
    overflow: visible;
}

.permission-group--accordion .permission-group__list {
    display: block;
    margin: 0;
    padding: 0.75rem 1rem 1rem;
    border-left: none;
    max-height: 280px;
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-width: thin;
    scrollbar-color: rgba(25, 150, 131, 0.35) transparent;
}

.permission-group__list {
    margin-left: 0.35rem;
    padding-left: 0.875rem;
    border-left: 2px solid rgba(25, 150, 131, 0.35);
    max-height: 340px;
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-width: thin;
    scrollbar-color: rgba(25, 150, 131, 0.35) transparent;
}

.permission-group__list::-webkit-scrollbar {
    width: 5px;
}

.permission-group__list::-webkit-scrollbar-thumb {
    background: rgba(25, 150, 131, 0.35);
    border-radius: 999px;
}

.permission-group__block + .permission-group__block {
    margin-top: 0.125rem;
}

.permission-group__nested {
    margin-left: 1.25rem;
    padding-left: 0.75rem;
    border-left: 2px solid rgba(25, 150, 131, 0.18);
}

.permission-group__row.form-check {
    position: relative;
    padding-left: 0;
    min-height: auto;
}

.permission-group__row {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 0.625rem;
    padding: 0.5rem 0.625rem;
    margin-bottom: 0;
    border-radius: 0.4375rem;
    transition: background-color 0.15s ease;
}

.permission-group--accordion .permission-group__row {
    padding: 0.4375rem 0.5rem;
}

.permission-group__row--nested {
    padding-left: 0.375rem;
}

.permission-group__block > .permission-group__row:not(.permission-group__row--nested) .permission-group__label {
    font-weight: 600;
    color: var(--text-main, #111827);
}

.permission-group__row--nested .permission-group__label {
    font-weight: 500;
    font-size: 0.8125rem;
}

.permission-group__row:hover {
    background: rgba(25, 150, 131, 0.06);
}

.permission-group__label {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-muted, #374151);
    cursor: pointer;
    margin-bottom: 0;
    line-height: 1.45;
    padding-left: 0;
}

.permission-group__checkbox-wrap {
    position: relative;
    flex-shrink: 0;
    width: 1.0625rem;
    height: 1.0625rem;
    margin-top: 0.15rem;
}

.permission-group__checkbox {
    width: 100%;
    height: 100%;
    margin: 0;
    float: none;
    position: static;
    cursor: pointer;
    border-radius: 0.25rem;
    border: 1.5px solid #cbd5e1;
    flex-shrink: 0;
    display: block;
}

.permission-group__checkbox:checked {
    background-color: var(--accent-dark, #199683);
    border-color: var(--accent-dark, #199683);
}

.permission-group__checkbox:focus {
    border-color: var(--accent-dark, #199683);
    box-shadow: 0 0 0 0.15rem rgba(25, 150, 131, 0.2);
}

.permission-group__checkbox:disabled {
    opacity: 0.65;
    cursor: wait;
}

.b-spinner {
    width: 1rem;
    height: 1rem;
    position: absolute;
    inset: 0;
    margin: auto;
    z-index: 9;
    display: none;
}

.pending .b-spinner {
    display: block;
}

.pending .permission-group__checkbox,
.pending .permission-group__checkbox:disabled {
    opacity: 0.35;
    cursor: wait;
}

.pending .permission-group__label {
    cursor: wait;
}

@media (max-width: 767.98px) {
    .permission-group--accordion .permission-group__list {
        grid-template-columns: 1fr;
        padding: 0.875rem 1rem 1rem;
    }
}
</style>
