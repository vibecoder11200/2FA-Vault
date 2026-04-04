# 2FA-Vault Browser Extension

Secure 2FA manager with end-to-end encryption for Chrome and Firefox.

## 🎯 Features

- **🔐 End-to-End Encryption**: All 2FA secrets encrypted with AES-256-GCM
- **🔄 Auto-Fill**: Automatically detect and fill OTP codes on login pages
- **⏱️ TOTP Generation**: Generate time-based one-time passwords with countdown timer
- **☁️ Cloud Sync**: Sync encrypted vault with self-hosted server
- **🌐 Cross-Browser**: Works on Chrome (Manifest V3) and Firefox (Manifest V2)
- **🎨 Clean UI**: Beautiful, intuitive interface with search and filtering
- **⌨️ Keyboard Shortcuts**: Quick autofill with Ctrl+Shift+V (Cmd+Shift+V on Mac)
- **🔒 Auto-Lock**: Vault automatically locks after inactivity

## 📦 Installation

### Chrome

1. **Load Unpacked Extension:**
   - Open Chrome and navigate to `chrome://extensions/`
   - Enable "Developer mode" (top right toggle)
   - Click "Load unpacked"
   - Select the `browser-extension/` directory

2. **Or Build and Install:**
   ```bash
   cd browser-extension
   zip -r 2fa-vault-chrome.zip . -x "*.md" "manifest.firefox.json"
   ```
   - Drag and drop the `.zip` file into `chrome://extensions/`

### Firefox

1. **Temporary Installation (Development):**
   - Open Firefox and navigate to `about:debugging#/runtime/this-firefox`
   - Click "Load Temporary Add-on"
   - Select `manifest.firefox.json` from `browser-extension/` directory

2. **Permanent Installation:**
   - Sign the extension on [addons.mozilla.org](https://addons.mozilla.org/developers/)
   - Install the signed `.xpi` file

## 🚀 Quick Start

### First Time Setup

1. **Install Extension** (see above)

2. **Set Master Password:**
   - Click the extension icon
   - Create a strong master password
   - This password encrypts all your 2FA secrets locally

3. **Add Your First Account:**
   - Click the ➕ button
   - Enter account name (e.g., "GitHub")
   - Enter issuer (optional, e.g., "GitHub Inc.")
   - Enter the **secret key** from your 2FA QR code
   - Click "Add Account"

4. **Configure Server (Optional):**
   - Click ⚙️ Settings
   - Enter your self-hosted server URL
   - Test connection
   - Enable auto-sync

### Daily Usage

1. **Unlock Vault:**
   - Click extension icon
   - Enter master password

2. **Auto-Fill OTP:**
   - Navigate to a login page with 2FA
   - The extension will detect OTP input fields
   - Click the overlay button or press `Ctrl+Shift+V`
   - OTP code is automatically filled

3. **Manual Copy:**
   - Open the extension popup
   - Click "📋 Copy" next to any account
   - Paste the OTP code where needed

## ⚙️ Configuration

### Server Settings

Configure your self-hosted 2FA-Vault server:

```
Server URL: http://localhost:8000
API Token: (optional, for authenticated access)
```

### Sync Settings

- **Auto-Sync Interval**: How often to sync with server (default: 15 minutes)
- **Manual Sync**: Click 🔄 Sync button anytime

### Security Settings

- **Auto-Lock Timeout**: Lock vault after X minutes of inactivity (default: 15)
- **Master Password**: Change your encryption password
- **Clear All Data**: Remove all accounts and settings (⚠️ irreversible!)

### Appearance

- **Theme**: Light, Dark, or System

## 🔐 Security & Privacy

### Encryption

- **Algorithm**: AES-256-GCM (industry standard)
- **Key Derivation**: PBKDF2 with 600,000 iterations (OWASP recommendation)
- **Zero-Knowledge**: Server never sees your master password or decrypted secrets

### Permissions Explained

| Permission | Purpose |
|------------|---------|
| `activeTab` | Detect OTP input fields on the current page |
| `storage` | Store encrypted vault locally |
| `alarms` | Refresh TOTP codes every 30 seconds |
| `notifications` | Notify you of sync status |
| `host_permissions` | Connect to your self-hosted server |

**We do NOT:**
- Track your browsing history
- Send data to third parties
- Store passwords in plaintext
- Access other websites beyond OTP detection

## 🛠️ Development

### File Structure

```
browser-extension/
├── manifest.json              # Chrome Manifest V3
├── manifest.firefox.json      # Firefox Manifest V2
├── popup/                     # Extension popup UI
│   ├── popup.html
│   ├── popup.js
│   └── popup.css
├── background/                # Background service worker
│   └── service-worker.js
├── content/                   # Content script (OTP detection)
│   └── content.js
├── options/                   # Settings page
│   ├── options.html
│   └── options.js
├── shared/                    # Shared utilities
│   ├── crypto.js              # Encryption/decryption
│   ├── api.js                 # API client
│   └── storage.js             # Storage wrapper
└── icons/                     # Extension icons
```

### Testing

1. **Load extension in development mode** (see Installation above)

2. **Open Chrome DevTools:**
   - **Popup**: Right-click extension icon → Inspect
   - **Background**: `chrome://extensions/` → Details → Inspect service worker
   - **Content Script**: Open any webpage → DevTools → Console

3. **Test OTP Detection:**
   - Go to any login page with 2FA (GitHub, Google, etc.)
   - Check console for detection logs
   - Verify overlay button appears on OTP fields

4. **Test Encryption:**
   - Add an account
   - Check `chrome://extensions/` → Details → Inspect storage
   - Verify vault is encrypted (not readable plaintext)

### Building for Production

**Chrome:**
```bash
cd browser-extension
zip -r 2fa-vault-chrome.zip . \
  -x "*.md" "manifest.firefox.json" ".git/*" "node_modules/*"
```

**Firefox:**
```bash
cd browser-extension
zip -r 2fa-vault-firefox.zip . \
  -x "*.md" "manifest.json" ".git/*" "node_modules/*"
mv manifest.firefox.json manifest.json  # Rename for Firefox
```

Then submit to:
- **Chrome**: [Chrome Web Store Developer Dashboard](https://chrome.google.com/webstore/devconsole)
- **Firefox**: [addons.mozilla.org](https://addons.mozilla.org/developers/)

## 🐛 Troubleshooting

### Extension Won't Load

- Check browser console for errors
- Ensure all required files are present
- Verify `manifest.json` syntax (use a JSON validator)

### OTP Fields Not Detected

- Refresh the page
- Check if the input field matches detection patterns
- Open content script console and look for errors

### Sync Failing

- Verify server URL in settings
- Test connection button
- Check server logs for API errors
- Ensure server is running and accessible

### Vault Won't Unlock

- Double-check master password
- If you forgot it, there's no recovery (E2EE design)
- You'll need to clear all data and start over

## 📝 Known Limitations

- **TOTP Only**: Currently supports TOTP (time-based), not HOTP (counter-based)
- **No QR Code Scanner**: Must manually enter secret keys
- **Basic Sync**: No conflict resolution (server overwrites local)
- **Argon2 Fallback**: Uses PBKDF2 instead of Argon2id (browser limitation)

## 🔮 Roadmap

- [ ] QR code scanning
- [ ] HOTP support
- [ ] Biometric unlock (WebAuthn)
- [ ] Export/import vault
- [ ] Browser fingerprint for additional security
- [ ] Progressive Web App version

## 📄 License

MIT License - see main project LICENSE

## 🙏 Credits

Part of the [2FA-Vault](../) project - a secure, self-hosted 2FA manager.

---

**⚠️ Security Notice**: This extension handles sensitive authentication data. Always:
- Use a strong master password
- Keep your browser updated
- Only install from trusted sources
- Review code before use in production
