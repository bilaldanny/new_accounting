/**
 * Tailwind-based skeleton primitives with CSS gradient shimmer (see resources/css/tailwind-app.css).
 *
 * Example — dashboard cards:
 *   <SkeletonShimmer variant="inverted" :width="64" :height="64" rounded="full" custom-class="float-end" />
 *
 * Example — table (inside tbody while loading):
 *   <SkeletonTable :columns="columns.length" :rows="10" />
 *
 * Example — form placeholder:
 *   <SkeletonForm :fields="6" :columns="2" />
 */
export { default as SkeletonShimmer } from './SkeletonShimmer.vue';
export { default as SkeletonAvatar } from './SkeletonAvatar.vue';
export { default as SkeletonCard } from './SkeletonCard.vue';
export { default as SkeletonTable } from './SkeletonTable.vue';
export { default as SkeletonTableRows } from './SkeletonTableRows.vue';
export { default as SkeletonForm } from './SkeletonForm.vue';
export { default as SkeletonList } from './SkeletonList.vue';
export { default as SkeletonHeaderShell } from './SkeletonHeaderShell.vue';
export { default as SkeletonNotificationList } from './SkeletonNotificationList.vue';
export { default as SkeletonSidebarNav } from './SkeletonSidebarNav.vue';
export { default as PageContentSkeleton } from './PageContentSkeleton.vue';
export { default as GlobalSkeleton } from './GlobalSkeleton.vue';
