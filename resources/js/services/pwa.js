// PWA Service - Handle service worker registration and PWA features

class PWAService {
  constructor() {
    this.deferredPrompt = null;
    this.registration = null;
    this._hasUpdate = false;
  }

  /**
   * Register service worker
   */
  async register() {
    if (!('serviceWorker' in navigator)) {
      if (import.meta.env.DEV) console.warn('Service Worker not supported');
      return false;
    }

    try {
      this.registration = await navigator.serviceWorker.register('/sw.js');
      if (import.meta.env.DEV) console.log('Service Worker registered:', this.registration);

      // Check for updates
      this.registration.addEventListener('updatefound', () => {
        this.handleUpdate(this.registration);
      });

      return true;
    } catch (error) {
      console.error('Service Worker registration failed:', error);
      return false;
    }
  }

  /**
   * Handle service worker updates
   */
  handleUpdate(registration) {
    const newWorker = registration.installing;
    
    newWorker.addEventListener('statechange', () => {
      if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
        // New service worker available
        this.notifyUpdate();
      }
    });
  }

  /**
   * Notify user about available update
   */
  notifyUpdate() {
    this._hasUpdate = true;
    const updateAvailable = new CustomEvent('pwa:updateAvailable');
    window.dispatchEvent(updateAvailable);
  }

  /**
   * Get current PWA status
   */
  getStatus() {
    return {
      hasUpdate: this._hasUpdate,
      isInstalled: this.isInstalled(),
      isOnline: navigator.onLine,
    };
  }

  /**
   * Apply pending update (E14: wait for the new SW to take control before
   * reloading, and reset the updating flag when there is no waiting worker).
   */
  applyUpdate() {
    if (!this.registration || !this.registration.waiting) {
      this.updating = false
      return
    }

    this.registration.waiting.postMessage({ type: 'SKIP_WAITING' })

    let reloaded = false
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (reloaded) return
      reloaded = true
      window.location.reload()
    })

    // Safety net: if controllerchange never fires, reload after a grace period.
    setTimeout(() => {
      if (!reloaded) {
        reloaded = true
        window.location.reload()
      }
    }, 3000)
  }

  /**
   * Handle install prompt
   */
  setupInstallPrompt() {
    window.addEventListener('beforeinstallprompt', (e) => {
      // Prevent default mini-infobar
      e.preventDefault();
      // Store event for later use
      this.deferredPrompt = e;
      
      // Dispatch custom event
      const installAvailable = new CustomEvent('pwa-install-available');
      window.dispatchEvent(installAvailable);
    });

    // Handle successful installation
    window.addEventListener('appinstalled', () => {
      if (import.meta.env.DEV) console.log('PWA installed successfully');
      this.deferredPrompt = null;
      
      const installed = new CustomEvent('pwa-installed');
      window.dispatchEvent(installed);
    });
  }

  /**
   * Trigger install prompt
   */
  async promptInstall() {
    if (!this.deferredPrompt) {
      return false;
    }

    // Show install prompt
    this.deferredPrompt.prompt();
    
    // Wait for user choice
    const { outcome } = await this.deferredPrompt.userChoice;
    if (import.meta.env.DEV) console.log(`Install prompt outcome: ${outcome}`);
    
    // Clear the deferred prompt
    this.deferredPrompt = null;
    
    return outcome === 'accepted';
  }

  /**
   * Check if app is installed
   */
  isInstalled() {
    // Check if running in standalone mode
    if (window.matchMedia('(display-mode: standalone)').matches) {
      return true;
    }
    
    // Check iOS
    if (window.navigator.standalone === true) {
      return true;
    }
    
    return false;
  }

  /**
   * Handle online/offline status
   */
  setupOnlineStatus(callback) {
    const updateOnlineStatus = () => {
      callback(navigator.onLine);
    };

    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);

    // Initial status
    updateOnlineStatus();

    // Return cleanup function
    return () => {
      window.removeEventListener('online', updateOnlineStatus);
      window.removeEventListener('offline', updateOnlineStatus);
    };
  }

  /**
   * Check if PWA features are supported
   */
  isSupported() {
    return 'serviceWorker' in navigator;
  }

  /**
   * Get installation instructions for platform. Returns i18n KEY lists —
   * the component translates them so the prompt follows the app language.
   */
  getInstallInstructions() {
    const userAgent = navigator.userAgent.toLowerCase();

    if (/iphone|ipad|ipod/.test(userAgent)) {
      return {
        platform: 'ios',
        stepKeys: [
          'pwa.install.ios.step1',
          'pwa.install.ios.step2',
          'pwa.install.ios.step3'
        ]
      };
    } else if (/android/.test(userAgent)) {
      return {
        platform: 'android',
        stepKeys: [
          'pwa.install.android.step1',
          'pwa.install.android.step2',
          'pwa.install.android.step3'
        ]
      };
    } else {
      return {
        platform: 'desktop',
        stepKeys: [
          'pwa.install.desktop.step1',
          'pwa.install.desktop.step2',
          'pwa.install.desktop.step3'
        ]
      };
    }
  }
}

export default new PWAService();
