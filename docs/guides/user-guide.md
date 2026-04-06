# 2FA-Vault User Guide

Complete guide for end users of 2FA-Vault.

---

## Table of Contents

1. [Getting Started](#getting-started)
2. [Account Setup](#account-setup)
3. [Adding 2FA Accounts](#adding-2fa-accounts)
4. [Managing Your Accounts](#managing-your-accounts)
5. [Using Groups](#using-groups)
6. [End-to-End Encryption (E2EE)](#end-to-end-encryption-e2ee)
7. [Teams & Collaboration](#teams--collaboration)
8. [Backup & Restore](#backup--restore)
9. [Browser Extension](#browser-extension)
10. [PWA Features](#pwa-features)
11. [Security Best Practices](#security-best-practices)
12. [FAQ](#faq)

---

## Getting Started

### What is 2FA-Vault?

2FA-Vault is a secure, self-hosted application for managing your Two-Factor Authentication (2FA) accounts. Unlike other authenticator apps that store your data on your phone, 2FA-Vault:

- **Runs in your browser** - Access from any device
- **Encrypts everything** - Zero-knowledge end-to-end encryption
- **Supports teams** - Share accounts securely with colleagues
- **Works offline** - Progressive Web App with offline support
- **Open source** - Fully self-hosted, you control your data

### First Time Setup

1. **Create your account**
   - Enter your name and email
   - Create a strong password (use a password manager!)
   - Verify your email if required

2. **Set up E2EE** (recommended)
   - Create a master password for encryption
   - This password encrypts all your 2FA secrets
   - **Never share this password with anyone**

3. **Add your first account**
   - Scan a QR code or enter manually
   - Your account is immediately ready to use

---

## Account Setup

### Registration

| Field | Description |
|-------|-------------|
| **Name** | Your display name (visible to team members) |
| **Email** | Used for login and team invitations |
| **Password** | Min 8 characters, use a unique strong password |

### Login Options

- **Password** - Traditional email/password login
- **WebAuthn** - Passwordless login with security key (YubiKey, etc.)
- **SSO** - Single sign-on if enabled by your organization

### Passwordless Login with WebAuthn

1. Go to Settings → Security
2. Click "Add Security Key"
3. Follow your browser's prompts to register your device
4. Enable "Passwordless Login" to skip password entry

---

## Adding 2FA Accounts

### Quick Add (QR Code)

1. Click the "+" button or "Add Account"
2. Select "Scan QR Code"
3. Allow camera access when prompted
4. Point camera at the QR code
5. Account is added automatically

**Works with:** Google Authenticator, Authy, Microsoft, GitHub, and any TOTP/HOTP service

### Manual Entry

Use this when you don't have a QR code:

1. Click "+" then "Add Manually"
2. Fill in the fields:

| Field | Description | Example |
|-------|-------------|---------|
| **Service** | Website or app name | `GitHub` |
| **Account** | Username or email | `user@example.com` |
| **Secret** | Base32 secret key | `JBSWY3DPEHPK3PXP` |
| **Type** | TOTP or HOTP | `TOTP` (most common) |
| **Digits** | Code length (usually 6) | `6` |
| **Period** | Seconds between codes | `30` |
| **Algorithm** | Hash algorithm | `SHA1` |

3. Click "Save"

### Import from Other Apps

**Supported formats:**
- 2FAuth (JSON)
- Google Authenticator (QR code)
- Aegis (JSON, plain text)
- 2FAS Auth (JSON)
- AndOTP (JSON)

1. Go to Settings → Import
2. Choose your export file or scan QR code
3. Review imported accounts
4. Click "Import" to complete

---

## Managing Your Accounts

### Viewing OTP Codes

- **Copy to clipboard** - Click the code
- **Auto-hide** - Codes hidden by default (enable in settings)
- **Countdown timer** - Shows time remaining until next code

### Editing Accounts

1. Click the account to edit
2. Modify service name, icon, or other details
3. Save changes

### Deleting Accounts

1. Click the account → Delete
2. Confirm deletion
3. **Account is permanently removed** (cannot be undone)

### Reordering Accounts

Drag and drop accounts to arrange them in your preferred order.

---

## Using Groups

Groups help organize your accounts by category.

### Creating Groups

1. Click "Groups" in the sidebar
2. Click "New Group"
3. Enter a name (e.g., "Work", "Personal", "Finance")
4. Click "Create"

### Assigning Accounts to Groups

1. Select one or more accounts
2. Click "Move to Group"
3. Choose the destination group

### Reordering Groups

Drag and drop groups to customize their display order.

---

## End-to-End Encryption (E2EE)

### Why Enable E2EE?

With E2EE enabled:
- Your 2FA secrets are encrypted **before** leaving your browser
- The server stores only encrypted data (cannot decrypt)
- Even if the server is compromised, your secrets remain safe
- You control the encryption key

### Setting Up E2EE

1. Go to Settings → Security → Encryption
2. Click "Enable Encryption"
3. Create a **master password** (this is your encryption key)
4. Enter a password hint (optional, but recommended)
5. Click "Enable"

**⚠️ Important:**
- Your master password **cannot be recovered**
- If you forget it, you'll need to re-add all accounts
- Write down your password hint or use a password manager

### How It Works

1. **Key Derivation** - Master password + salt → encryption key (Argon2id)
2. **Client Encryption** - Secrets encrypted with AES-256-GCM
3. **Server Storage** - Only encrypted data stored on server
4. **On Demand** - Secrets decrypted in browser when needed

### Locking Your Vault

- **Manual lock** - Click your avatar → "Lock Vault"
- **Auto-lock** - Set inactivity timeout in settings
- **After copying** - Auto-lock after copying a code (optional)

### Unlocking Your Vault

1. Enter your master password
2. Vault unlocks for your session
3. Codes display normally

---

## Teams & Collaboration

### Creating a Team

1. Go to Settings → Teams
2. Click "Create Team"
3. Enter team name
4. Click "Create"

You are automatically the **team owner**.

### Team Roles

| Role | Permissions |
|------|-------------|
| **Owner** | Full control, delete team, manage all members |
| **Admin** | Invite/remove members, update roles |
| **Member** | View and use shared accounts |
| **Viewer** | View shared accounts only (read-only) |

### Inviting Members

1. Go to your team
2. Click "Invite Member"
3. Enter their email
4. Select their role
5. Click "Send Invitation"

The invitee receives an email with a join link.

### Joining a Team

**Via Email Invite:**
1. Click the link in the invitation email
2. Sign in or create an account
3. Team is added to your account

**Via Invite Code:**
1. Go to Settings → Teams → "Join Team"
2. Enter the invite code
3. Click "Join"

### Sharing Accounts

Shared accounts appear in your account list with a team icon:
- 👤 Personal - only you can see
- 👥 Team - shared with your team

---

## Backup & Restore

### Encrypted Backups

Backups are double-encrypted for maximum security:
1. **Client-side** - Encrypted with your backup password
2. **AES-256-GCM** - Military-grade encryption

### Creating a Backup

1. Go to Settings → Backup
2. Click "Export Backup"
3. Create a **backup password** (different from your master password!)
4. Click "Export"
5. Save the `.vault` file somewhere safe

**⚠️ Important:**
- Store backups securely (encrypted drive, password manager)
- Your backup password **cannot be recovered**
- Keep at least 2 backup copies in different locations

### Restoring from Backup

1. Go to Settings → Backup → Import
2. Select your `.vault` file
3. Enter your backup password
4. Choose import mode:
   - **Replace** - Delete all existing accounts, replace with backup
   - **Merge** - Add backup accounts to existing ones
5. Click "Import"

### Backup Best Practices

- **Frequency** - Backup monthly or after adding important accounts
- **Locations** - Store in multiple secure locations
- **Testing** - Test restore periodically to verify backups work
- **Versions** - Keep multiple backup versions (in case of corruption)

---

## Browser Extension

### Installation

**Chrome/Edge:**
1. Visit Chrome Web Store
2. Search "2FA-Vault"
3. Click "Add to Chrome"

**Firefox:**
1. Visit Firefox Add-ons
2. Search "2FA-Vault"
3. Click "Add to Firefox"

### First-Time Setup

1. Click the extension icon
2. Enter your 2FA-Vault URL
3. Sign in to your account
4. Grant necessary permissions

### Using the Extension

**Auto-Fill OTP Codes:**
1. Visit a login page
2. Extension icon highlights if a matching account exists
3. Click the icon → code is copied
4. Paste in the OTP field

**Account Picker:**
1. Click the extension icon
2. See all your matching accounts
3. Click to copy code

### Extension Permissions

The extension requires:
- **Read website access** - Detect login pages
- **Clipboard access** - Copy OTP codes
- **Storage access** - Cache encrypted data

---

## PWA Features

2FA-Vault is a Progressive Web App (PWA) that works offline.

### Installing the PWA

**Chrome/Edge:**
1. Visit your 2FA-Vault URL
2. Click the install icon in the address bar
3. Click "Install"

**Firefox:**
1. Open the app menu (⋮)
2. Click "Install App"

**iOS Safari:**
1. Tap the Share button
2. Tap "Add to Home Screen"

**Android Chrome:**
1. Tap the menu (⋮)
2. Tap "Install App" or "Add to Home Screen"

### Offline Usage

When offline:
- ✅ View existing accounts and OTP codes
- ✅ Copy codes to clipboard
- ❌ Add/edit/delete accounts (requires connection)
- ❌ Use E2EE unlock (requires connection)

Changes sync automatically when you reconnect.

---

## Security Best Practices

### Master Password

- ✅ Use a unique, strong password (16+ characters)
- ✅ Use a password manager to store it
- ✅ Write down a hint in case you forget
- ❌ Don't reuse your master password anywhere
- ❌ Don't share it with anyone

### Device Security

- ✅ Lock your device when not in use
- ✅ Use device encryption (BitLocker, FileVault)
- ✅ Keep browser and OS updated
- ❌ Don't use public computers to access

### Backup Password

- ✅ Use a different password than your master password
- ✅ Store it securely (password manager, safe deposit box)
- ❌ Don't store it with the backup file

### Team Security

- ✅ Only invite trusted members
- ✅ Use appropriate roles (not everyone needs Admin)
- ✅ Regularly review team membership
- ❌ Don't share accounts via email/chat

### General Security

| Practice | Why |
|----------|-----|
| Enable 2FA on 2FA-Vault | Protects your account |
| Use HTTPS only | Prevents man-in-the-middle attacks |
| Auto-lock after inactivity | Limits exposure if device is stolen |
| Regular backups | Protects against data loss |
| Monitor account activity | Detect unauthorized access |

---

## FAQ

### General Questions

**Q: Is 2FA-Vault free?**
A: Yes, 2FA-Vault is free and open source. You can self-host it at no cost.

**Q: Can I use 2FA-Vault on multiple devices?**
A: Yes! Access from any device with a browser. Your data syncs automatically.

**Q: What happens if I lose my master password?**
A: You cannot recover your master password. You'll need to disable encryption, re-add accounts, and enable E2EE again with a new password.

**Q: Is my data safe if the server is hacked?**
A: With E2EE enabled, yes. Your secrets are encrypted before they reach the server, so even a compromised server cannot read them.

### Encryption Questions

**Q: Should I enable E2EE?**
A: Yes, highly recommended. E2EE protects your data even if the server is compromised.

**Q: Can I change my master password?**
A: Not directly. You would need to disable E2EE, re-enable it, and re-encrypt all accounts.

**Q: What encryption does 2FA-Vault use?**
A: Argon2id for key derivation and AES-256-GCM for encryption.

### Team Questions

**Q: Can team members see my personal accounts?**
A: No, personal accounts are only visible to you.

**Q: Can I transfer team ownership?**
A: Yes, the current owner can transfer ownership to another admin member.

**Q: What happens to shared accounts if I leave a team?**
A: They remain with the team. You'll lose access to them.

### Backup Questions

**Q: How often should I backup?**
A: Monthly or whenever you add important accounts.

**Q: Can I restore a backup to a different 2FA-Vault instance?**
A: Yes, backups work across any 2FA-Vault instance.

**Q: What if I lose my backup password?**
A: You cannot recover it. The backup is useless without the password.

### Technical Questions

**Q: Does 2FA-Vault work offline?**
A: Yes, as a PWA. You can view codes offline, but adding/editing requires connection.

**Q: What browsers are supported?**
A: Any modern browser with Web Crypto API support: Chrome, Firefox, Safari, Edge.

**Q: Can I import from Google Authenticator?**
A: Yes, scan the QR code from Google Authenticator or export a JSON file (if available).

---

## Getting Help

- **Documentation** - [docs/](../README.md)
- **Issues** - [GitHub Issues](https://github.com/your-org/2FA-Vault/issues)
- **Discussions** - [GitHub Discussions](https://github.com/your-org/2FA-Vault/discussions)
- **Security** - Report vulnerabilities via GitHub Security Advisory

---

**Version:** 1.0.0
**Last Updated:** April 2026
