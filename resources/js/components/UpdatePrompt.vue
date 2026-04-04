<template>
  <transition name="slide-down">
    <div v-if="showPrompt" class="update-prompt">
      <div class="prompt-content">
        <div class="prompt-icon">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
          </svg>
        </div>
        
        <div class="prompt-text">
          <h3>Update Available</h3>
          <p>A new version of 2FA-Vault is ready to install</p>
        </div>

        <div class="prompt-actions">
          <button @click="update" class="btn-update" :disabled="updating">
            {{ updating ? 'Updating...' : 'Update Now' }}
          </button>
          <button @click="dismiss" class="btn-dismiss">
            Later
          </button>
        </div>
      </div>
    </div>
  </transition>
</template>

<script>
import { ref, onMounted, onUnmounted } from 'vue';
import pwaService from '../services/pwa.js';

export default {
  name: 'UpdatePrompt',

  setup() {
    const showPrompt = ref(false);
    const updating = ref(false);

    // Handle update available event
    const handleUpdateAvailable = (event) => {
      console.log('[UpdatePrompt] Update available:', event.detail);
      showPrompt.value = true;
    };

    // Update application
    const update = async () => {
      updating.value = true;

      try {
        await pwaService.applyUpdate();
      } catch (error) {
        console.error('[UpdatePrompt] Failed to update:', error);
        updating.value = false;
      }
    };

    // Dismiss update prompt
    const dismiss = () => {
      showPrompt.value = false;
      
      // Show again in 24 hours
      setTimeout(() => {
        const status = pwaService.getStatus();
        if (status.hasUpdate) {
          showPrompt.value = true;
        }
      }, 24 * 60 * 60 * 1000);
    };

    // Lifecycle
    onMounted(() => {
      // Listen for update events
      window.addEventListener('pwa:updateAvailable', handleUpdateAvailable);

      // Check current status
      const status = pwaService.getStatus();
      if (status.hasUpdate) {
        showPrompt.value = true;
      }
    });

    onUnmounted(() => {
      window.removeEventListener('pwa:updateAvailable', handleUpdateAvailable);
    });

    return {
      showPrompt,
      updating,
      update,
      dismiss
    };
  }
};
</script>

<style scoped>
.update-prompt {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  color: white;
  padding: 1rem;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.15);
  z-index: 9999;
  animation: slideDown 0.3s ease-out;
}

@media (min-width: 768px) {
  .update-prompt {
    top: 1rem;
    left: 50%;
    right: auto;
    transform: translateX(-50%);
    max-width: 500px;
    border-radius: 12px;
  }
}

.prompt-content {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.prompt-icon {
  flex-shrink: 0;
  width: 48px;
  height: 48px;
  background: rgba(255, 255, 255, 0.2);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.prompt-icon svg {
  width: 28px;
  height: 28px;
}

.prompt-text {
  flex: 1;
}

.prompt-text h3 {
  margin: 0 0 0.25rem 0;
  font-size: 1.1rem;
  font-weight: 600;
}

.prompt-text p {
  margin: 0;
  font-size: 0.9rem;
  opacity: 0.95;
}

.prompt-actions {
  display: flex;
  gap: 0.5rem;
}

.btn-update,
.btn-dismiss {
  padding: 0.5rem 1rem;
  border: none;
  border-radius: 8px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
}

.btn-update {
  background: white;
  color: #10b981;
}

.btn-update:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.btn-update:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-dismiss {
  background: rgba(255, 255, 255, 0.2);
  color: white;
}

.btn-dismiss:hover {
  background: rgba(255, 255, 255, 0.3);
}

/* Transition */
.slide-down-enter-active,
.slide-down-leave-active {
  transition: transform 0.3s ease-out, opacity 0.3s;
}

.slide-down-enter-from {
  transform: translateY(-100%);
  opacity: 0;
}

.slide-down-leave-to {
  transform: translateY(-100%);
  opacity: 0;
}
</style>
