// composables/useNetworkStatus.js
import { ref, onMounted, onBeforeUnmount } from "vue";

export function useNetworkStatus() {
  const isOnline = ref(navigator.onLine);

  const updateStatus = (event) => {
    isOnline.value = event.type === "online";
  };

  onMounted(() => {
    window.addEventListener("online", updateStatus);
    window.addEventListener("offline", updateStatus);
  });

  onBeforeUnmount(() => {
    window.removeEventListener("online", updateStatus);
    window.removeEventListener("offline", updateStatus);
  });

  return { isOnline };
}
