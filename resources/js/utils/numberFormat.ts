/**
 * PHP number_format($value, $decimals) equivalent — comma thousands, dot decimals.
 */
export type NumberFormatConfig = {
    decimals: number;
    thousand: string;
    decimal: string;
    grouping: 'western' | 'indian';
    negative: 'minus' | 'brackets';
};

/** The company's number format (Business Settings); the default is the format the application always used. */
let numberFormat: NumberFormatConfig = { decimals: 2, thousand: ',', decimal: '.', grouping: 'western', negative: 'minus' };

export function setNumberFormat(config: Partial<NumberFormatConfig> | null | undefined): void {
    numberFormat = { ...numberFormat, ...(config ?? {}) } as NumberFormatConfig;
}

export function formatNumber(value: unknown, decimals = 2): string {
    const amount = parseNumericValue(value);

    if (amount === null) {
        return formatNumber(0, decimals);
    }

    const isDefault =
        numberFormat.thousand === ',' && numberFormat.decimal === '.' && numberFormat.grouping === 'western' && numberFormat.negative === 'minus' && numberFormat.decimals === 2;

    if (isDefault) {
        return amount.toLocaleString('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
    }

    // an amount shown with two decimals follows the company's choice; counts and quantities keep what the caller asked for
    const places = decimals === 2 ? numberFormat.decimals : decimals;
    const fixed = Math.abs(amount).toFixed(places);
    const [whole, fraction = ''] = fixed.split('.');
    const grouped =
        numberFormat.grouping === 'indian' && whole.length > 3
            ? whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, numberFormat.thousand) + numberFormat.thousand + whole.slice(-3)
            : whole.replace(/\B(?=(\d{3})+(?!\d))/g, numberFormat.thousand);
    const text = grouped + (fraction ? numberFormat.decimal + fraction : '');

    if (amount < 0 && Number(fixed) !== 0) {
        return numberFormat.negative === 'brackets' ? `(${text})` : `-${text}`;
    }

    return text;
}


export function parseNumericValue(value: unknown): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : null;
    }

    const normalized = String(value).replace(/,/g, '').trim();

    if (normalized === '' || normalized === '-') {
        return null;
    }

    const amount = Number(normalized);

    return Number.isFinite(amount) ? amount : null;
}

export function isNumericDisplayValue(value: unknown): boolean {
    return parseNumericValue(value) !== null;
}
