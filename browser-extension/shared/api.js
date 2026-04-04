/**
 * API client for 2FA-Vault self-hosted server
 * Handles communication with backend API
 */

class APIClient {
  constructor() {
    this.baseUrl = null;
    this.token = null;
  }

  /**
   * Initialize API client with server URL and token
   * @param {string} serverUrl - Base URL of the API server
   * @param {string} token - Authentication token
   */
  async init() {
    // Load from storage
    this.baseUrl = await storage.getServerUrl();
    this.token = await storage.getApiToken();
  }

  /**
   * Make HTTP request with authentication
   * @param {string} endpoint - API endpoint
   * @param {object} options - Fetch options
   * @returns {Promise<any>} Response data
   */
  async request(endpoint, options = {}) {
    if (!this.baseUrl) {
      await this.init();
    }

    const url = `${this.baseUrl}${endpoint}`;
    const headers = {
      'Content-Type': 'application/json',
      ...options.headers
    };

    if (this.token) {
      headers['Authorization'] = `Bearer ${this.token}`;
    }

    const config = {
      ...options,
      headers
    };

    try {
      const response = await fetch(url, config);
      
      if (!response.ok) {
        const error = await response.json().catch(() => ({ message: response.statusText }));
        throw new Error(error.message || `HTTP ${response.status}`);
      }

      return await response.json();
    } catch (error) {
      console.error(`API request failed: ${endpoint}`, error);
      throw error;
    }
  }

  /**
   * Login to server
   * @param {string} email - User email
   * @param {string} password - User password
   * @returns {Promise<{token: string, user: object}>}
   */
  async login(email, password) {
    const response = await this.request('/api/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    });

    this.token = response.token;
    await storage.setApiToken(response.token);
    
    return response;
  }

  /**
   * Register new user
   * @param {string} email - User email
   * @param {string} password - User password
   * @returns {Promise<{token: string, user: object}>}
   */
  async register(email, password) {
    const response = await this.request('/api/auth/register', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    });

    this.token = response.token;
    await storage.setApiToken(response.token);
    
    return response;
  }

  /**
   * Logout (clear local token)
   */
  async logout() {
    this.token = null;
    await storage.remove(storage.constructor.KEYS.API_TOKEN);
  }

  /**
   * Get all 2FA accounts
   * @returns {Promise<Array>} List of encrypted accounts
   */
  async getAccounts() {
    return await this.request('/api/accounts', {
      method: 'GET'
    });
  }

  /**
   * Create new 2FA account
   * @param {object} account - Encrypted account data
   * @returns {Promise<object>} Created account
   */
  async createAccount(account) {
    return await this.request('/api/accounts', {
      method: 'POST',
      body: JSON.stringify(account)
    });
  }

  /**
   * Update existing account
   * @param {string} id - Account ID
   * @param {object} updates - Encrypted account updates
   * @returns {Promise<object>} Updated account
   */
  async updateAccount(id, updates) {
    return await this.request(`/api/accounts/${id}`, {
      method: 'PUT',
      body: JSON.stringify(updates)
    });
  }

  /**
   * Delete account
   * @param {string} id - Account ID
   * @returns {Promise<void>}
   */
  async deleteAccount(id) {
    return await this.request(`/api/accounts/${id}`, {
      method: 'DELETE'
    });
  }

  /**
   * Sync vault with server
   * @param {object} localVault - Local encrypted vault
   * @returns {Promise<object>} Merged vault
   */
  async syncVault(localVault) {
    return await this.request('/api/sync', {
      method: 'POST',
      body: JSON.stringify(localVault)
    });
  }

  /**
   * Check server health
   * @returns {Promise<{status: string, version: string}>}
   */
  async healthCheck() {
    return await this.request('/api/health', {
      method: 'GET'
    });
  }

  /**
   * Test connection to server
   * @returns {Promise<boolean>} True if connected
   */
  async testConnection() {
    try {
      await this.healthCheck();
      return true;
    } catch (error) {
      return false;
    }
  }

  /**
   * Retry failed request with exponential backoff
   * @param {Function} fn - Async function to retry
   * @param {number} maxRetries - Maximum retry attempts
   * @returns {Promise<any>}
   */
  async retry(fn, maxRetries = 3) {
    let lastError;
    
    for (let i = 0; i < maxRetries; i++) {
      try {
        return await fn();
      } catch (error) {
        lastError = error;
        const delay = Math.min(1000 * Math.pow(2, i), 10000); // Max 10s
        await new Promise(resolve => setTimeout(resolve, delay));
      }
    }
    
    throw lastError;
  }
}

// Export singleton
const api = new APIClient();

if (typeof module !== 'undefined' && module.exports) {
  module.exports = api;
}
