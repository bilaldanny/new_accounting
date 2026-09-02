import { ref } from 'vue';
import useCommons from './common';
import { API_ENDPOINTS } from './apiEndpoints';

export type ActiveFinancialYear = {
    start_date: string;
    end_date: string;
};

function toDateInput(value: unknown): string {
    return String(value ?? '').slice(0, 10);
}

export default function useActiveFinancialYear() {
    const { fetchWithRetry, Notify } = useCommons();

    const fiscalYear = ref<ActiveFinancialYear | null>(null);

    const fetchActiveFinancialYear = async (
        companyId: string | number | null | undefined,
    ): Promise<ActiveFinancialYear | null> => {
        if (! companyId) {
            fiscalYear.value = null;

            return null;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.fetchFinancialYears, {
                params: {
                    company_id: companyId,
                },
            });

            const years = Array.isArray(response.data) ? response.data : [];
            const active = years[0] as { start_date?: string; end_date?: string } | undefined;

            if (! active?.start_date || ! active?.end_date) {
                fiscalYear.value = null;

                return null;
            }

            fiscalYear.value = {
                start_date: toDateInput(active.start_date),
                end_date: toDateInput(active.end_date),
            };

            return fiscalYear.value;
        } catch (error: unknown) {
            fiscalYear.value = null;

            if (window.axios.isAxiosError(error) && error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'Unable to load financial year', 'alert');
            }

            return null;
        }
    };

    const clampToFiscalYear = (date: string): string => {
        if (! fiscalYear.value || ! date) {
            return date;
        }

        if (date < fiscalYear.value.start_date) {
            return fiscalYear.value.start_date;
        }

        if (date > fiscalYear.value.end_date) {
            return fiscalYear.value.end_date;
        }

        return date;
    };

    return {
        fiscalYear,
        fetchActiveFinancialYear,
        clampToFiscalYear,
    };
}
