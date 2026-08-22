export const MAX_VARIANT_COMBINATIONS = 500;
export const VARIANT_SEPARATOR = ' / ';

export type VariationValueOption = {
    name: string;
    active?: boolean | number;
};

export type ApplicableVariation = {
    id: number | string;
    name?: string | null;
    values?: VariationValueOption[];
    active?: boolean | number;
};

export type VariationSelection = {
    variationId: number | string;
    enabled: boolean;
    selectedValues: string[];
};

export type VariantDimension = {
    name?: string;
    values: string[];
};

export function normalizeVariantLabel(value: string): string {
    return value.trim().replace(/\s+/g, ' ').toLowerCase();
}

export function variationDisplayName(variation: ApplicableVariation): string {
    const name = String(variation.name ?? '').trim();

    return name !== '' ? name : `Variation #${variation.id}`;
}

export function activeValueNames(variation: ApplicableVariation | null | undefined): string[] {
    if (! variation || ! Array.isArray(variation.values)) {
        return [];
    }

    const seen = new Set<string>();

    return variation.values.reduce<string[]>((names, value) => {
        const name = String(value?.name ?? '').trim();

        if (! name || value.active === false || value.active === 0 || value.active === '0') {
            return names;
        }

        const key = normalizeVariantLabel(name);

        if (seen.has(key)) {
            return names;
        }

        seen.add(key);
        names.push(name);

        return names;
    }, []);
}

export function combinationCount(dimensions: VariantDimension[]): number {
    const normalized = dimensions
        .map((dimension) => dimension.values.map((value) => value.trim()).filter(Boolean))
        .filter((values) => values.length > 0);

    if (normalized.length === 0) {
        return 0;
    }

    return normalized.reduce((total, values) => total * values.length, 1);
}

export function combinationFactors(dimensions: VariantDimension[]): number[] {
    return dimensions
        .map((dimension) => dimension.values.map((value) => value.trim()).filter(Boolean).length)
        .filter((count) => count > 0);
}

export function variantLabel(values: string[]): string {
    return values.map((value) => value.trim()).filter(Boolean).join(VARIANT_SEPARATOR);
}

export function splitVariantLabel(label: string): string[] {
    return label
        .split(VARIANT_SEPARATOR)
        .map((value) => value.trim())
        .filter(Boolean);
}

export function inferSelections(
    variations: ApplicableVariation[],
    details: Array<{ variation_name?: string }>,
): VariationSelection[] {
    const segments = details
        .flatMap((detail) => splitVariantLabel(String(detail.variation_name ?? '')))
        .filter((name) => name !== '' && name !== 'dummy');

    return variations.map((variation) => {
        const values = activeValueNames(variation);
        const selectedValues = values.filter((value) => segments.includes(value));

        if (selectedValues.length > 0) {
            return {
                variationId: variation.id,
                enabled: true,
                selectedValues,
            };
        }

        return {
            variationId: variation.id,
            enabled: true,
            selectedValues: [...values],
        };
    });
}

export function firstValidationError(
    variations: ApplicableVariation[],
    selections: VariationSelection[],
): string {
    const enabled = selections.filter((selection) => selection.enabled);

    if (enabled.length === 0) {
        return 'Select at least one variation.';
    }

    for (const selection of enabled) {
        const variation = variations.find((item) => String(item.id) === String(selection.variationId));
        const name = variation ? variationDisplayName(variation) : 'A variation';
        const active = variation ? activeValueNames(variation) : [];

        if (active.length === 0) {
            return `${name} has no active values.`;
        }

        if (selection.selectedValues.length === 0) {
            return `${name} has no selected values.`;
        }
    }

    const count = combinationCount(enabled.map((selection) => ({
        values: selection.selectedValues,
    })));

    if (count > MAX_VARIANT_COMBINATIONS) {
        return `This selection creates ${count} variants. Unselect some values or turn off a variation. Maximum is ${MAX_VARIANT_COMBINATIONS}.`;
    }

    return '';
}
