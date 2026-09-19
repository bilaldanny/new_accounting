export type LabelProductRow = {
    id?: number | string;
    product_id: number | string;
    variation_id?: number | string;
    pro_name: string;
    var_name?: string;
    value?: string;
    sku: string;
    default_sell_price: number;
    sell_price_inc_tax: number;
    label: number | string;
};

export type LabelSettings = {
    business_name: boolean;
    product_name: boolean;
    product_variation: boolean;
    product_price: boolean;
    show_price: 'inclusive' | 'exclusive';
    barcode_setting: 1 | 2;
    barcode_type: string;
};
