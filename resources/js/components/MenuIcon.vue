<script setup lang="ts">
import { computed } from 'vue';
import { getMenuIconComponent } from '@/utils/menuIcons';

const props = defineProps<{
    icon?: string | null;
}>();

const iconComponent = computed(() => getMenuIconComponent(props.icon));
</script>

<template>
    <component :is="iconComponent" size="md" class="menu-boxicon" />
</template>

<style scoped>
.menu-boxicon {
    display: block;
    fill: currentColor;
}
</style>
