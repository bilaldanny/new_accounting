<script setup lang="ts">
import { ref } from 'vue';

defineProps<{
    branding: { name: string; logoUrl: string | null };
}>();

/* A logo file that fails to load falls back to the name, never a broken image. */
const broken = ref(false);
</script>

<template>
    <a class="lp-logo" href="/" :aria-label="`${branding.name} home`">
        <!-- A light chip keeps a dark logo readable on the navy background. -->
        <span v-if="branding.logoUrl && !broken" class="lp-logo-chip">
            <img :src="branding.logoUrl" alt="" height="28" @error="broken = true" />
        </span>
        <span v-else class="lp-logo-mark" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20L12 4l8 16M8 14h8" /></svg>
        </span>
        <span>{{ branding.name }}</span>
    </a>
</template>
