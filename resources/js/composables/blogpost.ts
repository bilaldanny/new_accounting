import { reactive, ref } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

export default function useBlogPosts(){

    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const emptyForm = () => ({
      title: '',
      slug: '',
      excerpt: '',
      body: '',
      status: 'draft',
      published_at: '',
      meta_title: '',
      meta_description: '',
      cover_image: null as File | null,
      cover_url: '',
      remove_cover: false,
    });

    /** The form as multipart data: the cover is a file, the rest plain fields. */
    const toMultipart = (data: Record<string, unknown>): FormData => {
        const body = new FormData();

        Object.entries(data).forEach(([key, value]) => {
            if (value === null || value === undefined || key === 'cover_url') {
                return;
            }

            if (value instanceof File) {
                body.append(key, value);
            } else if (typeof value === 'boolean') {
                body.append(key, value ? '1' : '0');
            } else if (key !== 'cover_image') {
                body.append(key, String(value));
            }
        });

        return body;
    };

    const formData = ref(emptyForm());
    const defaultFormData = ref(emptyForm());

    const {Notify, select_data, fetchWithRetry, changeOrderFn, deleteFn, checkAllFn, getData, restoreFn} = useCommons()

    const state = reactive({
      records: {
        data: [],
        from: 0,
        to: 0,
        total: 0,
        last_page: 0,
        current_page: 1,
      },
      search: {
        sort_by: 'created_at',
        sort_type: 'desc' as 'asc' | 'desc',
        show_record: 10,
        page: 1,
        search: '',
        status: 'all',
      },
      loading: false,
      modalLoading: true,
      edit_ids: [],
      selectAll: false,
      trash_count: 0,
      loadingIds: new Set(),
    });

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.blogPosts+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.blogPosts+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getBlogPosts = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.blogPosts, data, state)
    };

    const getTrashBlogPosts = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.blogPosts+'/trash', data, state);
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.blogPosts}/${id}`);
            formData.value = {
                ...emptyForm(),
                ...response.data,
                // The stored cover is shown as a preview (cover_url); a new file is only sent when one is chosen.
                cover_image: null,
                remove_cover: false,
                published_at: response.data?.published_at ? String(response.data.published_at).slice(0, 16) : '',
            };

            return true;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                if(error.response?.data?.message !== 'Unauthenticated.'){
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } else {
                Notify('Unexpected error occurred', 'alert');
            }

            return false;
        }
    }

    const restoreBulkRecord = async (ids: Array<number>) => {
        return restoreFn(API_ENDPOINTS.blogPosts+'/restore_records', ids, state)
    }

    return{
        state,
        Notify,
        getBlogPosts,
        getTrashBlogPosts,
        getEditData,
        formData,
        defaultFormData,
        emptyForm,
        toMultipart,
        deleteRecord,
        perDeleteBulkRecord,
        restoreBulkRecord,
        changeOrder,
        checkAll,
        select_data
    }

}
