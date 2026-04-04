/**
 * Background service worker for 2FA-Vault extension
 * Handles OTP generation, auto-fill, and sync
 */

// Import shared modules (works in service worker)
importScripts('shared/crypto.js', 'shared/storage.js', 'shared/api.js');

// Service worker state
let decryptedAccounts = [];
let sessionActive = false;

// Initialize service worker
chrome.runtime.onInstalled.addListener(async () => {
  console.log('2FA-Vault extension installed');
  
  // Set up periodic sync alarm (every 30 seconds for TOTP refresh)
  chrome.alarms.create('totp-refresh', { periodInMinutes: 0.5 });
  
  // Set up auto-sync alarm (configurable interval)
  const syncInterval = await storage.getAutoSyncInterval();
  chrome.alarms.create('auto-sync', { periodInMinutes: syncInterval });
  
  // Initialize default settings
  const serverUrl = await storage.getServerUrl();
  if (!serverUrl) {
    await storage.setServerUrl('http://localhost:8000');
  }
});

// Handle alarms
chrome.alarms.onAlarm.addListener(async (alarm) => {
  if (alarm.name === 'totp-refresh') {
    // Refresh TOTP codes if vault is unlocked
    if (sessionActive) {
      await refreshTOTPCodes();
      notifyPopup({ type: 'totp-updated' });
    }
  } else if (alarm.name === 'auto-sync') {
    // Auto-sync with server if connected
    if (sessionActive) {
      await syncWithServer();
    }
  }
});

// Handle messages from popup and content scripts
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  handleMessage(message, sender).then(sendResponse).catch((error) => {
    sendResponse({ error: error.message });
  });
  return true; // Keep channel open for async response
});

/**
 * Handle incoming messages
 */
async function handleMessage(message, sender) {
  const { action, data } = message;

  switch (action) {
    case 'unlock-vault':
      return await unlockVault(data.masterPassword);
    
    case 'lock-vault':
      return await lockVault();
    
    case 'get-accounts':
      return await getAccounts();
    
    case 'get-otp':
      return await getOTP(data.accountId);
    
    case 'autofill-otp':
      return await autofillOTP(sender.tab.id, data.accountId);
    
    case 'add-account':
      return await addAccount(data.account);
    
    case 'update-account':
      return await updateAccount(data.accountId, data.updates);
    
    case 'delete-account':
      return await deleteAccount(data.accountId);
    
    case 'sync-now':
      return await syncWithServer();
    
    case 'get-status':
      return {
        locked: !sessionActive,
        accountCount: decryptedAccounts.length,
        connected: await api.testConnection()
      };
    
    default:
      throw new Error(`Unknown action: ${action}`);
  }
}

/**
 * Unlock vault with master password
 */
async function unlockVault(masterPassword) {
  try {
    // Get encrypted vault from storage
    const encryptedVault = await storage.getEncryptedVault();
    
    if (!encryptedVault) {
      throw new Error('No vault found. Please set up your vault first.');
    }

    // Derive key from master password
    const salt = cryptoUtil.base64ToArrayBuffer(encryptedVault.salt);
    const key = await cryptoUtil.deriveKey(masterPassword, new Uint8Array(salt));

    // Decrypt vault
    const decryptedData = await cryptoUtil.decrypt(
      encryptedVault.data,
      encryptedVault.iv,
      key
    );

    decryptedAccounts = JSON.parse(decryptedData);
    sessionActive = true;

    // Store session key (not the master password!)
    await storage.unlock(encryptedVault.salt);

    return { success: true, accountCount: decryptedAccounts.length };
  } catch (error) {
    console.error('Failed to unlock vault:', error);
    throw new Error('Invalid master password or corrupted vault');
  }
}

/**
 * Lock vault and clear session
 */
async function lockVault() {
  decryptedAccounts = [];
  sessionActive = false;
  await storage.lock();
  return { success: true };
}

/**
 * Get all accounts (vault must be unlocked)
 */
async function getAccounts() {
  if (!sessionActive) {
    throw new Error('Vault is locked');
  }
  
  // Generate current OTP codes for all accounts
  const accountsWithOTP = await Promise.all(
    decryptedAccounts.map(async (account) => ({
      ...account,
      otp: await generateTOTP(account.secret),
      timeRemaining: getTimeRemaining()
    }))
  );

  return accountsWithOTP;
}

/**
 * Get OTP for specific account
 */
async function getOTP(accountId) {
  if (!sessionActive) {
    throw new Error('Vault is locked');
  }

  const account = decryptedAccounts.find(a => a.id === accountId);
  if (!account) {
    throw new Error('Account not found');
  }

  const otp = await generateTOTP(account.secret);
  const timeRemaining = getTimeRemaining();

  return { otp, timeRemaining, account };
}

/**
 * Auto-fill OTP into active tab
 */
async function autofillOTP(tabId, accountId) {
  const { otp } = await getOTP(accountId);

  // Send message to content script to fill OTP
  await chrome.tabs.sendMessage(tabId, {
    action: 'fill-otp',
    otp: otp
  });

  return { success: true, otp };
}

/**
 * Generate TOTP code (Time-based One-Time Password)
 * Simplified implementation - in production use a proper library
 */
async function generateTOTP(secret, timeStep = 30) {
  // This is a placeholder - implement proper TOTP algorithm
  // In real extension, use a library like otpauth or jsOTP
  
  const counter = Math.floor(Date.now() / 1000 / timeStep);
  const hash = await cryptoUtil.hash(secret + counter);
  
  // Extract 6-digit code from hash
  const code = parseInt(hash.substring(0, 8), 16) % 1000000;
  return String(code).padStart(6, '0');
}

/**
 * Get time remaining for current TOTP code
 */
function getTimeRemaining(timeStep = 30) {
  const now = Math.floor(Date.now() / 1000);
  return timeStep - (now % timeStep);
}

/**
 * Refresh all TOTP codes
 */
async function refreshTOTPCodes() {
  if (!sessionActive) return;
  
  // Generate fresh codes for all accounts
  for (const account of decryptedAccounts) {
    account.otp = await generateTOTP(account.secret);
    account.timeRemaining = getTimeRemaining();
  }
}

/**
 * Add new account to vault
 */
async function addAccount(account) {
  if (!sessionActive) {
    throw new Error('Vault is locked');
  }

  account.id = crypto.randomUUID();
  account.createdAt = Date.now();
  decryptedAccounts.push(account);

  await saveVault();
  return account;
}

/**
 * Update existing account
 */
async function updateAccount(accountId, updates) {
  if (!sessionActive) {
    throw new Error('Vault is locked');
  }

  const index = decryptedAccounts.findIndex(a => a.id === accountId);
  if (index === -1) {
    throw new Error('Account not found');
  }

  decryptedAccounts[index] = { ...decryptedAccounts[index], ...updates };
  await saveVault();
  
  return decryptedAccounts[index];
}

/**
 * Delete account
 */
async function deleteAccount(accountId) {
  if (!sessionActive) {
    throw new Error('Vault is locked');
  }

  decryptedAccounts = decryptedAccounts.filter(a => a.id !== accountId);
  await saveVault();
  
  return { success: true };
}

/**
 * Save vault to storage (encrypted)
 */
async function saveVault() {
  // Get salt from storage or generate new one
  let encryptedVault = await storage.getEncryptedVault();
  let salt;

  if (encryptedVault && encryptedVault.salt) {
    salt = cryptoUtil.base64ToArrayBuffer(encryptedVault.salt);
  } else {
    salt = cryptoUtil.generateSalt();
  }

  // Note: We need the master password to encrypt again
  // In practice, you'd store a derived key temporarily during session
  // For now, this is a simplified version
  
  const sessionKey = await storage.getSessionKey();
  if (!sessionKey) {
    throw new Error('Cannot save vault: session key not available');
  }

  // Re-derive key from session key (in real app, keep key in memory)
  const key = await cryptoUtil.deriveKey('temporary', new Uint8Array(salt));

  const encrypted = await cryptoUtil.encrypt(
    JSON.stringify(decryptedAccounts),
    key,
    new Uint8Array(salt)
  );

  await storage.setEncryptedVault(encrypted);
}

/**
 * Sync with server
 */
async function syncWithServer() {
  if (!sessionActive) return { skipped: true };

  try {
    const connected = await api.testConnection();
    if (!connected) {
      return { error: 'Server not reachable' };
    }

    // Get encrypted vault
    const encryptedVault = await storage.getEncryptedVault();
    
    // Sync with server
    const mergedVault = await api.syncVault(encryptedVault);
    
    await storage.setEncryptedVault(mergedVault);
    await storage.set('lastSync', Date.now());

    return { success: true, lastSync: Date.now() };
  } catch (error) {
    console.error('Sync failed:', error);
    return { error: error.message };
  }
}

/**
 * Notify popup of updates
 */
function notifyPopup(message) {
  chrome.runtime.sendMessage(message).catch(() => {
    // Popup might be closed, ignore error
  });
}

/**
 * Handle keyboard command
 */
chrome.commands.onCommand.addListener(async (command) => {
  if (command === 'autofill') {
    // Get active tab
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    
    if (tab && sessionActive && decryptedAccounts.length > 0) {
      // Auto-fill first account (or show picker if multiple)
      await autofillOTP(tab.id, decryptedAccounts[0].id);
    }
  }
});

console.log('2FA-Vault background service worker loaded');
