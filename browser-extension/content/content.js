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

  // Listen for messages from background with origin validation
  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    // Validate message comes from our extension
    if (!sender || !sender.url || !sender.url.startsWith(chrome.runtime.getURL(''))) {
      console.warn('[2FA-Vault] Message from untrusted origin blocked');
      sendResponse({ success: false, error: 'Unauthorized origin' });
      return false;
    }

    if (message.action === 'fill-otp') {
      fillOTP(message.otp);
      sendResponse({ success: true });
      return true;
    }

    return false;
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

  // Create button using DOM methods (XSS prevention)
  const buttonDiv = document.createElement('div');
  buttonDiv.style.cssText = `
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
  `;
  buttonDiv.title = 'Autofill OTP from 2FA-Vault';

  // Create SVG icon safely
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('width', '28');
  svg.setAttribute('height', '28');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'white');

  const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  path.setAttribute('d', 'M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z');
  svg.appendChild(path);
  buttonDiv.appendChild(svg);

  buttonDiv.addEventListener('click', () => {
    requestAutofill();
  });

  buttonDiv.addEventListener('mouseenter', () => {
    buttonDiv.style.transform = 'scale(1.1)';
  });

  buttonDiv.addEventListener('mouseleave', () => {
    buttonDiv.style.transform = 'scale(1)';
  });

  overlayButton.appendChild(buttonDiv);
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

  // Create heading using textContent (XSS prevention)
  const heading = document.createElement('h3');
  heading.textContent = 'Select Account';
  heading.style.marginTop = '0';
  dialog.appendChild(heading);

  // Create accounts container
  const accountsContainer = document.createElement('div');
  accountsContainer.style.cssText = 'display: flex; flex-direction: column; gap: 8px;';

  // Create account buttons using DOM methods
  accounts.forEach(account => {
    const button = document.createElement('button');
    button.className = 'account-option';
    button.dataset.accountId = account.id;
    button.style.cssText = `
      padding: 12px;
      border: 1px solid #ddd;
      border-radius: 4px;
      background: white;
      cursor: pointer;
      text-align: left;
      transition: background 0.2s;
    `;

    // Create account name (safe text content)
    const nameDiv = document.createElement('div');
    nameDiv.style.fontWeight = 'bold';
    nameDiv.textContent = account.name;
    button.appendChild(nameDiv);

    // Create issuer (safe text content)
    const issuerDiv = document.createElement('div');
    issuerDiv.style.fontSize = '12px';
    issuerDiv.style.color = '#666';
    issuerDiv.textContent = account.issuer || '';
    button.appendChild(issuerDiv);

    // Create OTP code (safe text content)
    const otpDiv = document.createElement('div');
    otpDiv.style.fontFamily = 'monospace';
    otpDiv.style.marginTop = '4px';
    otpDiv.style.color = '#4CAF50';
    otpDiv.textContent = account.otp;
    button.appendChild(otpDiv);

    // Add click handler
    button.addEventListener('click', () => {
      const accountId = button.dataset.accountId;
      const acc = accounts.find(a => a.id === accountId);
      if (acc) {
        fillOTP(acc.otp);
        showNotification(`Filled OTP for ${acc.name}`, 'success');
        dialog.remove();
      }
    });

    // Add hover effects
    button.addEventListener('mouseenter', () => {
      button.style.background = '#f5f5f5';
    });

    button.addEventListener('mouseleave', () => {
      button.style.background = 'white';
    });

    accountsContainer.appendChild(button);
  });

  dialog.appendChild(accountsContainer);

  // Create cancel button
  const cancelButton = document.createElement('button');
  cancelButton.id = 'picker-cancel';
  cancelButton.textContent = 'Cancel';
  cancelButton.style.cssText = 'margin-top: 12px; padding: 8px; width: 100%; border: none; background: #f0f0f0; border-radius: 4px; cursor: pointer;';
  cancelButton.addEventListener('click', () => {
    dialog.remove();
  });
  dialog.appendChild(cancelButton);

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
