<template>
  <transition name="fade">
    <div v-if="show" class="offline-indicator" :class="statusClass">
      <div class="indicator-content">
        <div class="indicator-icon">
          <svg v-if="isOnline" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
            <path d="M1 9l2 2c4.97-4.97 13.03-4.97 18 0l2-2C16.93 2.93 7.08 2.93 1 9zm8 8l3 3 3-3c-1.65-1.66-4.34-1.66-6 0zm-4-4l2 2c2.76-2.76 7.24-2.76 10 0l2-2C15.14 9.14 8.87 9.14 5 13z"/>
          </svg>
          <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
            <path d="M23.64 7l-2-2C16.57-.03 7.43-.03 2.36 5l-2 2 2 2c2.1-2.1 4.79-3.38 7.64-3.83V7h4V5.17c2.85.45 5.54 1.73 7.64 3.83l2-2zM17.5 14.5l-1.5-1.5c-1.1-1.1-2.9-1.1-4 0l-1.5 1.5 1.5 1.5 1.5-1.5 1.5 1.5 1.5-1.5zM3.41 5.56l14.83 14.83 1.41-1.41L4.82 4.15l-1.41 1.41z"/>
          </svg>
        </div>

        <div class="indicator-text">
          <strong>{{ statusText }}</strong>
          <span v-if="!isOnline && cachedAccounts > 0">
            {{ cachedAccounts }} accounts cached
          </span>
          <span v-if="syncing">
            Syncing...
          </span>
        </div>

        <button v-if="!autoHide" @click="dismiss" class="btn-dismiss">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
          </svg>
        </button>
      </div>
    </div>
  </transition>
</template>

<script>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import offlineDb from '../services/offline-db.js';

export default {
  name: 'OfflineIndicator',

  props: {
    autoHide: {
      type: Boolean,
      default: true
    },
    autoHideDelay: {
      type: Number,
      default: 5000
    }
  },

  setup(props) {
    const show = ref(false);
    const isOnline = ref(navigator.onLine);
    const syncing = ref(false);
    const cachedAccounts = ref(0);
    let hideTimer = null;

    // Status class
    const statusClass = computed(() => {
      return isOnline.value ? 'status-online' : 'status-offline';
    });

    // Status text
    const statusText = computed(() => {
      if (isOnline.value) {
        return syncing.value ? 'Back online - Syncing' : 'Back online';
      }
      return 'You are offline';
    });

    // Load cached accounts count
    const loadCachedCount = async () => {
      try {
        const stats = await offlineDb.getStats();
        cachedAccounts.value = stats.accounts || 0;
      } catch (error) {
        console.error('[OfflineIndicator] Failed to load stats:', error);
      }
    };

    // Show indicator with auto-hide
    const showIndicator = () => {
      show.value = true;

      if (props.autoHide) {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => {
          show.value = false;
        }, props.autoHideDelay);
      }
    };

    // Dismiss manually
    const dismiss = () => {
      clearTimeout(hideTimer);
      show.value = false;
    };

    // Handle online event
    const handleOnline = () => {
      isOnline.value = true;
      syncing.value = true;
      showIndicator();

      // Trigger sync
      window.dispatchEvent(new CustomEvent('pwa:online', { 
        detail: { isOnline: true } 
      }));

      // Stop syncing indicator after delay
      setTimeout(() => {
        syncing.value = false;
      }, 2000);
    };

    // Handle offline event
    const handleOffline = async () => {
      isOnline.value = false;
      syncing.value = false;
      
      await loadCachedCount();
      showIndicator();

      window.dispatchEvent(new CustomEvent('pwa:offline', { 
        detail: { isOnline: false } 
      }));
    };

    // Handle sync event
    const handleSync = () => {
      syncing.value = true;
      setTimeout(() => {
        syncing.value = false;
      }, 2000);
    };

    // Lifecycle
    onMounted(async () => {
      // Initial load
      await loadCachedCount();

      // Show indicator if offline on load
      if (!navigator.onLine) {
        showIndicator();
      }

      // Listen for network events
      window.addEventListener('online', handleOnline);
      window.addEventListener('offline', handleOffline);
      window.addEventListener('pwa:syncAccounts', handleSync);
    });

    onUnmounted(() => {
      clearTimeout(hideTimer);
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
      window.removeEventListener('pwa:syncAccounts', handleSync);
    });

    return {
      show,
      isOnline,
      syncing,
      cachedAccounts,
      statusClass,
      statusText,
      dismiss
    };
  }
};
</script>

<style scoped>
.offline-indicator {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  padding: 0.75rem 1rem;
  z-index: 9998;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  transition: all 0.3s ease;
}

.status-offline {
  background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%);
  color: white;
}

.status-online {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  color: white;
}

.indicator-content {
  max-width: 1200px;
  margin: 0 auto;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.indicator-icon {
  flex-shrink: 0;
  width: 24px;
  height: 24px;
}

.indicator-icon svg {
  width: 100%;
  height: 100%;
}

.indicator-text {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.indicator-text strong {
  font-weight: 600;
  font-size: 0.95rem;
}

.indicator-text span {
  font-size: 0.85rem;
  opacity: 0.9;
}

.btn-dismiss {
  flex-shrink: 0;
  background: rgba(255, 255, 255, 0.2);
  border: none;
  border-radius: 50%;
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: white;
  transition: background 0.2s;
}

.btn-dismiss:hover {
  background: rgba(255, 255, 255, 0.3);
}

.btn-dismiss svg {
  width: 16px;
  height: 16px;
}

/* Fade transition */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s, transform 0.3s;
}

.fade-enter-from {
  opacity: 0;
  transform: translateY(-100%);
}

.fade-leave-to {
  opacity: 0;
  transform: translateY(-100%);
}
</style>
