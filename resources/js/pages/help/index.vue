<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Help Center',
            subtitle: 'Guides and answers; open a support ticket if you do not find yours',
            breadcrumbs: [{ title: 'Help', href: 'NULL' }, { title: 'Help Center', href: 'NULL' }],
        },
    });

    type Article = { id: number; title: string; slug: string; category: string };
    type Open = { title: string; body: string; category: string };

    const { run } = useCloseout();
    const articles = ref<Article[]>([]);
    const categories = ref<string[]>([]);
    const query = ref('');
    const category = ref('');
    const open = ref<Open | null>(null);

    async function load() {
        const response = await run(() => window.axios.get('/api/help-articles', { params: { q: query.value || undefined, category: category.value || undefined } }));
        articles.value = response?.data?.data ?? [];
        categories.value = response?.data?.categories ?? [];
    }

    async function show(article: Article) {
        const response = await run(() => window.axios.get(`/api/help-articles/${article.slug}`));
        open.value = response?.data?.data ?? null;
    }

    onMounted(load);
</script>

<template>
    <Head title="Help Center" />

    <div class="container-fluid py-3">
        <div class="row g-3">
            <div class="col-lg-4">
                <form class="d-flex gap-2 mb-2" @submit.prevent="load">
                    <input v-model="query" type="search" class="form-control form-control-sm" placeholder="Search the help" aria-label="Search the help" />
                    <button type="submit" class="btn btn-dark btn-sm">Search</button>
                </form>
                <select v-model="category" class="form-select form-select-sm mb-2" aria-label="Category" @change="load"><option value="">All topics</option><option v-for="c in categories" :key="c" :value="c">{{ c }}</option></select>
                <div class="list-group">
                    <button v-for="article in articles" :key="article.id" type="button" class="list-group-item list-group-item-action" @click="show(article)">{{ article.title }} <span class="text-muted small">{{ article.category }}</span></button>
                    <div v-if="!articles.length" class="text-muted small p-2">No articles found.</div>
                </div>
                <a class="btn btn-outline-dark btn-sm mt-3" href="/support">Ask support</a>
            </div>
            <div class="col-lg-8">
                <div v-if="open" class="border rounded p-3">
                    <h5>{{ open.title }}</h5>
                    <div style="white-space: pre-line">{{ open.body }}</div>
                </div>
                <div v-else class="text-muted">Choose an article.</div>
            </div>
        </div>
    </div>
</template>
