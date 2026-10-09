<script setup lang="ts">
import { computed, ref } from 'vue';

export interface Branding {
    name: string;
    logoUrl: string | null;
    headerLogoUrl?: string | null;
    footerLogoUrl?: string | null;
    headerChip?: boolean;
    footerChip?: boolean;
}

const props = withDefaults(defineProps<{ branding: Branding; placement?: 'header' | 'footer' }>(), { placement: 'header' });

const logo = computed(() => (props.placement === 'footer' ? props.branding.footerLogoUrl : props.branding.headerLogoUrl) ?? null);
const chip = computed(() => (props.placement === 'footer' ? props.branding.footerChip : props.branding.headerChip) ?? true);

const showName = computed(() => chip.value || !logo.value || broken.value);

/* A logo file that fails to load falls back to the name, never a broken image. */
const broken = ref(false);
</script>

<template>
    <a class="lp-logo" href="/" :aria-label="`${branding.name} home`">
        <!-- The software logo is made for light screens, so it sits on a light chip; a logo uploaded for the website is shown as it is. -->
        <span v-if="logo && !broken" :class="chip ? 'lp-logo-chip' : 'lp-logo-bare'">
            <img :src="logo" :alt="chip ? '' : branding.name" height="28" @error="broken = true" />
        </span>
        <span v-else class="lp-logo-mark" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20L12 4l8 16M8 14h8" /></svg>
        </span>
        <!-- A logo uploaded for the website is usually a full wordmark, so the name is not repeated beside it. -->
        <span v-if="showName">{{ branding.name }}</span>
    </a>
</template>
