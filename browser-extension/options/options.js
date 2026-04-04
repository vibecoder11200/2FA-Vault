/**
 * Options page logic for 2FA-Vault extension
 */

// DOM elements
const serverUrlInput = document.getElementById('server-url');
const apiTokenInput = document.getElementById('api-token');
const connectionStatus = document.getElementById('connection-status');
const testConnectionBtn = document.getElementById('test-connection-btn');
const saveServerBtn = document.getElementById('save-server-btn');
const syncIntervalInput = document.getElementById('sync-interval');
const syncNowBtn = document.getElementById('sync-now-btn');
const saveSyncBtn = document.getElementById('save-sync-btn');
const themeRadios = document.querySelectorAll('input[name="theme"]');
const saveAppearanceBtn = document.getElementById('save-appearance-btn');
const autoLockInput = document.getElementById('auto-lock');
const changePasswordBtn = document.getElementById('change-password-btn');
const saveSecurityBtn = document.getElementById('save-security-btn');
const clearDataBtn = document.getElementById('clear-data-btn');
const messageContainer = document.getElementById('message-container');

/**
 * Initialize options page
 */
async function init() {
  await loadSettings();
  setupEventListeners();
  await checkConnection();
}

/**
 * Load saved settings
 */
async function loadSettings() {
  // Server settings
  const serverUrl = await storage.getServerUrl();
  serverUrlInput.value = serverUrl;

  const apiToken = await storage.getApiToken();
  if (apiToken) {
    apiTokenInput.value = '••••••••';
    apiTokenInput.dataset.hasToken = 'true';
  }

  // Sync settings
  const syncInterval = await storage.getAutoSyncInterval();
  syncIntervalInput.value = syncInterval;

  // Theme
  const theme = await storage.getTheme();
  const themeRadio = document.querySelector(`input[name="theme"][value="${theme}"]`);
  if (themeRadio) {
    themeRadio.checked = true;
  }

  // Auto-lock (default 15 minutes)
  const autoLock = await storage.get('autoLockTimeout') || 15;
  autoLockInput.value = autoLock;
}

/**
 * Setup event listeners
 */
function setupEventListeners() {
  // Server settings
  testConnectionBtn.addEventListener('click', handleTestConnection);
  saveServerBtn.addEventListener('click', handleSaveServer);

  // Sync settings
  syncNowBtn.addEventListener('click', handleSyncNow);
  saveSyncBtn.addEventListener('click', handleSaveSync);

  // Appearance
  saveAppearanceBtn.addEventListener('click', handleSaveAppearance);

  // Security
  changePasswordBtn.addEventListener('click', handleChangePassword);
  saveSecurityBtn.addEventListener('click', handleSaveSecurity);

  // Danger zone
  clearDataBtn.addEventListener('click', handleClearData);

  // Auto-save server URL on change
  serverUrlInput.addEventListener('blur', async () => {
    const url = serverUrlInput.value.trim();
    if (url) {
      await storage.setServerUrl(url);
      await checkConnection();
    }
  });
}

/**
 * Check server connection
 */
async function checkConnection() {
  try {
    await api.init();
    const connected = await api.testConnection();
    updateConnectionStatus(connected);
  } catch (error) {
    updateConnectionStatus(false);
  }
}

/**
 * Update connection status indicator
 */
function updateConnectionStatus(connected) {
  if (connected) {
    connectionStatus.textContent = 'Connected';
    connectionStatus.className = 'status-badge success';
  } else {
    connectionStatus.textContent = 'Offline';
    connectionStatus.className = 'status-badge error';
  }
}

/**
 * Handle test connection
 */
async function handleTestConnection() {
  testConnectionBtn.textContent = 'Testing...';
  testConnectionBtn.disabled = true;

  try {
    const serverUrl = serverUrlInput.value.trim();
    if (!serverUrl) {
      throw new Error('Please enter a server URL');
    }

    await storage.setServerUrl(serverUrl);
    await api.init();
    
    const response = await api.healthCheck();
    updateConnectionStatus(true);
    showMessage(`Connected successfully! Server version: ${response.version || 'unknown'}`, 'success');
  } catch (error) {
    updateConnectionStatus(false);
    showMessage(`Connection failed: ${error.message}`, 'error');
  } finally {
    testConnectionBtn.textContent = 'Test Connection';
    testConnectionBtn.disabled = false;
  }
}

/**
 * Handle save server settings
 */
async function handleSaveServer() {
  try {
    const serverUrl = serverUrlInput.value.trim();
    if (!serverUrl) {
      throw new Error('Server URL is required');
    }

    await storage.setServerUrl(serverUrl);

    // Save API token if changed
    if (apiTokenInput.value && apiTokenInput.value !== '••••••••') {
      await storage.setApiToken(apiTokenInput.value.trim());
      apiTokenInput.dataset.hasToken = 'true';
    }

    showMessage('Server settings saved successfully', 'success');
    await checkConnection();
  } catch (error) {
    showMessage(`Failed to save: ${error.message}`, 'error');
  }
}

/**
 * Handle sync now
 */
async function handleSyncNow() {
  syncNowBtn.textContent = 'Syncing...';
  syncNowBtn.disabled = true;

  try {
    const response = await chrome.runtime.sendMessage({ action: 'sync-now' });

    if (response.success) {
      showMessage('Synced successfully', 'success');
    } else if (response.error) {
      showMessage(`Sync failed: ${response.error}`, 'error');
    } else if (response.skipped) {
      showMessage('Vault is locked. Unlock to sync.', 'error');
    }
  } catch (error) {
    showMessage(`Sync failed: ${error.message}`, 'error');
  } finally {
    syncNowBtn.textContent = 'Sync Now';
    syncNowBtn.disabled = false;
  }
}

/**
 * Handle save sync settings
 */
async function handleSaveSync() {
  try {
    const interval = parseInt(syncIntervalInput.value);
    
    if (isNaN(interval) || interval < 1 || interval > 1440) {
      throw new Error('Sync interval must be between 1 and 1440 minutes');
    }

    await storage.setAutoSyncInterval(interval);

    // Update alarm
    chrome.alarms.clear('auto-sync', () => {
      chrome.alarms.create('auto-sync', { periodInMinutes: interval });
    });

    showMessage('Sync settings saved successfully', 'success');
  } catch (error) {
    showMessage(`Failed to save: ${error.message}`, 'error');
  }
}

/**
 * Handle save appearance
 */
async function handleSaveAppearance() {
  try {
    const selectedTheme = document.querySelector('input[name="theme"]:checked').value;
    await storage.setTheme(selectedTheme);
    showMessage('Appearance settings saved', 'success');

    // Apply theme (optional - implement theme switching logic)
    applyTheme(selectedTheme);
  } catch (error) {
    showMessage(`Failed to save: ${error.message}`, 'error');
  }
}

/**
 * Apply theme
 */
function applyTheme(theme) {
  // Implement theme switching logic here
  // For now, just log
  console.log('Theme changed to:', theme);
}

/**
 * Handle change master password
 */
async function handleChangePassword() {
  // This would typically open a modal or new page
  const newPassword = prompt('Enter new master password:');
  
  if (!newPassword) {
    return;
  }

  const confirmPassword = prompt('Confirm new master password:');
  
  if (newPassword !== confirmPassword) {
    showMessage('Passwords do not match', 'error');
    return;
  }

  // In a real implementation, you'd:
  // 1. Verify current password
  // 2. Re-encrypt vault with new password
  // 3. Save new encrypted vault
  
  showMessage('Password change not yet implemented - coming soon!', 'error');
}

/**
 * Handle save security settings
 */
async function handleSaveSecurity() {
  try {
    const autoLock = parseInt(autoLockInput.value);
    
    if (isNaN(autoLock) || autoLock < 1 || autoLock > 120) {
      throw new Error('Auto-lock timeout must be between 1 and 120 minutes');
    }

    await storage.set('autoLockTimeout', autoLock);
    showMessage('Security settings saved', 'success');
  } catch (error) {
    showMessage(`Failed to save: ${error.message}`, 'error');
  }
}

/**
 * Handle clear all data
 */
async function handleClearData() {
  const confirmed = confirm(
    'Are you ABSOLUTELY sure you want to delete ALL your 2FA accounts and settings?\n\n' +
    'This action CANNOT be undone!\n\n' +
    'Type "DELETE" in the next prompt to confirm.'
  );

  if (!confirmed) {
    return;
  }

  const verification = prompt('Type DELETE to confirm:');
  
  if (verification !== 'DELETE') {
    showMessage('Data deletion cancelled', 'error');
    return;
  }

  try {
    await storage.clear();
    showMessage('All data cleared successfully. Extension will now reload.', 'success');
    
    setTimeout(() => {
      window.location.reload();
    }, 2000);
  } catch (error) {
    showMessage(`Failed to clear data: ${error.message}`, 'error');
  }
}

/**
 * Show message
 */
function showMessage(text, type = 'info') {
  const message = document.createElement('div');
  message.className = `message ${type}`;
  message.textContent = text;

  messageContainer.innerHTML = '';
  messageContainer.appendChild(message);

  // Auto-hide after 5 seconds
  setTimeout(() => {
    message.style.opacity = '0';
    message.style.transition = 'opacity 0.3s';
    setTimeout(() => message.remove(), 300);
  }, 5000);
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
