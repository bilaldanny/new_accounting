<script setup lang="ts">
    const appUrl = import.meta.env.VITE_APP_URL
</script>

<template>
    <div class="loader">
        <img :src="`${appUrl}/assets/images/loader.svg`" alt="Loader">
    </div>
</template>

<style lang="css" scoped>
    .loader{
        width: 100%;
        height: 300px;
    }

    .loader img{
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
    }
</style>
