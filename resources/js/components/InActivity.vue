<script setup lang="ts">
    import { nextTick, ref, onMounted, onUnmounted } from "vue";
    import { usePage } from "@inertiajs/vue3";
    import axios from "axios";
    import ModalComponent from "./ModalComponent.vue";

    type BootstrapModalInstance = {
        show: () => void;
        hide: () => void;
        dispose: () => void;
    };

    type BootstrapModal = {
        getOrCreateInstance: (
            element: Element,
            options?: Record<string, unknown>,
        ) => BootstrapModalInstance;
    };

    const showModal = ref(false);
    const isIdle = ref(false);

    const reminders = [30]; // remind before 30s
    const remainingTime = ref(undefined);
    const remainingTimeDisplay = ref(undefined);
    const originalTime = ref(undefined);

    let timer = null;
    let retryCount = 0;
    const page = usePage();

    let ModalClass: BootstrapModal | null = null;
    let myModal: BootstrapModalInstance | null = null;

    /**
     * API: Check timeout from server
     */
    const checkTimeout = async (callback) => {
        try {
            const { data } = await axios.get("/idle-timeout-alert/check");
            remainingTime.value = data;
            remainingTimeDisplay.value = data;
            originalTime.value = data;
            if (callback) callback(data);
        } catch (error) {
            if (error.response) {
                if (error.response.status === 401) {
                    // Guest pages should not run inactivity checks.
                    return;
                }
                if (error.response.status === 500 && retryCount < 5) {
                    retryCount++;
                    return checkTimeout(callback);
                }
                if ([401, 419].includes(error.response.status)) {
                    window.location.href = "/login";
                }
            }
        }
    };

    /**
     * API: Reset timeout on activity
     */
    const resetTimeout = async () => {
        try {
            const { data } = await axios.post("/idle-timeout-alert/ping");
            showModal.value = false;
            isIdle.value = false;
            remainingTime.value = data;
            remainingTimeDisplay.value = data;
            originalTime.value = data;
        } catch (error) {
            console.error(error);
        }
    };

    /**
     * User reminded before idle
     */
    const handleRemind = () => {
        if (!myModal) return;
        myModal.show();
        showModal.value = true;
        isIdle.value = true;
    };

    /**
     * User goes fully idle
     */
    const handleIdle = () => {
        window.location.replace("/login");
    };

    /**
     * User becomes active again
     */
    const handleActive = () => {
        if (!myModal) return;
        myModal.hide();
        showModal.value = false;
        isIdle.value = false;
        remainingTimeDisplay.value = originalTime.value;
    };



    const initModal = async () => {
        if (typeof window === 'undefined') {
            return;
        }

        if (!ModalClass) {
            const module = await import('bootstrap/js/dist/modal');
            ModalClass = module.default as BootstrapModal;
        }

        await nextTick();
        const modalEl = document.getElementById("timeout-modal");
        if (!modalEl || !ModalClass) return;
        myModal = ModalClass.getOrCreateInstance(modalEl, {
            backdrop: "static",
            keyboard: false,
        });
    };

    onMounted(async () => {
        if (!page.props?.auth?.user) return;

        await initModal();

        checkTimeout();

        // countdown
        timer = setInterval(() => {
            if (remainingTimeDisplay.value !== undefined && remainingTimeDisplay.value > 0) {
                remainingTimeDisplay.value--;
            }
        }, 1000);

        // activity listeners
        window.addEventListener("mousemove", handleActive);
        window.addEventListener("keypress", handleActive);
    });

    onUnmounted(() => {
        if (timer) clearInterval(timer);
        if (myModal) {
            myModal.dispose();
            myModal = null;
        }
        window.removeEventListener("mousemove", handleActive);
        window.removeEventListener("keypress", handleActive);
    });
</script>

<template>
  <div>
    <!-- Idle Detector -->
    <v-idle
      :duration="remainingTime"
      :reminders="reminders"
      @remind="handleRemind"
      @idle="handleIdle"
      style="position: absolute;width: 100%;"
    />

    <modal-component
        id="timeout-modal"
        :onClose="resetTimeout"
        :header="false"
        :footer="false"
        size="md"
    >
        <div>
            <p class="text-center">We haven't heard from you in a while!</p>
            <p class="text-center">You will be automatically logged out in:</p>
            <p class="text-center">
                <span class="text-danger fs-4">{{ remainingTimeDisplay }}</span> seconds
            </p>
            <div class="d-flex flex-column w-100 justify-content-between align-items-center py-3">
                <button class="btn btn-sm btn-primary fw-bold text-uppercase text-white" @click="resetTimeout">
                    I'm still here!
                </button>
            </div>
        </div>
    </modal-component>
  </div>
</template>
