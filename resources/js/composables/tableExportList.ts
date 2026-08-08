/**
 * Fetch all rows for table export using the same list endpoint and `state.search`
 * params as normal pagination (matches Laravel paginator responses used by `getData`).
 */
export const TABLE_EXPORT_PAGE_SIZE = 500;

export type TableExportStateLike = {
    records: { total: number };
    search: Record<string, unknown>;
};

export function createTableExportAllRows(
    listApiUrl: string,
    getState: () => TableExportStateLike,
    mapRows?: (rows: Record<string, unknown>[]) => Record<string, unknown>[],
): () => Promise<Record<string, unknown>[]> {
    return async () => {
        const state = getState();
        const total = Number(state.records.total) || 0;
        if (total === 0) {
            return [];
        }

        const axios = (window as any).axios;
        const search = state.search;

        const fetchPaginator = async (curPage: number, showRecord: number) => {
            const params = { ...search, show_record: showRecord, cur_page: curPage };
            const { data: body } = await axios.get(listApiUrl, { params });
            return body?.data ?? null;
        };

        let rows: Record<string, unknown>[] = [];

        if (total <= TABLE_EXPORT_PAGE_SIZE) {
            const paginator = await fetchPaginator(1, total);
            rows = (paginator?.data ?? []) as Record<string, unknown>[];
        } else {
            const first = await fetchPaginator(1, TABLE_EXPORT_PAGE_SIZE);
            const lastPage = Number(first?.last_page) || 1;
            rows = [...((first?.data ?? []) as Record<string, unknown>[])];
            for (let p = 2; p <= lastPage; p++) {
                const paginator = await fetchPaginator(p, TABLE_EXPORT_PAGE_SIZE);
                rows.push(...((paginator?.data ?? []) as Record<string, unknown>[]));
            }
        }

        return mapRows ? mapRows(rows) : rows;
    };
}
