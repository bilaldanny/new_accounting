<script setup lang="ts">
import type { Branding } from '@/components/landing/BrandMark.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import PostCard from '@/components/landing/PostCard.vue';
import type { PostCardData } from '@/components/landing/PostCard.vue';
import BrandMark from '@/components/landing/BrandMark.vue';
import PublicHeader from '@/components/landing/PublicHeader.vue';

const props = defineProps<{
    branding: Branding;
    registrationEnabled: boolean;
    posts: {
        data: PostCardData[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

const brand = computed(() => props.branding.name);
const description = computed(() => `News and notes from ${props.branding.name}.`);
</script>

<template>
    <Head :title="`Blog | ${brand}`">
        <meta head-key="description" name="description" :content="description" />
        <meta head-key="og:title" property="og:title" :content="`Blog | ${brand}`" />
        <meta head-key="og:description" property="og:description" :content="description" />
        <meta head-key="og:type" property="og:type" content="website" />
    </Head>

    <div class="lp">
        <a class="lp-skip" href="#main">Skip to content</a>
        <PublicHeader :branding="branding" :registration-enabled="registrationEnabled" />

        <main id="main" class="lp-section">
            <div class="lp-wrap">
                <div class="lp-head">
                    <p class="lp-eyebrow">Blog</p>
                    <h1 style="font-size: clamp(1.8rem, 4vw, 2.6rem)">Latest posts</h1>
                </div>

                <div v-if="posts.data.length > 0" class="lp-posts">
                    <PostCard v-for="post in posts.data" :key="post.slug" :post="post" />
                </div>
                <p v-else class="lp-note">There are no posts yet.</p>

                <nav v-if="posts.last_page > 1" class="lp-pager" aria-label="Pages">
                    <a v-if="posts.prev_page_url" class="lp-btn lp-btn-ghost" :href="posts.prev_page_url" rel="prev">Newer</a>
                    <span class="lp-note" style="align-self: center">Page {{ posts.current_page }} of {{ posts.last_page }}</span>
                    <a v-if="posts.next_page_url" class="lp-btn lp-btn-ghost" :href="posts.next_page_url" rel="next">Older</a>
                </nav>
            </div>
        </main>

        <footer class="lp-footer">
            <div class="lp-wrap">
                <BrandMark :branding="branding" placement="footer" />
            </div>
            <div class="lp-wrap lp-legal">&copy; {{ new Date().getFullYear() }} {{ brand }}</div>
        </footer>
    </div>
</template>
