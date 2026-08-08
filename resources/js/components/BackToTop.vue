<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { ArrowUp } from '@boxicons/vue';

const isVisible = ref(false);

function updateVisibility(): void {
    isVisible.value = window.scrollY > 300;
}

function scrollToTop(): void {
    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
}

onMounted(() => {
    window.addEventListener('scroll', updateVisibility, { passive: true });
    updateVisibility();
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', updateVisibility);
});
</script>

<template>
    <a
        href="javascript:void(0);"
        class="back-to-top"
        :style="{ display: isVisible ? 'flex' : 'none' }"
        aria-label="Back to top"
        @click.prevent="scrollToTop"
    >
        <ArrowUp pack="solid" size="md" class="back-to-top-icon" />
    </a>
</template>

<style scoped>
.back-to-top {
    display: flex;
    align-items: center;
    justify-content: center;
}

.back-to-top-icon {
    fill: currentColor;
    width: 26.5px;
    height: 40px;
}
</style>
