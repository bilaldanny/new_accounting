<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import PublicHeader from '@/components/landing/PublicHeader.vue';

const props = defineProps<{
    branding: { name: string; logoUrl: string | null };
    registrationEnabled: boolean;
    post: {
        title: string;
        slug: string;
        excerpt: string;
        cover_url: string | null;
        published_at: string | null;
        html: string;
        meta_title: string;
        meta_description: string;
        author: string | null;
    };
}>();

const brand = computed(() => props.branding.name);
const dateText = computed(() =>
    props.post.published_at ? new Date(props.post.published_at).toLocaleDateString('en', { year: 'numeric', month: 'long', day: 'numeric' }) : '',
);
</script>

<template>
    <Head :title="`${post.meta_title} | ${brand}`">
        <meta head-key="description" name="description" :content="post.meta_description" />
        <meta head-key="og:title" property="og:title" :content="post.meta_title" />
        <meta head-key="og:description" property="og:description" :content="post.meta_description" />
        <meta head-key="og:type" property="og:type" content="article" />
        <meta v-if="post.cover_url" head-key="og:image" property="og:image" :content="post.cover_url" />
    </Head>

    <div class="lp">
        <a class="lp-skip" href="#main">Skip to content</a>
        <PublicHeader :branding="branding" :registration-enabled="registrationEnabled" />

        <main id="main" class="lp-section">
            <article class="lp-wrap lp-article">
                <p class="lp-eyebrow"><a href="/blog" style="text-decoration: none; color: inherit">Blog</a></p>
                <h1 style="font-size: clamp(1.9rem, 4.2vw, 2.8rem)">{{ post.title }}</h1>
                <p class="lp-note" style="margin-top: 0">
                    <time v-if="post.published_at" :datetime="post.published_at">{{ dateText }}</time>
                    <span v-if="post.author"> &middot; {{ post.author }}</span>
                </p>
                <img v-if="post.cover_url" class="lp-article-cover" :src="post.cover_url" alt="" />
                <!-- The server renders the markdown with raw HTML stripped and unsafe links removed. -->
                <div class="lp-prose" v-html="post.html" />
                <p style="margin-top: 36px"><a href="/blog">&larr; All posts</a></p>
            </article>
        </main>

        <footer class="lp-footer">
            <div class="lp-wrap lp-legal" style="border-top: 0; margin-top: 0; padding-top: 0">&copy; {{ new Date().getFullYear() }} {{ brand }}</div>
        </footer>
    </div>
</template>
