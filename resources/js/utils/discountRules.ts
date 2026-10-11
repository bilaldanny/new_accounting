export type DiscountRule = {
    id: number;
    name: string;
    scope: string;
    discount_type: 'percent' | 'fixed';
    value: number;
    min_quantity: number | null;
};

/**
 * The per-unit discount the best standing rule gives at this price and quantity. The server lists a product's rules narrowest
 * scope first, so the first one whose minimum quantity is met wins, as the Product Discount Rules page describes.
 */
export function ruleDiscount(rules: DiscountRule[] | undefined, unitPrice: number, quantity: number): { amount: number; rule: DiscountRule | null } {
    for (const rule of rules ?? []) {
        if (rule.min_quantity !== null && quantity < Number(rule.min_quantity)) {
            continue;
        }

        const amount = rule.discount_type === 'percent' ? (unitPrice * Number(rule.value)) / 100 : Number(rule.value);

        return { amount: Number(Math.min(Math.max(amount, 0), unitPrice).toFixed(2)), rule };
    }

    return { amount: 0, rule: null };
}
