# 2FA-Vault

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg?style=flat-square)
![Build Status](https://img.shields.io/badge/build-passing-brightgreen.svg?style=flat-square)
![License](https://img.shields.io/badge/license-AGPL--3.0-blue.svg?style=flat-square)
![PHP](https://img.shields.io/badge/PHP-^8.4-777BB4.svg?style=flat-square&logo=php)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20.svg?style=flat-square&logo=laravel)
![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D.svg?style=flat-square&logo=vue.js)
![TypeScript](https://img.shields.io/badge/TypeScript-5-3178C6.svg?style=flat-square&logo=typescript)

## 🔒 Enhanced Fork with Enterprise Features

**2FA-Vault** is an enhanced fork of [2FAuth](https://github.com/Bubka/2FAuth) with additional enterprise-grade features:

- 🔐 **End-to-End Encryption (E2EE)**: Client-side encryption with Web Crypto API, PBKDF2 key derivation, and AES-256-GCM
- 👥 **Multi-User & Team Management**: Role-based access control, team collaboration, and secure sharing
- 🧩 **Browser Extension**: Chrome/Firefox extension for seamless OTP access across websites
- 📱 **Progressive Web App (PWA)**: Offline-first architecture with background sync and push notifications
- 💾 **Encrypted Backups**: Double-encrypted backup files with separate password protection
- 🚀 **Modern Tech Stack**: Laravel 12 + Vue 3 + TypeScript

### 📚 Documentation

- 🏗️ [**ARCHITECTURE.md**](ARCHITECTURE.md) - Technical architecture and system design
- 🔒 [**SECURITY.md**](SECURITY.md) - Security architecture, threat model, and best practices
- 🔄 [**MIGRATION.md**](MIGRATION.md) - Migration guide from 2FAuth to 2FA-Vault
- 📝 [**CHANGELOG.md**](CHANGELOG.md) - Version history and breaking changes
- 🗺️ [**ROADMAP.md**](ROADMAP.md) - Development roadmap and planned features

---

## 📊 Feature Comparison

| Feature | 2FAuth | 2FA-Vault |
|---------|--------|-----------|
| TOTP/HOTP Generation | ✅ | ✅ |
| QR Code Import | ✅ | ✅ |
| Groups & Organization | ✅ | ✅ |
| Data Encryption | ⚠️ Optional | ✅ **End-to-End (Mandatory)** |
| Multi-User Support | ❌ | ✅ **Full Multi-User + Teams** |
| Team Collaboration | ❌ | ✅ **Role-Based Access Control** |
| Browser Extension | ❌ | ✅ **Chrome/Firefox** |
| Progressive Web App | ❌ | ✅ **Offline Support** |
| Encrypted Backups | ❌ | ✅ **Double Encryption** |
| Push Notifications | ❌ | ✅ **Web Push API** |
| Zero-Knowledge Architecture | ❌ | ✅ **Full Zero-Knowledge** |

---

## 🚀 Quick Start

### Docker (Recommended)

```bash
# Clone the repository
git clone https://github.com/yourusername/2FA-Vault.git
cd 2FA-Vault

# Copy and configure environment
cp .env.example .env
# Edit .env with your settings

# Start with Docker Compose
docker-compose up -d

# Access at http://localhost:8000
```

### Manual Installation

See [Installation Guide](#installation) below for detailed instructions.

---

---

## About 2FAuth

A web app to manage your Two-Factor Authentication (2FA) accounts and generate their security codes

![screens](https://user-images.githubusercontent.com/858858/100485897-18c21400-3102-11eb-9c72-ea0b1b46ef2e.png)

[**2FAuth Demo**](https://demo.2fauth.app/)  
Credentials (login - password) : `demo@2fauth.app` - `demo`

## Purpose

2FAuth is a web based self-hosted alternative to One Time Passcode (OTP) generators like Google Authenticator, designed for both mobile and desktop.

It aims to ease you perform your 2FA authentication steps whatever the device you handle, with a clean and suitable interface.

I created it because :

* Most of the UIs for this kind of apps show tokens for all accounts in the same time with stressful countdowns (in my opinion)
* I wanted my 2FA accounts to be stored in a standalone database I can easily backup and restore (did you already encountered a smartphone loss with all your 2FA accounts in Google Auth? I did...)
* I hate taking out my smartphone to get an OTP when I use a desktop computer
* I love coding and I love self-hosted solutions

## Main features

* Manage your 2FA accounts and organize them using Groups
* Scan and decode any QR code to add account in no time
* Add custom account without QR code thanks to an advanced form
* Edit accounts, even the imported ones
* Generate TOTP and HOTP security codes and Steam Guard codes

2FAuth is currently fully localized in English and French. See [Contributing](#contributing) if you want to help on adding more languages.

## Security

2FAuth provides several security mechanisms to protect your 2FA data as best as possible.

### Single user app

You have to create a user account and authenticate yourself to use the app. It is not possible to create more than one user account, the app is thought for personal use.

### Modern authentication

You can sign in 2FAuth using a security key like a Yubikey or a Titan key and disable the traditional login form.

### Data encryption

Sensitive data stored in the database can be encrypted to protect them against db compromise. Encryption is provided as an option which is disabled by default. It is strongly recommended to backup the APP_KEY value of your .env file (or the whole file) when encryption is On.

### Auto logout

2FAuth automatically log you out after an inactivity period to prevent long life session. The auto logout can be deactivated or triggered when a security code is copied.

### RFC compliance

2FAuth generates OTP according to RFC 4226 (HOTP Algorithm) and RFC 6238 (TOTP Algorithm) thanks to [Spomky-Labs/OTPHP](https://github.com/Spomky-Labs/otphp) php library.

## Requirements

* [![Requires PHP8](https://img.shields.io/badge/php-^8.4-red.svg?style=flat-square)](https://secure.php.net/downloads.php)
* See [Laravel server requirements](https://laravel.com/docs/installation#server-requirements)
* Any database [supported by Laravel](https://laravel.com/docs/database)

## Installation guides

### 🐳 Docker Installation (Production)

**Prerequisites:**
- Docker 20.10+
- Docker Compose v2.0+

```bash
# Clone the repository
git clone https://github.com/yourusername/2FA-Vault.git
cd 2FA-Vault

# Copy production environment
cp .env.example .env

# Configure your environment
nano .env  # Set APP_URL, DB credentials, etc.

# Start services
docker-compose -f docker-compose.prod.yml up -d

# Generate app key
docker-compose exec app php artisan key:generate

# Run migrations
docker-compose exec app php artisan migrate --force

# Create first user
docker-compose exec app php artisan user:create
```

**Access:** Open `http://your-domain.com` (or configured APP_URL)

### 💻 Manual Installation

**Prerequisites:**
- PHP 8.4+
- Composer 2.0+
- Node.js 18+ & npm
- MySQL 8.0+ / PostgreSQL 13+ / SQLite 3.8+

```bash
# Clone and install
git clone https://github.com/yourusername/2FA-Vault.git
cd 2FA-Vault

# Backend setup
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate

# Configure database in .env
nano .env

# Run migrations
php artisan migrate --force

# Frontend setup
npm install
npm run build

# Set permissions
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Start server (development)
php artisan serve
```

For production deployment with Nginx/Apache, see [Deployment Guide](https://docs.2fauth.app/getting-started/installation/self-hosted-server/).

---

## ⚙️ Configuration

### Environment Variables

Key variables to configure in `.env`:

```env
# Application
APP_NAME=2FA-Vault
APP_URL=http://localhost:8000
APP_ENV=production
APP_DEBUG=false

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=2fa_vault
DB_USERNAME=root
DB_PASSWORD=your_password

# Cache & Sessions (Redis recommended for production)
CACHE_DRIVER=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# E2EE Settings
E2EE_ENABLED=true
E2EE_PBKDF2_ITERATIONS=100000

# Push Notifications
VAPID_PUBLIC_KEY=your_vapid_public_key
VAPID_PRIVATE_KEY=your_vapid_private_key

# Rate Limiting
RATE_LIMIT_LOGIN=5  # Max login attempts per minute
RATE_LIMIT_API=60   # Max API requests per minute
```

### Generate VAPID Keys (for PWA push notifications)

```bash
php artisan webpush:vapid
```

---

## 📈 Upgrading from 2FAuth

### Migration Steps

1. **Backup Your Data**
   ```bash
   # Export from 2FAuth
   # Go to Settings → Backup → Export all accounts
   # Save the JSON file
   ```

2. **Install 2FA-Vault**
   Follow the [Installation Guide](#installation-guides) above.

3. **Import Data**
   ```bash
   # Login to 2FA-Vault
   # Go to Settings → Import
   # Upload your 2FAuth JSON backup
   # Choose "Merge" mode to keep existing data
   ```

4. **Verify & Enable E2EE**
   - Check all accounts imported correctly
   - Go to Settings → Security → Enable E2EE
   - Set your master encryption password
   - **Important:** All data will be re-encrypted client-side

### Breaking Changes

| Change | Impact | Migration |
|--------|--------|-----------|
| E2EE Required | All data must be encrypted | One-time re-encryption on enable |
| Multi-User | Single user → Multi-user | Original account becomes owner |
| Database Schema | New tables added | Auto-migrated via `php artisan migrate` |
| Browser Extension | New feature | Optional, install from Chrome/Firefox store |

See [MIGRATION.md](MIGRATION.md) for detailed migration guide and rollback instructions.

---

## 🖼️ Screenshots

> 📸 Screenshots coming soon! See [2FAuth Demo](https://demo.2fauth.app/) for UI preview.

**New Features Preview:**
- 🔐 E2EE Encryption Dashboard
- 👥 Team Management Interface
- 🧩 Browser Extension Popup
- 📱 PWA Install Prompt
- 💾 Encrypted Backup Export

---

## Upgrading

* [Upgrade guide](https://docs.2fauth.app/getting-started/upgrade/)

## Migration

2FAuth supports importing from the following formats: 2FAuth (JSON), Google Auth (QR code), Aegis Auth (JSON, plain text), 2FAS Auth (JSON)

* [Import guide](https://docs.2fauth.app/getting-started/usage/import/)

## Contributing

You can contribute to 2FA-Vault in many ways:

* 🐛 **Bug Reports:** [Submit issues](https://github.com/yourusername/2FA-Vault/issues/new?template=bug_report.md) with detailed reproduction steps
* ✨ **Feature Requests:** [Suggest enhancements](https://github.com/yourusername/2FA-Vault/issues/new?template=feature_request.md) that align with our security-first approach
* 🔧 **Pull Requests:** Submit fixes or features on the `develop` branch (see [CONTRIBUTING.md](CONTRIBUTING.md))
* 🌍 **Translations:** Help translate 2FA-Vault on [Crowdin](https://crowdin.com/project/2fauth)
* 🔒 **Security:** Report vulnerabilities responsibly (see [SECURITY.md](SECURITY.md))

**Development Setup:**
```bash
git clone https://github.com/yourusername/2FA-Vault.git
cd 2FA-Vault
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev  # Frontend hot-reload
php artisan serve
```

---

## 📄 License

[AGPL-3.0](https://www.gnu.org/licenses/agpl-3.0.html) - Same as original 2FAuth

**Key Points:**
- ✅ Free to use, modify, and distribute
- ✅ Must disclose source code
- ✅ Must use same license for derivatives
- ❌ No warranty provided

---

## 🙏 Acknowledgments

- **Original 2FAuth:** [Bubka/2FAuth](https://github.com/Bubka/2FAuth) - Thank you for the solid foundation!
- **Laravel Framework:** [Laravel](https://laravel.com/)
- **Vue.js:** [Vue.js](https://vuejs.org/)
- **OTPHP:** [Spomky-Labs/OTPHP](https://github.com/Spomky-Labs/otphp) for RFC-compliant OTP generation

---

## 📞 Support & Community

- 📖 **Documentation:** [docs.2fa-vault.example.com](https://docs.2fa-vault.example.com) *(coming soon)*
- 💬 **Discussions:** [GitHub Discussions](https://github.com/yourusername/2FA-Vault/discussions)
- 🐛 **Issues:** [GitHub Issues](https://github.com/yourusername/2FA-Vault/issues)
- 🔒 **Security:** security@2fa-vault.example.com

---

## 📊 Project Stats

- **Version:** 1.0.0
- **Release Date:** April 2026
- **Development Time:** 6 Phases (Design → E2EE → Multi-User → Backups → Extensions → Polish)
- **Total Features:** 15+ major features beyond original 2FAuth
- **Lines of Code:** ~50,000+ (estimate)

---

Made with ❤️ by the 2FA-Vault team | Forked from [2FAuth](https://github.com/Bubka/2FAuth) by Bubka
