<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

const name = usePage().props.name;
const year = new Date().getFullYear();
</script>

<template>
    <footer class="page-footer">
        <p class="mb-0">Copyright © {{ year }} {{ name }}. All rights reserved.</p>
    </footer>
</template>