/**
 * Push Notifications Service
 * Handles Web Push API integration for notifications
 */

class PushNotificationsService {
  constructor() {
    this.swRegistration = null;
    this.subscription = null;
    this.publicKey = null; // Will be set from server
  }

  /**
   * Initialize push notifications
   * @param {ServiceWorkerRegistration} swRegistration 
   */
  async init(swRegistration) {
    if (!('PushManager' in window)) {
      console.warn('[Push] Push notifications not supported');
      return false;
    }

    this.swRegistration = swRegistration;

    // Get VAPID public key from server
    await this.getPublicKey();

    // Check existing subscription
    this.subscription = await this.swRegistration.pushManager.getSubscription();

    return true;
  }

  /**
   * Get VAPID public key from server
   */
  async getPublicKey() {
    try {
      const response = await fetch('/api/push/public-key');
      const data = await response.json();
      this.publicKey = data.publicKey;
      console.log('[Push] Got VAPID public key');
    } catch (error) {
      console.error('[Push] Failed to get public key:', error);
      throw error;
    }
  }

  /**
   * Request notification permission
   */
  async requestPermission() {
    if (!('Notification' in window)) {
      throw new Error('Notifications not supported');
    }

    const permission = await Notification.requestPermission();
    console.log('[Push] Notification permission:', permission);

    return permission === 'granted';
  }

  /**
   * Subscribe to push notifications
   */
  async subscribe() {
    if (!this.swRegistration) {
      throw new Error('Service Worker not registered');
    }

    if (!this.publicKey) {
      await this.getPublicKey();
    }

    // Request permission first
    const permitted = await this.requestPermission();
    if (!permitted) {
      throw new Error('Notification permission denied');
    }

    try {
      // Subscribe to push manager
      this.subscription = await this.swRegistration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: this.urlBase64ToUint8Array(this.publicKey)
      });

      console.log('[Push] Subscribed to push notifications');

      // Send subscription to server
      await this.sendSubscriptionToServer(this.subscription);

      return this.subscription;
    } catch (error) {
      console.error('[Push] Failed to subscribe:', error);
      throw error;
    }
  }

  /**
   * Unsubscribe from push notifications
   */
  async unsubscribe() {
    if (!this.subscription) {
      console.warn('[Push] No active subscription');
      return;
    }

    try {
      // Unsubscribe from push manager
      await this.subscription.unsubscribe();
      console.log('[Push] Unsubscribed from push notifications');

      // Remove from server
      await this.removeSubscriptionFromServer(this.subscription);

      this.subscription = null;
    } catch (error) {
      console.error('[Push] Failed to unsubscribe:', error);
      throw error;
    }
  }

  /**
   * Send subscription to server
   */
  async sendSubscriptionToServer(subscription) {
    try {
      const response = await fetch('/api/push/subscribe', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          endpoint: subscription.endpoint,
          keys: {
            p256dh: this.arrayBufferToBase64(subscription.getKey('p256dh')),
            auth: this.arrayBufferToBase64(subscription.getKey('auth'))
          }
        })
      });

      if (!response.ok) {
        throw new Error('Failed to save subscription on server');
      }

      console.log('[Push] Subscription saved on server');
    } catch (error) {
      console.error('[Push] Failed to send subscription:', error);
      throw error;
    }
  }

  /**
   * Remove subscription from server
   */
  async removeSubscriptionFromServer(subscription) {
    try {
      const response = await fetch('/api/push/unsubscribe', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          endpoint: subscription.endpoint
        })
      });

      if (!response.ok) {
        throw new Error('Failed to remove subscription from server');
      }

      console.log('[Push] Subscription removed from server');
    } catch (error) {
      console.error('[Push] Failed to remove subscription:', error);
      throw error;
    }
  }

  /**
   * Send test notification
   */
  async sendTestNotification() {
    try {
      const response = await fetch('/api/push/test', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        }
      });

      if (!response.ok) {
        throw new Error('Failed to send test notification');
      }

      console.log('[Push] Test notification sent');
    } catch (error) {
      console.error('[Push] Failed to send test notification:', error);
      throw error;
    }
  }

  /**
   * Check if user is subscribed
   */
  isSubscribed() {
    return this.subscription !== null;
  }

  /**
   * Get subscription status
   */
  getStatus() {
    return {
      supported: 'PushManager' in window,
      permission: Notification.permission,
      subscribed: this.isSubscribed(),
      subscription: this.subscription
    };
  }

  /**
   * Convert URL-safe base64 to Uint8Array
   */
  urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
      .replace(/\-/g, '+')
      .replace(/_/g, '/');

    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
  }

  /**
   * Convert ArrayBuffer to base64
   */
  arrayBufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return window.btoa(binary);
  }
}

// Export singleton instance
export default new PushNotificationsService();
