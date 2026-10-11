<script setup lang="ts">
    import { onMounted, ref } from 'vue';

    /**
     * How to pay the company. Payments are not taken online: this only shows the instructions the company wrote
     * in its settings (bank, account title, IBAN ...).
     */
    const info = ref('');

    onMounted(async () => {
        try {
            info.value = (await window.axios.get('/api/portal/payment-info')).data.payment_info ?? '';
        } catch {
            info.value = '';
        }
    });
</script>

<template>
    <div v-if="info" class="border rounded p-3 mb-4" data-section="payment-info">
        <h6 class="mb-1">How to pay</h6>
        <div style="white-space: pre-line">{{ info }}</div>
    </div>
</template>
