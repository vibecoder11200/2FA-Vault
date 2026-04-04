// PWA Service - Handle service worker registration and PWA features

class PWAService {
  constructor() {
    this.deferredPrompt = null;
    this.registration = null;
  }

  /**
   * Register service worker
   */
  async register() {
    if (!('serviceWorker' in navigator)) {
      console.warn('Service Worker not supported');
      return false;
    }

    try {
      this.registration = await navigator.serviceWorker.register('/sw.js');
      console.log('Service Worker registered:', this.registration);

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
    const updateAvailable = new CustomEvent('pwa-update-available');
    window.dispatchEvent(updateAvailable);
  }

  /**
   * Apply pending update
   */
  applyUpdate() {
    if (this.registration && this.registration.waiting) {
      this.registration.waiting.postMessage({ type: 'SKIP_WAITING' });
      window.location.reload();
    }
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
      console.log('PWA installed successfully');
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
    console.log(`Install prompt outcome: ${outcome}`);
    
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
   * Get installation instructions for platform
   */
  getInstallInstructions() {
    const userAgent = navigator.userAgent.toLowerCase();
    
    if (/iphone|ipad|ipod/.test(userAgent)) {
      return {
        platform: 'ios',
        steps: [
          'Tap the Share button at the bottom of Safari',
          'Scroll down and tap "Add to Home Screen"',
          'Tap "Add" in the top right corner'
        ]
      };
    } else if (/android/.test(userAgent)) {
      return {
        platform: 'android',
        steps: [
          'Tap the menu button (three dots)',
          'Tap "Add to Home screen" or "Install app"',
          'Tap "Add" or "Install" to confirm'
        ]
      };
    } else {
      return {
        platform: 'desktop',
        steps: [
          'Click the install icon in the address bar',
          'Or open browser menu and select "Install 2FA-Vault"',
          'Click "Install" to confirm'
        ]
      };
    }
  }
}

export default new PWAService();
