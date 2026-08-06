<script setup lang="ts">
    import { home } from '@/routes';

    defineProps<{
        title?: string;
        description?: string;
    }>();

</script>

<template>
    <div class="authentication-header"></div>
        <div class="authentication-reset-password d-flex align-items-center justify-content-center">
            <div class="row">
                <div class="col-12 col-lg-10 mx-auto">
                    <div class="card">
                        <div class="row g-0">
                            <div class="col-lg-5 border-end">
                                <div class="card-body">
                                    <div class="p-5 rounded">
                                        <div class="text-center d-flex justify-content-center mb-3">
                                            <img src="assets/images/logo-img.png" width="180" alt="">
                                        </div>
                                        <div class="text-center">
                                            <h3 class="">{{ title }}</h3>
                                            <p v-html="description"></p>
                                        </div>
                                        <slot />
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-7">
                                <img src="assets/images/login-images/forgot-password-frent-img.jpg" class="card-img login-img h-100" alt="...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
</template>