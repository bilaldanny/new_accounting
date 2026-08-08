<script setup lang="ts">
import useCommons from '@/composables/common';
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    EyeAlt,
    EyeClosed,
    Filter,
    Plus,
    RefreshCw,
    TrashAlt,
    TrashX,
    UndoAlt,
} from '@boxicons/vue';

const buttonProps = defineProps({
    state: Object,
    type: String,
    title: String,
    url: String,
    showFilter: { type: Boolean, default: true },
    filterOpen: { type: Boolean, default: false },
    showStatus: { type: Boolean, default: true },
    /** When true, refresh (reload data) is only shown if user has `/${url}/reload` in permission_paths */
    requireReloadPermission: { type: Boolean, default: false },
    getData: Function,
    changeStatus: Function,
    deleteRecord: Function,
    /** Trash page: bulk restore selected rows */
    restoreBulk: Function,
    /** Trash page: bulk permanent delete selected rows */
    permanentDeleteBulk: Function,
});

const emit = defineEmits<{
    toggleFilter: [];
}>();

const { props } = usePage();

const { formatedText } = useCommons();
</script>

<template>
    <div class="top-buttons modern-toolbar d-inline-flex align-items-center flex-wrap gap-1">
        <button
            type="button"
            class="btn btn-sm btn-outline-secondary top-btn top-btn-icon-only"
            :class="{ active: buttonProps.filterOpen }"
            aria-controls="filterCollapse"
            :aria-expanded="buttonProps.filterOpen ? 'true' : 'false'"
            title="Filter"
            v-if="buttonProps.showFilter === true"
            @click="emit('toggleFilter')"
        >
            <Filter size="sm" class="top-btn-icon" />
        </button>

        <button
            type="button"
            class="btn btn-sm btn-outline-dark top-btn top-btn-icon-only"
            id="SearchBtn"
            title="Refresh"
            v-if="
                !buttonProps.requireReloadPermission ||
                (buttonProps.url &&
                    props.auth.user.permission_paths.includes(`/${buttonProps.url}/reload`))
            "
            @click="buttonProps.state?.loading === true ? '' : buttonProps.getData?.()"
        >
            <RefreshCw
                size="sm"
                class="top-btn-icon"
                :class="{ 'top-btn-icon-spin': buttonProps.state?.loading !== false }"
            />
        </button>

        <template v-if="buttonProps.type === 'trash'">
            <button
                type="button"
                class="btn btn-sm btn-success top-btn top-btn-icon-only"
                v-if="
                    props.auth.user.permission_paths.includes(`/${buttonProps.url}/restore`) &&
                    buttonProps.state?.edit_ids.length > 0
                "
                @click="buttonProps.restoreBulk?.(buttonProps.state?.edit_ids)"
                :disabled="buttonProps.state?.loadingIds.size > 0"
                title="Restore selected"
            >
                <span
                    class="spinner-border spinner-border-sm"
                    role="status"
                    aria-hidden="true"
                    v-if="buttonProps.state?.loadingIds.size > 0"
                ></span>
                <UndoAlt v-else size="sm" class="top-btn-icon" />
            </button>

            <button
                type="button"
                class="btn btn-sm btn-danger top-btn top-btn-icon-only"
                v-if="
                    props.auth.user.permission_paths.includes(`/${buttonProps.url}/delete`) &&
                    buttonProps.state?.edit_ids.length > 0
                "
                @click="buttonProps.permanentDeleteBulk?.(buttonProps.state?.edit_ids)"
                :disabled="buttonProps.state?.loadingIds.size > 0"
                title="Permanently delete selected"
            >
                <span
                    class="spinner-border spinner-border-sm"
                    role="status"
                    aria-hidden="true"
                    v-if="buttonProps.state?.loadingIds.size > 0"
                ></span>
                <TrashX v-else size="sm" class="top-btn-icon" />
            </button>

            <Link
                :href="`/${buttonProps.url}`"
                class="btn btn-sm btn-dark top-btn"
                v-if="props.auth.user.permission_paths.includes(`/${buttonProps.url}`)"
            >
                <ArrowLeft size="sm" class="top-btn-icon top-btn-icon-inline" />
                Back {{ formatedText(props.routeName) }}
            </Link>
        </template>

        <template v-else>
            <button
                type="button"
                class="btn btn-sm btn-danger top-btn top-btn-icon-only"
                v-if="
                    props.auth.user.permission_paths.includes(`/${buttonProps.url}/delete`) &&
                    buttonProps.state?.edit_ids.length > 0
                "
                @click="buttonProps.deleteRecord?.(buttonProps.state?.edit_ids)"
                :disabled="buttonProps.state?.loadingIds.size > 0"
            >
                <span
                    class="spinner-border spinner-border-sm"
                    role="status"
                    aria-hidden="true"
                    v-if="buttonProps.state?.loadingIds.size > 0"
                ></span>
                <TrashAlt v-else size="sm" class="top-btn-icon" />
            </button>

            <button
                type="button"
                class="btn btn-sm btn-success top-btn top-btn-icon-only"
                v-if="
                    props.auth.user.permission_paths.includes(`/${buttonProps.url}/:id/edit`) &&
                    buttonProps.state?.edit_ids.length > 0 &&
                    buttonProps.showStatus
                "
                @click="buttonProps.changeStatus?.(buttonProps.state?.edit_ids, 'true')"
                :disabled="buttonProps.state?.loadingIds.size > 0"
            >
                <span
                    class="spinner-border spinner-border-sm"
                    role="status"
                    aria-hidden="true"
                    v-if="buttonProps.state?.loadingIds.size > 0"
                ></span>
                <EyeAlt v-else size="sm" class="top-btn-icon" />
            </button>

            <button
                type="button"
                class="btn btn-sm btn-warning top-btn top-btn-icon-only"
                v-if="
                    props.auth.user.permission_paths.includes(`/${buttonProps.url}/:id/edit`) &&
                    buttonProps.state?.edit_ids.length > 0 &&
                    buttonProps.showStatus
                "
                @click="buttonProps.changeStatus?.(buttonProps.state?.edit_ids, 'false')"
                :disabled="buttonProps.state?.loadingIds.size > 0"
            >
                <span
                    class="spinner-border spinner-border-sm"
                    role="status"
                    aria-hidden="true"
                    v-if="buttonProps.state?.loadingIds.size > 0"
                ></span>
                <EyeClosed v-else size="sm" class="top-btn-icon" />
            </button>

            <button
                type="button"
                class="btn btn-sm btn-primary top-btn"
                data-bs-toggle="modal"
                data-bs-target="#AddModal"
                v-if="props.auth.user.permission_paths.includes(`/${buttonProps.url}/add`)"
            >
                <Plus size="sm" class="top-btn-icon top-btn-icon-inline" />
                Add New
            </button>

            <Link
                :href="`${buttonProps.url}/trash`"
                class="btn btn-sm btn-danger top-btn"
                v-if="props.auth.user.permission_paths.includes(`/${buttonProps.url}/trash`)"
            >
                <TrashAlt size="sm" class="top-btn-icon top-btn-icon-inline" />
                Trash {{ formatedText(props.routeName) }} ({{ buttonProps.state?.trash_count }})
            </Link>
        </template>
    </div>
</template>

<style scoped>
.top-buttons :deep(.top-btn-icon) {
    width: 1rem;
    height: 1rem;
    display: block;
    fill: currentColor;
    flex-shrink: 0;
}

.top-buttons :deep(.top-btn-icon-inline) {
    display: inline-block;
    vertical-align: middle;
}

.top-buttons :deep(.top-btn-icon-spin) {
    animation: top-btn-icon-spin 0.75s linear infinite;
    transform-origin: center;
}

@keyframes top-btn-icon-spin {
    from {
        transform: rotate(0deg);
    }

    to {
        transform: rotate(360deg);
    }
}
</style>
