import { ref } from 'vue';

export type VoucherProject = { id: number; text: string };
export type VoucherCurrency = { id: number; text: string; rate: number };

/**
 * The optional pickers of the sale, purchase, expense and journal forms: the company's open projects and, only when it switched
 * multi-currency vouchers on, its foreign currencies with today's rate. Both lists are empty for a company that uses neither, so a
 * form that shows a picker only when its list is not empty looks exactly as before.
 */
export default function useVoucherOptions(companyId?: () => number | string | null | undefined) {
    const projects = ref<VoucherProject[]>([]);
    const currencies = ref<VoucherCurrency[]>([]);
    const multiCurrency = ref(false);

    async function load() {
        const company = companyId?.();

        try {
            const response = await window.axios.get('/api/voucher-options', { params: company ? { company_id: company } : {} });
            projects.value = response.data?.projects ?? [];
            currencies.value = response.data?.currencies ?? [];
            multiCurrency.value = Boolean(response.data?.multi_currency);
        } catch {
            projects.value = [];
            currencies.value = [];
            multiCurrency.value = false;
        }
    }

    return { projects, currencies, multiCurrency, load };
}
