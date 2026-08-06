<script setup lang="ts">
    import { ref, watch } from "vue";
    import { useNetworkStatus } from "@/composables/useNetworkStatus";

    const { isOnline } = useNetworkStatus();

    const showRestored = ref(false);
    const showOnline = ref(false);

    watch(isOnline, (online) => {
        if (online) {
            // Show "Restored"
            showRestored.value = true;
            setTimeout(()=>{
                showRestored.value = false;

            },1500);

            // Show "Online"
            showOnline.value = true;
            setTimeout(()=>{
                showRestored.value = false;
            },3000);
        }
    });
</script>

<template>
  <!-- Offline Alert -->
  <transition name="fade">
    <div
      v-if="!isOnline"
      class="alert bg-danger text-white position-fixed start-50 translate-middle-x top-3 shadow-lg"
    >
      <i class="bx bx-wifi-off me-2"></i>
      No Internet Connection. Reconnecting<span class="dots"></span>
    </div>
  </transition>

  <!-- Restored Alert -->
  <transition name="fade">
    <div
      v-if="showRestored"
      class="alert bg-secondary text-white position-fixed start-50 translate-middle-x top-3 shadow-lg"
    >
      <i class="bx bx-check-circle me-2"></i>
      Connection Restored
    </div>
  </transition>

  <!-- Online Alert -->
  <transition name="fade">
    <div
      v-if="showOnline"
      class="alert bg-success text-white position-fixed start-50 translate-middle-x top-3 shadow-lg"
    >
      <i class="bx bx-wifi me-2"></i>
      You are back online
    </div>
  </transition>
</template>

<style scoped>
    .alert {
        min-width: 260px;
        padding: 12px 18px;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }

    .top-3 {
        top: 1rem;
    }

    /* Fade transition */
    .fade-enter-active,
    .fade-leave-active {
        transition: opacity 0.4s;
    }
    .fade-enter-from,
    .fade-leave-to {
        opacity: 0;
    }

    /* Animated dots */
    .dots::after {
        content: "";
        animation: dots 1.2s infinite;
    }
    @keyframes dots {
        0% {
            content: "";
        }
        33% {
            content: ".";
        }
        66% {
            content: "..";
        }
        100% {
            content: "...";
        }
    }
</style>
