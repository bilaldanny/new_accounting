<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import TheTable from '@/components/theTable.vue';
    import TopButtons from '@/components/topButtons.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useBlogPosts from '@/composables/blogpost';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import debounce from '@/utils/debounce';

    defineOptions({
        layout: {
            title: 'Blog posts',
            subtitle: 'Posts for the public blog',
            breadcrumbs: [
                { title: 'Blog posts', href: 'NULL' },
            ],
        },
    });

    const { props } = usePage();

    const { state, getBlogPosts, deleteRecord, changeOrder, checkAll } = useBlogPosts();
    const { select_data, formatedText } = useCommons();

    const columns = [
        { key: 'title', label: 'Title', type: 'primary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'status', label: 'Status', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'published_at', label: 'Published', type: 'secondary', responsive: ['md', 'lg'] },
        { key: 'created_at', label: 'Created', type: 'secondary', responsive: ['lg'] },
        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled', actions: ['edit', 'delete'] },
    ];
    const debouncedGetBlogPosts = debounce((params) => {
        getBlogPosts(params);
    }, 300);

    const getData = async () => {
        state.loading = true;
        await debouncedGetBlogPosts({ ...state.search });
    };

    onMounted(() => {
        debouncedGetBlogPosts({ ...state.search });
    });

    function onStateUpdate(newState) {
        Object.assign(state, newState);
    }

    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.blogPosts, () => state);
</script>

<template>
    <Head :title="formatedText(props.routeName)" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar">
                <TopButtons
                    :state="state"
                    :getData="getData"
                    :deleteRecord="deleteRecord"
                    :url="`${props.routeName?.split('.')[0]}`"
                    add-href="/blogposts/add"
                    :show-filter="false"
                    :show-import="false"
                />
            </div>

            <div class="admin-list-card__body">
                <div class="admin-list-table">
                    <TheTable
                        :columns="columns"
                        :selectData="select_data"
                        :state="state"
                        :checkAll="checkAll"
                        :getData="getData"
                        :changeOrder="changeOrder"
                        :delete="deleteRecord"
                        actionType="link"
                        :apiUrl="props.routeName?.split('.')[0]"
                        show-export
                        :export-file-name="String(props.routeName ?? 'export').replace(/\./g, '-')"
                        :export-title="formatedText(props.routeName)"
                        :export-all-rows="fetchAllRowsForExport"
                        @update:state="onStateUpdate"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
