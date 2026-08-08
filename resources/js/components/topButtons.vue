<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { Link, usePage } from '@inertiajs/vue3';

    const buttonProps = defineProps({
        state:Object,
        type: String,
        title: String,
        url: String,
        showFilter: {type: Boolean, default: true},
        showStatus: {type: Boolean, default: true},
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

    const { props } = usePage();

    const {
        formatedText,
    } = useCommons();
</script>

<template>

    <button type="button" class="btn btn-sm btn-icon btn-secondary btn-wave waves-effect waves-light me-1" data-bs-toggle="collapse" data-bs-target="#filterCollapse" v-if="buttonProps.showFilter === true">
        <i class="mdi mdi-filter-outline"></i>
    </button>

    <button
        type="button"
        class="btn btn-sm btn-icon btn-dark btn-wave waves-effect waves-light me-1"
        id="SearchBtn"
        v-if="!buttonProps.requireReloadPermission || (buttonProps.url && props.auth.user.permission_paths.includes(`/${buttonProps.url}/reload`))"
        @click="buttonProps.state?.loading === true ? '' : buttonProps.getData?.()"
    >
        <i class="mdi mdi-refresh" :class="(buttonProps.state?.loading === false)?'':'fa-spin'"></i>
    </button>

    <span v-if="buttonProps.type === 'trash'" class="d-inline-flex align-items-center flex-wrap gap-1">
        <button
            type="button"
            class="btn btn-sm btn-icon btn-success btn-wave waves-effect waves-light me-1"
            v-if="props.auth.user.permission_paths.includes(`/${buttonProps.url}/restore`) && buttonProps.state?.edit_ids.length > 0"
            @click="buttonProps.restoreBulk?.(buttonProps.state?.edit_ids)"
            :disabled="buttonProps.state?.loadingIds.size > 0"
            title="Restore selected"
        >
            <span class="spinner-border spinner-border-sm align-middle" role="status" aria-hidden="true" v-if="buttonProps.state?.loadingIds.size > 0"></span>
            <i class="mdi mdi-restore" v-else></i>
        </button>

        <button
            type="button"
            class="btn btn-sm btn-icon btn-danger btn-wave waves-effect waves-light me-1"
            v-if="props.auth.user.permission_paths.includes(`/${buttonProps.url}/delete`) && buttonProps.state?.edit_ids.length > 0"
            @click="buttonProps.permanentDeleteBulk?.(buttonProps.state?.edit_ids)"
            :disabled="buttonProps.state?.loadingIds.size > 0"
            title="Permanently delete selected"
        >
            <span class="spinner-border spinner-border-sm align-middle" role="status" aria-hidden="true" v-if="buttonProps.state?.loadingIds.size > 0"></span>
            <i class="mdi mdi-delete-forever" v-else></i>
        </button>

        <Link :href="`/${buttonProps.url}`" class="btn btn-sm btn-dark btn-wave waves-light waves-effect waves-light" v-if="props.auth.user.permission_paths.includes(`/${buttonProps.url}`)">
            <i class="mdi mdi-arrow-left"></i> Back {{ formatedText(props.routeName) }}
        </Link>
    </span>

    <span v-else>
        <button
            type="button"
            class="btn btn-sm btn-icon btn-danger btn-wave waves-effect waves-light me-1"
            v-if="props.auth.user.permission_paths.includes(`/${buttonProps.url}/delete`) && buttonProps.state?.edit_ids.length > 0"
            @click="buttonProps.deleteRecord?.(buttonProps.state?.edit_ids)"
            :disabled="buttonProps.state?.loadingIds.size > 0"
        >
            <span class="spinner-border spinner-border-sm align-middle" role="status" aria-hidden="true" v-if="buttonProps.state?.loadingIds.size > 0"></span>
            <i class="mdi mdi-delete-outline" v-else></i>
        </button>

        <button
            type="button"
            class="btn btn-sm btn-icon btn-success btn-wave waves-effect waves-light me-1"
            v-if="props.auth.user.permission_paths.includes(`/${buttonProps.url}/:id/edit`) && buttonProps.state?.edit_ids.length > 0 && buttonProps.state?.showStatus"
            @click="buttonProps.changeStatus?.(buttonProps.state?.edit_ids, 'true')"
            :disabled="buttonProps.state?.loadingIds.size > 0"
        >
            <span class="spinner-border spinner-border-sm align-middle" role="status" aria-hidden="true" v-if="buttonProps.state?.loadingIds.size > 0"></span>
            <i class="mdi mdi-eye-outline" v-else></i>
        </button>

        <button
            type="button"
            class="btn btn-sm btn-icon btn-warning btn-wave waves-effect waves-light me-1"
            v-if="props.auth.user.permission_paths.includes(`/${buttonProps.url}/:id/edit`) && buttonProps.state?.edit_ids.length > 0 && buttonProps.state?.showStatus"
            @click="buttonProps.changeStatus?.(buttonProps.state?.edit_ids, 'false')"
            :disabled="buttonProps.state?.loadingIds.size > 0"
        >
            <span class="spinner-border spinner-border-sm align-middle" role="status" aria-hidden="true" v-if="buttonProps.state?.loadingIds.size > 0"></span>
            <i class="mdi mdi-eye-off-outline" v-else></i>
        </button>

        <button type="button" class="btn btn-sm btn-primary btn-wave waves-light waves-effect waves-light me-1" data-bs-toggle="modal" data-bs-target="#AddModal" v-if="props.auth.user.permission_paths.includes(`/${buttonProps.url}/add`)">
            <i class="mdi mdi-plus align-middle me-1"></i> Add New
        </button>

        <Link :href="`${buttonProps.url}/trash`" class="btn btn-sm btn-danger btn-wave waves-light waves-effect waves-light" v-if="props.auth.user.permission_paths.includes(`/${buttonProps.url}/trash`)">
            <i class="mdi mdi-delete-outline me-0"></i> Trash {{ formatedText(props.routeName) }} ({{ buttonProps.state?.trash_count }})
        </Link>
    </span>


</template>
