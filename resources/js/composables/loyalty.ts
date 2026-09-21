import { reactive } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

export default function useLoyalty(){

    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const {Notify, select_data, changeOrderFn, checkAllFn, getData} = useCommons()

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
        sort_by: 'points_balance',
        sort_type: 'desc' as 'asc' | 'desc',
        show_record: 10,
        page: 1,
        search: '',
        status: 'all',
        company_id: '',
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

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getLoyalty = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.loyalty, data, state)
    };

    return{
        state,
        Notify,
        getLoyalty,
        changeOrder,
        checkAll,
        select_data
    }

}
