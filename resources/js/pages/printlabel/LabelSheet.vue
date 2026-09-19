<script setup lang="ts">
    import JsBarcode from 'jsbarcode';
    import { computed, nextTick, watch } from 'vue';
    import type { LabelProductRow, LabelSettings } from './types';

    const props = defineProps({
        products: {
            type: Array as () => LabelProductRow[],
            default: () => [],
        },
        settings: {
            type: Object as () => LabelSettings,
            required: true,
        },
        businessName: {
            type: String,
            default: '',
        },
        currencySymbol: {
            type: String,
            default: '',
        },
        currencyPlacement: {
            type: String,
            default: 'before',
        },
    });

    type Sticker = LabelProductRow & { stickerKey: string };

    const stickers = computed<Sticker[]>(() => {
        const rows: Sticker[] = [];

        props.products.forEach((product) => {
            const count = Math.max(0, Number(product.label) || 0);

            for (let i = 0; i < count; i++) {
                rows.push({ ...product, stickerKey: `${product.product_id}-${product.variation_id ?? 0}-${i}` });
            }
        });

        return rows;
    });

    const stickerWidthClass = computed(() => (props.settings.barcode_setting === 2 ? 'is-narrow' : 'is-wide'));

    function priceFor(sticker: Sticker): number {
        return props.settings.show_price === 'inclusive'
            ? Number(sticker.sell_price_inc_tax || sticker.default_sell_price || 0)
            : Number(sticker.default_sell_price || 0);
    }

    function formattedPrice(sticker: Sticker): string {
        const amount = priceFor(sticker).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

        if (! props.currencySymbol) {
            return amount;
        }

        return props.currencyPlacement === 'after' ? `${amount} ${props.currencySymbol}` : `${props.currencySymbol} ${amount}`;
    }

    function renderBarcodes() {
        stickers.value.forEach((sticker) => {
            const el = document.getElementById(`barcode-${sticker.stickerKey}`);

            if (! el || ! sticker.sku) {
                return;
            }

            try {
                JsBarcode(el, sticker.sku, {
                    format: props.settings.barcode_type,
                    width: 1.4,
                    height: 32,
                    fontSize: 11,
                    margin: 2,
                    displayValue: true,
                });
            } catch {
                // Unsupported SKU for the chosen barcode format — leave the sticker without a rendered barcode.
            }
        });
    }

    watch(
        [stickers, () => props.settings.barcode_type],
        async () => {
            await nextTick();
            renderBarcodes();
        },
        { immediate: true, deep: true },
    );

    defineExpose({ renderBarcodes });
</script>

<template>
    <div class="label-sheet">
        <div v-if="stickers.length === 0" class="label-sheet__empty">
            Add products and set a label count to preview the sheet.
        </div>
        <div v-else class="label-sheet__grid">
            <div
                v-for="sticker in stickers"
                :key="sticker.stickerKey"
                class="label-sheet__sticker"
                :class="stickerWidthClass"
            >
                <strong v-if="settings.business_name && businessName" class="label-sheet__business">{{ businessName }}</strong>
                <span v-if="settings.product_name" class="label-sheet__name">{{ sticker.pro_name }}</span>
                <span v-if="settings.product_variation && sticker.var_name" class="label-sheet__variation">
                    <b>{{ sticker.var_name }}</b>: {{ sticker.value }}
                </span>
                <span v-if="settings.product_price" class="label-sheet__price">Price: {{ formattedPrice(sticker) }}</span>
                <svg :id="`barcode-${sticker.stickerKey}`" class="label-sheet__barcode"></svg>
            </div>
        </div>
    </div>
</template>

<style scoped>
.label-sheet__empty {
    padding: 2.5rem 1rem;
    text-align: center;
    color: #64748b;
    font-size: 0.8125rem;
    background: #f8fafc;
    border: 1px dashed var(--app-border, #e5e7eb);
    border-radius: 0.65rem;
}

.label-sheet__grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    padding: 0.75rem;
    background: #f8fafc;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 0.65rem;
}

.label-sheet__sticker {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.1rem;
    padding: 0.35rem 0.3rem;
    border: 1px dotted #94a3b8;
    border-radius: 0.2rem;
    background: #fff;
    text-align: center;
}

.label-sheet__sticker.is-wide {
    width: 3.6375in;
    min-height: 1in;
}

.label-sheet__sticker.is-narrow {
    width: 2.54625in;
    min-height: 1in;
}

.label-sheet__business {
    font-size: 0.7rem;
    text-transform: uppercase;
    color: #0f172a;
}

.label-sheet__name {
    overflow: hidden;
    max-width: 100%;
    font-size: 0.7rem;
    color: #0f172a;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.label-sheet__variation {
    font-size: 0.65rem;
    color: #334155;
}

.label-sheet__price {
    font-size: 0.7rem;
    font-weight: 700;
    color: #0f172a;
}

.label-sheet__barcode {
    max-width: 100%;
    height: 32px;
}
</style>
