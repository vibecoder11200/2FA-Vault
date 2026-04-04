/**
 * Popup UI logic for 2FA-Vault extension
 */

// DOM elements
const lockScreen = document.getElementById('lock-screen');
const mainContent = document.getElementById('main-content');
const unlockForm = document.getElementById('unlock-form');
const masterPasswordInput = document.getElementById('master-password');
const unlockError = document.getElementById('unlock-error');
const accountList = document.getElementById('account-list');
const emptyState = document.getElementById('empty-state');
const searchInput = document.getElementById('search-input');
const addAccountBtn = document.getElementById('add-account-btn');
const addFirstAccountBtn = document.getElementById('add-first-account');
const lockBtn = document.getElementById('lock-btn');
const syncBtn = document.getElementById('sync-btn');
const settingsBtn = document.getElementById('settings-btn');
const addAccountModal = document.getElementById('add-account-modal');
const addAccountForm = document.getElementById('add-account-form');
const closeModalBtn = document.getElementById('close-modal');
const cancelAddBtn = document.getElementById('cancel-add');
const statusIndicator = document.getElementById('status-indicator');
const statusText = document.getElementById('status-text');

// State
let accounts = [];
let refreshInterval = null;

/**
 * Initialize popup
 */
async function init() {
  // Check vault status
  const status = await sendMessage({ action: 'get-status' });
  
  updateConnectionStatus(status.connected);
  
  if (status.locked) {
    showLockScreen();
  } else {
    await showMainContent();
  }

  setupEventListeners();
  startAutoRefresh();
}

/**
 * Setup event listeners
 */
function setupEventListeners() {
  // Unlock form
  unlockForm.addEventListener('submit', handleUnlock);

  // Search
  searchInput.addEventListener('input', handleSearch);

  // Add account
  addAccountBtn.addEventListener('click', showAddAccountModal);
  addFirstAccountBtn.addEventListener('click', showAddAccountModal);
  addAccountForm.addEventListener('submit', handleAddAccount);
  closeModalBtn.addEventListener('click', hideAddAccountModal);
  cancelAddBtn.addEventListener('click', hideAddAccountModal);

  // Lock vault
  lockBtn.addEventListener('click', handleLock);

  // Sync
  syncBtn.addEventListener('click', handleSync);

  // Settings
  settingsBtn.addEventListener('click', openSettings);

  // Listen for background updates
  chrome.runtime.onMessage.addListener((message) => {
    if (message.type === 'totp-updated') {
      refreshAccounts();
    }
  });
}

/**
 * Send message to background script
 */
async function sendMessage(message) {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage(message, (response) => {
      if (response && response.error) {
        reject(new Error(response.error));
      } else {
        resolve(response);
      }
    });
  });
}

/**
 * Show lock screen
 */
function showLockScreen() {
  lockScreen.classList.remove('hidden');
  mainContent.classList.add('hidden');
  masterPasswordInput.value = '';
  unlockError.classList.add('hidden');
  masterPasswordInput.focus();
}

/**
 * Show main content
 */
async function showMainContent() {
  lockScreen.classList.add('hidden');
  mainContent.classList.remove('hidden');
  await refreshAccounts();
}

/**
 * Handle unlock
 */
async function handleUnlock(e) {
  e.preventDefault();
  
  const masterPassword = masterPasswordInput.value;
  
  try {
    unlockError.classList.add('hidden');
    const result = await sendMessage({
      action: 'unlock-vault',
      data: { masterPassword }
    });

    if (result.success) {
      await showMainContent();
    }
  } catch (error) {
    unlockError.textContent = error.message;
    unlockError.classList.remove('hidden');
    masterPasswordInput.value = '';
    masterPasswordInput.focus();
  }
}

/**
 * Handle lock
 */
async function handleLock() {
  await sendMessage({ action: 'lock-vault' });
  accounts = [];
  showLockScreen();
}

/**
 * Refresh accounts from background
 */
async function refreshAccounts() {
  try {
    accounts = await sendMessage({ action: 'get-accounts' });
    renderAccounts(accounts);
  } catch (error) {
    console.error('Failed to refresh accounts:', error);
    showLockScreen();
  }
}

/**
 * Render accounts list
 */
function renderAccounts(accountsToRender) {
  accountList.innerHTML = '';

  if (accountsToRender.length === 0) {
    emptyState.classList.remove('hidden');
    accountList.classList.add('hidden');
    return;
  }

  emptyState.classList.add('hidden');
  accountList.classList.remove('hidden');

  accountsToRender.forEach(account => {
    const item = createAccountItem(account);
    accountList.appendChild(item);
  });
}

/**
 * Create account list item
 */
function createAccountItem(account) {
  const div = document.createElement('div');
  div.className = 'account-item';
  div.dataset.accountId = account.id;

  const isExpiring = account.timeRemaining < 10;

  div.innerHTML = `
    <div class="account-header">
      <div class="account-info">
        <h3>${escapeHtml(account.name)}</h3>
        ${account.issuer ? `<p>${escapeHtml(account.issuer)}</p>` : ''}
      </div>
      <div class="account-actions">
        <button class="btn btn-small btn-secondary" data-action="copy">
          📋 Copy
        </button>
      </div>
    </div>
    <div class="otp-display">
      <div class="otp-code">${formatOTP(account.otp)}</div>
      <div class="otp-timer">
        <div class="timer-circle ${isExpiring ? 'warning' : ''}">
          ${account.timeRemaining}
        </div>
      </div>
    </div>
  `;

  // Copy button
  const copyBtn = div.querySelector('[data-action="copy"]');
  copyBtn.addEventListener('click', () => copyOTP(account.otp, account.name));

  return div;
}

/**
 * Format OTP code (add space in middle)
 */
function formatOTP(otp) {
  if (otp.length === 6) {
    return `${otp.substring(0, 3)} ${otp.substring(3)}`;
  }
  return otp;
}

/**
 * Copy OTP to clipboard
 */
async function copyOTP(otp, accountName) {
  try {
    await navigator.clipboard.writeText(otp);
    showToast(`Copied OTP for ${accountName}`, 'success');
  } catch (error) {
    // Fallback for older browsers
    const input = document.createElement('input');
    input.value = otp;
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    document.body.removeChild(input);
    showToast(`Copied OTP for ${accountName}`, 'success');
  }
}

/**
 * Handle search
 */
function handleSearch(e) {
  const query = e.target.value.toLowerCase();
  
  const filtered = accounts.filter(account => {
    const name = account.name.toLowerCase();
    const issuer = (account.issuer || '').toLowerCase();
    return name.includes(query) || issuer.includes(query);
  });

  renderAccounts(filtered);
}

/**
 * Show add account modal
 */
function showAddAccountModal() {
  addAccountModal.classList.remove('hidden');
  document.getElementById('account-name').focus();
}

/**
 * Hide add account modal
 */
function hideAddAccountModal() {
  addAccountModal.classList.add('hidden');
  addAccountForm.reset();
}

/**
 * Handle add account
 */
async function handleAddAccount(e) {
  e.preventDefault();

  const name = document.getElementById('account-name').value.trim();
  const issuer = document.getElementById('account-issuer').value.trim();
  const secret = document.getElementById('account-secret').value.trim().replace(/\s/g, '');

  try {
    await sendMessage({
      action: 'add-account',
      data: {
        account: { name, issuer, secret }
      }
    });

    hideAddAccountModal();
    await refreshAccounts();
    showToast('Account added successfully', 'success');
  } catch (error) {
    showToast(error.message, 'error');
  }
}

/**
 * Handle sync
 */
async function handleSync() {
  const syncIcon = document.getElementById('sync-icon');
  const syncText = document.getElementById('sync-text');

  try {
    syncIcon.classList.add('spinning');
    syncText.textContent = 'Syncing...';

    const result = await sendMessage({ action: 'sync-now' });

    if (result.success) {
      showToast('Synced successfully', 'success');
      await refreshAccounts();
    } else if (result.error) {
      showToast(result.error, 'error');
    }
  } catch (error) {
    showToast('Sync failed', 'error');
  } finally {
    syncIcon.classList.remove('spinning');
    syncText.textContent = 'Sync';
  }
}

/**
 * Open settings page
 */
function openSettings() {
  chrome.runtime.openOptionsPage();
}

/**
 * Update connection status indicator
 */
function updateConnectionStatus(connected) {
  if (connected) {
    statusIndicator.classList.add('connected');
    statusText.textContent = 'Connected';
  } else {
    statusIndicator.classList.remove('connected');
    statusText.textContent = 'Offline';
  }
}

/**
 * Start auto-refresh timer
 */
function startAutoRefresh() {
  // Refresh every second to update countdown timers
  refreshInterval = setInterval(() => {
    if (accounts.length > 0) {
      // Update timers without re-fetching from background
      const items = accountList.querySelectorAll('.account-item');
      items.forEach((item, index) => {
        if (accounts[index]) {
          accounts[index].timeRemaining--;
          if (accounts[index].timeRemaining <= 0) {
            // Time to refresh OTP codes
            refreshAccounts();
            return;
          }
          
          const timer = item.querySelector('.timer-circle');
          if (timer) {
            timer.textContent = accounts[index].timeRemaining;
            
            // Add warning class if expiring soon
            if (accounts[index].timeRemaining < 10) {
              timer.classList.add('warning');
            } else {
              timer.classList.remove('warning');
            }
          }
        }
      });
    }
  }, 1000);
}

/**
 * Show toast notification
 */
function showToast(message, type = 'info') {
  const toast = document.createElement('div');
  toast.className = `${type}-message`;
  toast.textContent = message;
  toast.style.cssText = `
    position: fixed;
    top: 80px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 10000;
    padding: 12px 20px;
    border-radius: 6px;
    font-size: 13px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    animation: slideDown 0.3s ease-out;
  `;

  document.body.appendChild(toast);

  setTimeout(() => {
    toast.style.animation = 'slideUp 0.3s ease-in';
    setTimeout(() => toast.remove(), 300);
  }, 2000);
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Add animation styles
const style = document.createElement('style');
style.textContent = `
  @keyframes slideDown {
    from {
      transform: translate(-50%, -20px);
      opacity: 0;
    }
    to {
      transform: translate(-50%, 0);
      opacity: 1;
    }
  }
  
  @keyframes slideUp {
    from {
      transform: translate(-50%, 0);
      opacity: 1;
    }
    to {
      transform: translate(-50%, -20px);
      opacity: 0;
    }
  }
`;
document.head.appendChild(style);

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
