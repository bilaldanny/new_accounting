<script setup lang="ts">
    import { Trash2 } from '@lucide/vue';
    import { ref, watch } from 'vue';
    import type { RequisitionLineRow } from '@/composables/purchaseRequisition';
    import debounce from '@/utils/debounce';

    type ProductOption = {
        id: number | string;
        product_id: number | string;
        name?: string;
        product_name?: string;
        unit_id?: number | string;
        units?: Array<{ id: number | string; text: string }>;
    };

    const props = defineProps<{
        lines: RequisitionLineRow[];
        disabled?: boolean;
        searchProducts: (term: string) => Promise<ProductOption[]>;
    }>();

    const emit = defineEmits<{
        add: [line: RequisitionLineRow];
        update: [index: number, line: RequisitionLineRow];
        remove: [index: number];
    }>();

    const searchTerm = ref('');
    const searchResults = ref<ProductOption[]>([]);
    const searching = ref(false);

    const runSearch = debounce(async (term: string) => {
        searching.value = true;

        try {
            searchResults.value = await props.searchProducts(term);
        } finally {
            searching.value = false;
        }
    }, 300);

    watch(searchTerm, (term) => {
        void runSearch(term);
    });

    function addProduct(option: ProductOption) {
        const unit = option.units?.[0];

        emit('add', {
            product_id: option.product_id,
            variation_id: option.id,
            unit_id: unit?.id ?? option.unit_id ?? '',
            product_name: option.name ?? option.product_name,
            unit_name: unit?.text ?? '',
            requested_quantity: 1,
            note: '',
        });
        searchTerm.value = '';
        searchResults.value = [];
    }

    function updateQuantity(index: number, value: string) {
        emit('update', index, { ...props.lines[index], requested_quantity: value });
    }

    function updateNote(index: number, value: string) {
        emit('update', index, { ...props.lines[index], note: value });
    }
</script>

<template>
    <div class="requisition-lines">
        <div v-if="!disabled" class="requisition-lines__search">
            <input
                v-model="searchTerm"
                type="text"
                class="form-control form-control-sm"
                placeholder="Search a product to add…"
            >
            <ul v-if="searchResults.length" class="requisition-lines__results">
                <li v-for="option in searchResults" :key="option.id" @click="addProduct(option)">
                    {{ option.name ?? option.product_name }}
                </li>
            </ul>
            <div v-else-if="searching" class="requisition-lines__hint">Searching…</div>
        </div>

        <table class="table table-sm requisition-lines__table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th style="width: 140px">Quantity</th>
                    <th>Unit</th>
                    <th>Note</th>
                    <th v-if="!disabled" style="width: 40px"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(line, index) in lines" :key="`${line.product_id}-${line.variation_id}-${index}`">
                    <td>{{ line.product_name || `#${line.product_id}` }}</td>
                    <td>
                        <input
                            type="number"
                            min="0.01"
                            step="0.01"
                            class="form-control form-control-sm"
                            :value="line.requested_quantity"
                            :disabled="disabled"
                            @input="updateQuantity(index, ($event.target as HTMLInputElement).value)"
                        >
                    </td>
                    <td>{{ line.unit_name || '—' }}</td>
                    <td>
                        <input
                            type="text"
                            class="form-control form-control-sm"
                            :value="line.note"
                            :disabled="disabled"
                            @input="updateNote(index, ($event.target as HTMLInputElement).value)"
                        >
                    </td>
                    <td v-if="!disabled">
                        <button type="button" class="btn btn-sm btn-outline-danger" @click="emit('remove', index)">
                            <Trash2 class="h-3.5 w-3.5" />
                        </button>
                    </td>
                </tr>
                <tr v-if="!lines.length">
                    <td :colspan="disabled ? 4 : 5" class="text-center text-muted">No lines yet. Search a product above to add one.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
