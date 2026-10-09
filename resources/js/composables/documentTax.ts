import { ref } from 'vue';
import { API_ENDPOINTS } from './apiEndpoints';

export type TaxOption = {
    id: number;
    name: string;
    percentage: number;
    type: number;
    compound: boolean;
    kind: string;
    applies_on: string;
    company_id: number | null;
    label: string;
};

export type TaxPreviewLine = { tax_id: number | null; product_id: number | null; amount: number };

export type TaxPreviewResult = {
    lines: Array<{ tax_id: number | null; tax_amount: number; net_amount: number; tax_exemption_id: number | null }>;
    tax_total: number;
    exclusive_tax: number;
};

/**
 * The taxes a sale or purchase form offers and the preview of what they come to. The figures are always worked out by the
 * server (the same TaxCalculator that saves the document), so exemptions, compound groups and inclusive prices read the same
 * in the form as on the saved document.
 */
export default function useDocumentTax() {
    const taxOptions = ref<TaxOption[]>([]);
    const withholdingOptions = ref<TaxOption[]>([]);
    let latest = 0;

    async function loadTaxOptions(companyId: unknown) {
        const id = Number(companyId);

        if (!id) {
            taxOptions.value = [];
            withholdingOptions.value = [];

            return;
        }

        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchTaxes, { params: { company_id: id, with_groups: 1 } });
            taxOptions.value = (response.data as Array<Omit<TaxOption, 'label'>>).map((tax) => ({
                ...tax,
                label: `${tax.name} (${tax.percentage}%${tax.type === 1 && tax.compound ? ', compound' : ''})`,
            }));
        } catch {
            taxOptions.value = [];
        }

        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchTaxes, { params: { company_id: id, kind: 'withholding' } });
            withholdingOptions.value = (response.data as Array<Omit<TaxOption, 'label'>>).map((tax) => ({
                ...tax,
                label: `${tax.name} (${tax.percentage}% of ${tax.applies_on === 'gross' ? 'the invoice total' : 'the value before sales tax'})`,
            }));
        } catch {
            withholdingOptions.value = [];
        }
    }

    /**
     * Resolves null when a newer preview was asked for meanwhile (or the call failed), so a slow answer never overwrites a
     * newer one.
     */
    async function preview(payload: {
        company_id: unknown;
        contact_id: unknown;
        date: unknown;
        inclusive: boolean;
        discount: number;
        field: 'selllines' | 'purchaselines';
        lines: TaxPreviewLine[];
    }): Promise<TaxPreviewResult | null> {
        const ticket = ++latest;

        try {
            const response = await window.axios.post(API_ENDPOINTS.taxPreview, payload);

            return ticket === latest ? (response.data as TaxPreviewResult) : null;
        } catch {
            return null;
        }
    }

    return { taxOptions, withholdingOptions, loadTaxOptions, preview };
}
