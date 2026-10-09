<script setup lang="ts">
export interface PostCardData {
    title: string;
    slug: string;
    excerpt: string;
    cover_url: string | null;
    published_at: string | null;
}

defineProps<{ post: PostCardData }>();

function dateText(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en', { year: 'numeric', month: 'short', day: 'numeric' }) : '';
}
</script>

<template>
    <article class="lp-card lp-post">
        <a :href="`/blog/${post.slug}`" class="lp-post-cover" tabindex="-1" aria-hidden="true">
            <img v-if="post.cover_url" :src="post.cover_url" alt="" loading="lazy" />
            <span v-else class="lp-post-cover-empty" />
        </a>
        <p class="lp-post-date">{{ dateText(post.published_at) }}</p>
        <h3><a :href="`/blog/${post.slug}`">{{ post.title }}</a></h3>
        <p class="lp-post-excerpt">{{ post.excerpt }}</p>
    </article>
</template>
