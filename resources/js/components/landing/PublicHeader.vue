<script setup lang="ts">
import { ref } from 'vue';
import BrandMark from '@/components/landing/BrandMark.vue';

defineProps<{
    branding: { name: string; logoUrl: string | null };
    registrationEnabled: boolean;
}>();

const menuOpen = ref(false);
</script>

<template>
    <header class="lp-nav">
        <div class="lp-wrap lp-nav-row">
            <BrandMark :branding="branding" />
            <button class="lp-menu-btn" type="button" :aria-expanded="menuOpen" aria-controls="lp-links" @click="menuOpen = !menuOpen">Menu</button>
            <nav id="lp-links" class="lp-links" :class="{ 'is-open': menuOpen }" aria-label="Main">
                <a href="/">Home</a>
                <a href="/blog">Blog</a>
                <a href="/login">Login</a>
                <a v-if="registrationEnabled" class="lp-btn" href="/register">Register</a>
            </nav>
        </div>
    </header>
</template>
