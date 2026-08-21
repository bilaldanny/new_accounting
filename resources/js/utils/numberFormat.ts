/**
 * PHP number_format($value, $decimals) equivalent — comma thousands, dot decimals.
 */
export function formatNumber(value: unknown, decimals = 2): string {
    const amount = parseNumericValue(value);

    if (amount === null) {
        return formatNumber(0, decimals);
    }

    return amount.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
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
