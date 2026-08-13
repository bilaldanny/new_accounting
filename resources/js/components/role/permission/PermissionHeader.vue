<script setup>
import PermissionStats from './PermissionStats.vue';

const search = defineModel('search', { type: String, default: '' });

defineProps({
    totalModules: {
        type: Number,
        required: true,
    },
    grantedCount: {
        type: Number,
        required: true,
    },
    totalPermissions: {
        type: Number,
        required: true,
    },
    coveragePercent: {
        type: Number,
        required: true,
    },
});
</script>

<template>
    <div class="permission-header">
        <div class="permission-header__search-wrap">
            <label class="permission-header__search-label" for="permission-search">
                <i class="mdi mdi-magnify" aria-hidden="true" />
                Search modules & permissions
            </label>
            <div class="permission-header__field">
                <input
                    id="permission-search"
                    v-model="search"
                    type="search"
                    class="form-control permission-header__input"
                    placeholder="Type to filter modules, menus, or actions..."
                    autocomplete="off"
                    aria-label="Search permissions"
                >
                <button
                    v-if="search"
                    type="button"
                    class="permission-header__clear btn btn-link"
                    aria-label="Clear search"
                    @click="search = ''"
                >
                    <i class="mdi mdi-close-circle" aria-hidden="true" />
                </button>
            </div>
        </div>

        <PermissionStats
            :total-modules="totalModules"
            :granted-count="grantedCount"
            :total-permissions="totalPermissions"
            :coverage-percent="coveragePercent"
        />
    </div>
</template>

<style scoped>
.permission-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 1rem 1.5rem;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid rgba(226, 227, 231, 0.9);
}

.permission-header__search-wrap {
    flex: 1 1 280px;
    min-width: 0;
}

.permission-header__search-label {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted, #6b7280);
    margin-bottom: 0.5rem;
}

.permission-header__search-label .mdi {
    font-size: 0.875rem;
    color: var(--accent-dark, #199683);
}

.permission-header__field {
    position: relative;
}

.permission-header__input {
    min-height: 2.625rem;
    padding-right: 2.5rem;
    border-radius: 0.625rem;
    border: 1px solid #e5e7eb;
    font-size: 0.875rem;
    background: #fff;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
}

.permission-header__input:focus {
    border-color: var(--accent-dark, #199683);
    box-shadow: 0 0 0 3px rgba(25, 150, 131, 0.12);
}

.permission-header__clear {
    position: absolute;
    right: 0.5rem;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    padding: 0;
    line-height: 1;
    font-size: 1.125rem;
    text-decoration: none;
}

.permission-header__clear:hover {
    color: var(--accent-dark, #199683);
}

@media (max-width: 767.98px) {
    .permission-header {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>
