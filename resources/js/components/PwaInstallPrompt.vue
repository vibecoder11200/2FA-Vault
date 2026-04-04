<template>
  <Transition name="slide-up">
    <div v-if="showPrompt" class="pwa-install-prompt">
      <div class="prompt-content">
        <div class="prompt-header">
          <div class="prompt-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
              <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
              <line x1="12" y1="22.08" x2="12" y2="12"></line>
            </svg>
          </div>
          <div class="prompt-title">
            <h3>Install 2FA-Vault</h3>
            <p>Add to your home screen for quick access</p>
          </div>
          <button @click="dismiss" class="close-btn" aria-label="Close">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>

        <!-- Platform-specific instructions -->
        <div v-if="platform === 'ios'" class="install-instructions">
          <p class="instruction-intro">To install on iOS:</p>
          <ol>
            <li v-for="(step, index) in instructions.steps" :key="index">{{ step }}</li>
          </ol>
        </div>

        <!-- Install button for supported browsers -->
        <div v-else class="prompt-actions">
          <button @click="install" class="install-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
              <polyline points="7 10 12 15 17 10"></polyline>
              <line x1="12" y1="15" x2="12" y2="3"></line>
            </svg>
            Install App
          </button>
          <button @click="dismiss" class="cancel-btn">Not now</button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import pwaService from '../services/pwa.js';

const showPrompt = ref(false);
const platform = ref('');
const instructions = ref({ steps: [] });

const install = async () => {
  const accepted = await pwaService.promptInstall();
  if (accepted) {
    showPrompt.value = false;
  }
};

const dismiss = () => {
  showPrompt.value = false;
  // Remember user dismissed (optional - could use localStorage)
  localStorage.setItem('pwa-install-dismissed', Date.now().toString());
};

const handleInstallAvailable = () => {
  // Check if user previously dismissed (within last 7 days)
  const dismissed = localStorage.getItem('pwa-install-dismissed');
  if (dismissed) {
    const daysSinceDismissed = (Date.now() - parseInt(dismissed)) / (1000 * 60 * 60 * 24);
    if (daysSinceDismissed < 7) {
      return;
    }
  }

  instructions.value = pwaService.getInstallInstructions();
  platform.value = instructions.value.platform;
  
  // Show prompt after a short delay
  setTimeout(() => {
    showPrompt.value = true;
  }, 3000);
};

const handleInstalled = () => {
  showPrompt.value = false;
  localStorage.removeItem('pwa-install-dismissed');
};

onMounted(() => {
  // Don't show if already installed
  if (pwaService.isInstalled()) {
    return;
  }

  window.addEventListener('pwa-install-available', handleInstallAvailable);
  window.addEventListener('pwa-installed', handleInstalled);

  // For iOS, show instructions automatically after delay
  const userAgent = navigator.userAgent.toLowerCase();
  if (/iphone|ipad|ipod/.test(userAgent) && !window.navigator.standalone) {
    handleInstallAvailable();
  }
});

onUnmounted(() => {
  window.removeEventListener('pwa-install-available', handleInstallAvailable);
  window.removeEventListener('pwa-installed', handleInstalled);
});
</script>

<style scoped>
.pwa-install-prompt {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 1000;
  padding: 1rem;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.9) 0%, rgba(0, 0, 0, 0.95) 100%);
  backdrop-filter: blur(10px);
  border-top: 1px solid rgba(79, 70, 229, 0.3);
  box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.3);
}

.prompt-content {
  max-width: 600px;
  margin: 0 auto;
}

.prompt-header {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1rem;
}

.prompt-icon {
  flex-shrink: 0;
  width: 48px;
  height: 48px;
  background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
}

.prompt-icon svg {
  width: 28px;
  height: 28px;
}

.prompt-title {
  flex: 1;
}

.prompt-title h3 {
  margin: 0;
  font-size: 1.125rem;
  font-weight: 600;
  color: white;
}

.prompt-title p {
  margin: 0.25rem 0 0;
  font-size: 0.875rem;
  color: rgba(255, 255, 255, 0.7);
}

.close-btn {
  flex-shrink: 0;
  width: 32px;
  height: 32px;
  border: none;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 8px;
  color: rgba(255, 255, 255, 0.7);
  cursor: pointer;
  transition: all 0.2s;
}

.close-btn:hover {
  background: rgba(255, 255, 255, 0.2);
  color: white;
}

.close-btn svg {
  width: 18px;
  height: 18px;
}

.install-instructions {
  background: rgba(255, 255, 255, 0.05);
  border-radius: 12px;
  padding: 1rem;
  margin-top: 1rem;
}

.instruction-intro {
  margin: 0 0 0.75rem;
  font-weight: 500;
  color: white;
}

.install-instructions ol {
  margin: 0;
  padding-left: 1.5rem;
  color: rgba(255, 255, 255, 0.8);
}

.install-instructions li {
  margin-bottom: 0.5rem;
  line-height: 1.5;
}

.install-instructions li:last-child {
  margin-bottom: 0;
}

.prompt-actions {
  display: flex;
  gap: 0.75rem;
  margin-top: 1rem;
}

.install-btn,
.cancel-btn {
  flex: 1;
  padding: 0.75rem 1.5rem;
  border: none;
  border-radius: 10px;
  font-size: 1rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
}

.install-btn {
  background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
  color: white;
}

.install-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(79, 70, 229, 0.4);
}

.install-btn svg {
  width: 20px;
  height: 20px;
}

.cancel-btn {
  background: rgba(255, 255, 255, 0.1);
  color: rgba(255, 255, 255, 0.7);
}

.cancel-btn:hover {
  background: rgba(255, 255, 255, 0.15);
  color: white;
}

/* Transitions */
.slide-up-enter-active,
.slide-up-leave-active {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.slide-up-enter-from {
  transform: translateY(100%);
  opacity: 0;
}

.slide-up-leave-to {
  transform: translateY(100%);
  opacity: 0;
}

/* Responsive */
@media (max-width: 640px) {
  .pwa-install-prompt {
    padding: 0.75rem;
  }

  .prompt-header {
    gap: 0.75rem;
  }

  .prompt-icon {
    width: 40px;
    height: 40px;
  }

  .prompt-icon svg {
    width: 24px;
    height: 24px;
  }

  .prompt-title h3 {
    font-size: 1rem;
  }

  .prompt-title p {
    font-size: 0.8125rem;
  }

  .prompt-actions {
    flex-direction: column;
  }

  .install-btn,
  .cancel-btn {
    width: 100%;
  }
}
</style>
