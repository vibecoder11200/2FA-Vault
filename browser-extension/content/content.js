/**
 * Content script for 2FA-Vault extension
 * Detects OTP input fields and provides auto-fill functionality
 */

// OTP field detection patterns
const OTP_PATTERNS = [
  /otp/i,
  /code/i,
  /totp/i,
  /2fa/i,
  /two.?factor/i,
  /authentication.?code/i,
  /verification.?code/i,
  /security.?code/i,
  /mfa/i
];

// Track detected OTP fields
let detectedFields = [];
let overlayButton = null;

/**
 * Initialize content script
 */
function init() {
  console.log('2FA-Vault content script loaded');
  
  // Scan page for OTP fields
  scanForOTPFields();
  
  // Watch for dynamically added fields
  const observer = new MutationObserver(() => {
    scanForOTPFields();
  });
  
  observer.observe(document.body, {
    childList: true,
    subtree: true
  });

  // Listen for messages from background
  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message.action === 'fill-otp') {
      fillOTP(message.otp);
      sendResponse({ success: true });
    }
  });
}

/**
 * Scan page for OTP input fields
 */
function scanForOTPFields() {
  const inputs = document.querySelectorAll('input[type="text"], input[type="tel"], input[type="number"], input:not([type])');
  
  detectedFields = [];
  
  inputs.forEach(input => {
    if (isOTPField(input)) {
      detectedFields.push(input);
      
      // Add visual indicator
      markOTPField(input);
    }
  });

  // Show overlay button if OTP fields detected
  if (detectedFields.length > 0 && !overlayButton) {
    showOverlayButton();
  } else if (detectedFields.length === 0 && overlayButton) {
    hideOverlayButton();
  }
}

/**
 * Check if input field is likely an OTP field
 */
function isOTPField(input) {
  // Check input attributes
  const name = input.name || '';
  const id = input.id || '';
  const placeholder = input.placeholder || '';
  const autocomplete = input.autocomplete || '';
  const ariaLabel = input.getAttribute('aria-label') || '';
  
  const text = `${name} ${id} ${placeholder} ${autocomplete} ${ariaLabel}`.toLowerCase();
  
  // Check against patterns
  const matchesPattern = OTP_PATTERNS.some(pattern => pattern.test(text));
  
  if (matchesPattern) return true;
  
  // Check if near password field (common pattern)
  const passwordField = findNearbyPasswordField(input);
  if (passwordField) {
    // Check if this looks like a verification code field
    const maxLength = input.maxLength || input.getAttribute('maxlength');
    if (maxLength && parseInt(maxLength) <= 8) {
      return true;
    }
  }
  
  // Check for numeric-only fields with short max length
  if (input.type === 'number' || input.type === 'tel') {
    const maxLength = input.maxLength || input.getAttribute('maxlength');
    if (maxLength && parseInt(maxLength) <= 8) {
      return true;
    }
  }
  
  return false;
}

/**
 * Find nearby password field
 */
function findNearbyPasswordField(input) {
  const form = input.closest('form');
  if (form) {
    return form.querySelector('input[type="password"]');
  }
  return null;
}

/**
 * Mark OTP field with visual indicator
 */
function markOTPField(input) {
  // Avoid duplicate markers
  if (input.dataset.twoFAVaultMarked) return;
  
  input.dataset.twoFAVaultMarked = 'true';
  
  // Add subtle border highlight
  const originalBorder = input.style.border;
  input.style.border = '2px solid #4CAF50';
  
  // Add focus handler to show autofill option
  input.addEventListener('focus', () => {
    showInlineAutofillButton(input);
  });
  
  input.addEventListener('blur', () => {
    setTimeout(() => {
      hideInlineAutofillButton();
    }, 200);
  });
}

/**
 * Show overlay button for quick autofill
 */
function showOverlayButton() {
  overlayButton = document.createElement('div');
  overlayButton.id = 'twofa-vault-overlay';
  overlayButton.innerHTML = `
    <div style="
      position: fixed;
      bottom: 20px;
      right: 20px;
      width: 56px;
      height: 56px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border-radius: 50%;
      box-shadow: 0 4px 12px rgba(0,0,0,0.3);
      cursor: pointer;
      z-index: 999999;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform 0.2s;
    " title="Autofill OTP from 2FA-Vault">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="white">
        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
      </svg>
    </div>
  `;
  
  overlayButton.addEventListener('click', () => {
    requestAutofill();
  });
  
  overlayButton.addEventListener('mouseenter', (e) => {
    e.target.closest('div').style.transform = 'scale(1.1)';
  });
  
  overlayButton.addEventListener('mouseleave', (e) => {
    e.target.closest('div').style.transform = 'scale(1)';
  });
  
  document.body.appendChild(overlayButton);
}

/**
 * Hide overlay button
 */
function hideOverlayButton() {
  if (overlayButton) {
    overlayButton.remove();
    overlayButton = null;
  }
}

/**
 * Show inline autofill button next to input
 */
function showInlineAutofillButton(input) {
  // Remove existing button
  hideInlineAutofillButton();
  
  const button = document.createElement('button');
  button.id = 'twofa-vault-inline-btn';
  button.type = 'button';
  button.innerHTML = '🔐 Autofill';
  button.style.cssText = `
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    padding: 4px 8px;
    background: #4CAF50;
    color: white;
    border: none;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    z-index: 999999;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
  `;
  
  button.addEventListener('click', (e) => {
    e.preventDefault();
    requestAutofill();
  });
  
  // Position relative to input
  const rect = input.getBoundingClientRect();
  input.parentElement.style.position = 'relative';
  input.parentElement.appendChild(button);
}

/**
 * Hide inline autofill button
 */
function hideInlineAutofillButton() {
  const existing = document.getElementById('twofa-vault-inline-btn');
  if (existing) {
    existing.remove();
  }
}

/**
 * Request autofill from background script
 */
async function requestAutofill() {
  try {
    // Get accounts from background
    const response = await chrome.runtime.sendMessage({
      action: 'get-accounts'
    });

    if (response.error) {
      showNotification('Vault is locked. Please unlock first.', 'error');
      return;
    }

    const accounts = response;

    if (accounts.length === 0) {
      showNotification('No accounts found in vault', 'warning');
      return;
    }

    // If only one account, autofill directly
    if (accounts.length === 1) {
      fillOTP(accounts[0].otp);
      showNotification(`Filled OTP for ${accounts[0].name}`, 'success');
      return;
    }

    // Show account picker if multiple accounts
    showAccountPicker(accounts);

  } catch (error) {
    console.error('Autofill request failed:', error);
    showNotification('Failed to autofill OTP', 'error');
  }
}

/**
 * Fill OTP into detected field
 */
function fillOTP(otp) {
  if (detectedFields.length === 0) {
    showNotification('No OTP field found on this page', 'warning');
    return;
  }

  // Fill first detected field
  const field = detectedFields[0];
  field.value = otp;
  
  // Trigger input events to notify page
  field.dispatchEvent(new Event('input', { bubbles: true }));
  field.dispatchEvent(new Event('change', { bubbles: true }));
  
  // Focus the field
  field.focus();
  
  // Flash success indicator
  const originalBorder = field.style.border;
  field.style.border = '2px solid #4CAF50';
  setTimeout(() => {
    field.style.border = originalBorder;
  }, 1000);
}

/**
 * Show account picker dialog
 */
function showAccountPicker(accounts) {
  const dialog = document.createElement('div');
  dialog.id = 'twofa-vault-picker';
  dialog.style.cssText = `
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
    z-index: 9999999;
    max-width: 400px;
    max-height: 500px;
    overflow-y: auto;
  `;

  let html = '<h3 style="margin-top: 0;">Select Account</h3>';
  html += '<div style="display: flex; flex-direction: column; gap: 8px;">';
  
  accounts.forEach(account => {
    html += `
      <button class="account-option" data-account-id="${account.id}" style="
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        background: white;
        cursor: pointer;
        text-align: left;
        transition: background 0.2s;
      ">
        <div style="font-weight: bold;">${account.name}</div>
        <div style="font-size: 12px; color: #666;">${account.issuer || ''}</div>
        <div style="font-family: monospace; margin-top: 4px; color: #4CAF50;">${account.otp}</div>
      </button>
    `;
  });
  
  html += '</div>';
  html += '<button id="picker-cancel" style="margin-top: 12px; padding: 8px; width: 100%; border: none; background: #f0f0f0; border-radius: 4px; cursor: pointer;">Cancel</button>';
  
  dialog.innerHTML = html;
  
  // Add event listeners
  dialog.querySelectorAll('.account-option').forEach(btn => {
    btn.addEventListener('click', () => {
      const accountId = btn.dataset.accountId;
      const account = accounts.find(a => a.id === accountId);
      fillOTP(account.otp);
      showNotification(`Filled OTP for ${account.name}`, 'success');
      dialog.remove();
    });
    
    btn.addEventListener('mouseenter', (e) => {
      e.target.style.background = '#f5f5f5';
    });
    
    btn.addEventListener('mouseleave', (e) => {
      e.target.style.background = 'white';
    });
  });
  
  dialog.querySelector('#picker-cancel').addEventListener('click', () => {
    dialog.remove();
  });
  
  document.body.appendChild(dialog);
}

/**
 * Show notification toast
 */
function showNotification(message, type = 'info') {
  const colors = {
    success: '#4CAF50',
    error: '#f44336',
    warning: '#ff9800',
    info: '#2196F3'
  };

  const notification = document.createElement('div');
  notification.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    background: ${colors[type]};
    color: white;
    padding: 16px 24px;
    border-radius: 4px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    z-index: 99999999;
    font-family: system-ui, -apple-system, sans-serif;
    font-size: 14px;
    max-width: 300px;
    animation: slideIn 0.3s ease-out;
  `;
  
  notification.textContent = message;
  document.body.appendChild(notification);
  
  setTimeout(() => {
    notification.style.animation = 'slideOut 0.3s ease-in';
    setTimeout(() => notification.remove(), 300);
  }, 3000);
}

// Add animation styles
const style = document.createElement('style');
style.textContent = `
  @keyframes slideIn {
    from {
      transform: translateX(400px);
      opacity: 0;
    }
    to {
      transform: translateX(0);
      opacity: 1;
    }
  }
  
  @keyframes slideOut {
    from {
      transform: translateX(0);
      opacity: 1;
    }
    to {
      transform: translateX(400px);
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
