<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'

const modalProps = defineProps({
    size: {
        type: String,
        default: 'lg'
    },
    id: {
        type: String,
        required: true
    },
    title: {
        type: String
    },
    header: {
        type: Boolean,
        default: true
    },
    footer: {
        type: Boolean,
        default: true
    },
    onOpen: Function,
    onClose: Function,
    onSubmit: Function,
})

let modalEl: HTMLElement | null = null

onMounted(() => {
    modalEl = document.getElementById(modalProps.id)

    if (!modalEl) return

    // ✅ When the modal starts to hide
    const handleHide = () => {
        if (document.activeElement && modalEl?.contains(document.activeElement)) {
            (document.activeElement as HTMLElement).blur()
        }
    }

    const handleShow = (event: Event) => {
        modalProps.onOpen?.(event)
    }

    const handleHidden = () => {
        modalProps.onClose?.()
    }

    modalEl.addEventListener('hide.bs.modal', handleHide)
    modalEl.addEventListener('show.bs.modal', handleShow)
    modalEl.addEventListener('hidden.bs.modal', handleHidden)

    // store cleanup references
    modalEl.__vueModalHandlers__ = { handleHide, handleShow, handleHidden }
})

onBeforeUnmount(() => {
    if (!modalEl) return
    const handlers = (modalEl as any).__vueModalHandlers__
    if (!handlers) return

    modalEl.removeEventListener('hide.bs.modal', handlers.handleHide)
    modalEl.removeEventListener('show.bs.modal', handlers.handleShow)
    modalEl.removeEventListener('hidden.bs.modal', handlers.handleHidden)
})
</script>

<template>
    <div
        class="modal fade"
        :id="id"
        tabindex="-1"
        aria-labelledby="exampleModalLabel"
        aria-hidden="true"
    >
        <div
            :class="[
                'modal-dialog',
                'modal-dialog-scrollable',
                'modal-dialog-centered',
                `modal-${modalProps.size}`
            ]"
        >
            <div class="modal-content">
                <div class="modal-header" v-if="modalProps.header">
                    <h6
                        v-if="modalProps.title"
                        class="modal-title text-capitalize"
                        id="exampleModalLabel1"
                    >
                        {{ modalProps.title }}
                    </h6>
                    <button
                        type="button"
                        id="ModalClose"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <div class="modal-body">
                    <slot />
                </div>

                <div class="modal-footer" v-if="modalProps.footer">
                    <slot name="footer"></slot>
                </div>
            </div>
        </div>
    </div>
</template>
