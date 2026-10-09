<script setup lang="ts">
    import { Newspaper } from '@lucide/vue';
    import { computed } from 'vue';

    const params = defineProps({
        type: String,
        formData: {
            type: Object,
            default: () => ({}),
        },
        formRef: {
            type: Object,
            default: null,
        },
    });

    const isEdit = computed(() => params.type === 'edit');

    const colHalf = { container: 6, label: 12, wrapper: 12 };
    const colThird = { container: 4, label: 12, wrapper: 12 };
    const colFull = { container: 12, label: 12, wrapper: 12 };
    const cardClasses = {
        ElementLayout: {
            container: 'product-form-card',
        },
        GroupElement: {
            wrapper: 'product-form-card__body',
        },
    };

    const statuses = [
        { value: 'draft', label: 'Draft (not public)' },
        { value: 'published', label: 'Published' },
    ];
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />

    <GroupElement name="group_post" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_post" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Newspaper class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Blog post</h2>
                    <p class="product-form-section-copy">Shown on the public site. The body is markdown; raw HTML is removed when it is shown.</p>
                </div>
            </div>
        </StaticElement>

        <TextElement id="Title" field-name="Title" name="title" label="Title" placeholder="Post title" :columns="colHalf" rules="required|max:200" />
        <TextElement id="Slug" field-name="Slug" name="slug" label="Slug" placeholder="made from the title when empty" :columns="colHalf" rules="max:200" info="Lowercase letters, numbers and dashes. It is the address of the post: /blog/slug" />
        <TextareaElement id="Excerpt" field-name="Excerpt" name="excerpt" label="Excerpt" :rows="2" :columns="colFull" rules="max:500" info="Short summary for the list and for search results. The start of the body is used when empty." />
        <TextareaElement id="Body" field-name="Body" name="body" label="Body (markdown)" :rows="16" :columns="colFull" rules="required" />

        <SelectElement
            name="status"
            :native="false"
            :items="statuses"
            id="Status"
            field-name="Status"
            label="Status"
            :columns="colThird"
            label-prop="label"
            value-prop="value"
            :can-clear="false"
            rules="required"
        />
        <TextElement id="PublishedAt" field-name="PublishedAt" name="published_at" label="Publish at" type="datetime-local" :columns="colThird" info="Server time. Leave empty to publish now. A later time keeps the post hidden until then." />
        <FileElement
            id="CoverImage"
            field-name="CoverImage"
            name="cover_image"
            label="Cover image"
            accept="image/png,image/jpeg,image/webp"
            :auto="false"
            :drop="false"
            :columns="colThird"
            info="JPG, PNG or WebP, up to 2 MB."
        />

        <StaticElement v-if="isEdit && formData.cover_url" name="cover_preview" :columns="colFull">
            <img :src="formData.cover_url" alt="Current cover" style="max-height: 120px; border-radius: 8px" />
        </StaticElement>
        <ToggleElement
            v-if="isEdit && formData.cover_url"
            name="remove_cover"
            label="Remove the current cover"
            :true-value="true"
            :false-value="false"
            :default="false"
            :columns="colThird"
        />

        <TextElement id="MetaTitle" field-name="MetaTitle" name="meta_title" label="Meta title" placeholder="defaults to the title" :columns="colHalf" rules="max:200" />
        <TextElement id="MetaDescription" field-name="MetaDescription" name="meta_description" label="Meta description" placeholder="defaults to the excerpt" :columns="colHalf" rules="max:500" />
    </GroupElement>
</template>
