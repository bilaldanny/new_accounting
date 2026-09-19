<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { computed, ref } from 'vue';
    import useVoucherApprovals, { VOUCHER_APPROVAL } from '@/composables/voucherApproval';
    import type { VoucherFamily } from '@/composables/voucherApproval';

    /**
     * Approve and Reject buttons for the view page of a manual voucher. They only show while the
     * voucher is pending (`canDecide`, from the API's can_approve) and only for a user whose role
     * holds the matching menu permission; the server checks the same permission again.
     */
    const props = defineProps<{
        family: VoucherFamily;
        recordId: number;
        canDecide: boolean;
        disabled?: boolean;
    }>();

    const emit = defineEmits<{ decided: [] }>();

    const page = usePage();
    const { approveVoucher, rejectVoucher } = useVoucherApprovals(props.family);

    const isActing = ref(false);

    const permissionPaths = computed(() => (page.props.auth as { user?: { permission_paths?: string[] } } | undefined)?.user?.permission_paths ?? []);
    const showApprove = computed(() => props.canDecide && permissionPaths.value.includes(VOUCHER_APPROVAL[props.family].approvePath));
    const showReject = computed(() => props.canDecide && permissionPaths.value.includes(VOUCHER_APPROVAL[props.family].rejectPath));

    async function decide(decision: 'approve' | 'reject') {
        isActing.value = true;
        const done = decision === 'approve'
            ? await approveVoucher(props.recordId)
            : await rejectVoucher(props.recordId);
        isActing.value = false;

        if (done) {
            emit('decided');
        }
    }
</script>

<template>
    <div class="voucher-approval-actions" style="display: contents">
        <button
            v-if="showReject"
            type="button"
            class="btn btn-outline-danger"
            :disabled="disabled || isActing"
            @click="decide('reject')"
        >
            Reject
        </button>
        <button
            v-if="showApprove"
            type="button"
            class="btn btn-primary d-inline-flex align-items-center"
            :disabled="disabled || isActing"
            :aria-busy="isActing"
            @click="decide('approve')"
        >
            <span v-if="isActing" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
            Approve
        </button>
    </div>
</template>
