# Changelog

All notable changes to 2FA-Vault will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-04-04

### Added

**🔐 Zero-Knowledge Encryption**
- End-to-end encryption (E2EE) with client-side encryption
- Argon2id key derivation (64 MB memory cost, 3 iterations, parallelism 4)
- AES-256-GCM authenticated encryption
- Zero-knowledge architecture (server cannot decrypt user data)
- Encryption enabled by default for all new accounts
- Encrypted backup format (.vault) with integrity verification

**👥 Multi-User & Team Management**
- Multi-user support with team workspaces
- Team creation and management
- Team invite system with expiring codes
- User can join multiple teams (configurable limit)
- Personal team created automatically on signup

**🔑 Role-Based Access Control (RBAC)**
- Four roles: Owner, Admin, Member, Viewer
- Granular permissions per role
- Owner: Full control (delete team, manage all members)
- Admin: Manage accounts and members (cannot delete team)
- Member: Create/edit/delete own accounts only
- Viewer: Read-only access (view accounts, generate codes)

**💾 Enhanced Backup System**
- Encrypted backup export (.vault format)
- Encrypted backup import with password verification
- Password-protected backups
- Backup integrity checks (HMAC-SHA256)
- Migration tool from 2FAuth JSON format
- Automatic backup encryption on export

**🌐 Browser Extension**
- Chrome Manifest V3 extension
- Firefox WebExtension support
- One-click TOTP code copy
- Auto-fill for web forms
- Offline mode with IndexedDB sync
- Biometric unlock (WebAuthn) in extension

**📱 Progressive Web App (PWA)**
- Installable on desktop and mobile
- Offline mode with service worker
- Background sync for account updates
- Add to home screen support
- App-like experience (fullscreen, splash screen)
- Cache strategies (network-first, cache-first, stale-while-revalidate)

**🔔 Push Notifications**
- Web Push API integration
- VAPID protocol for authentication
- Push subscription management
- Notification permissions handling
- Customizable notification settings

**📴 Offline TOTP Generation**
- IndexedDB for encrypted local storage
- Offline code generation without server
- Service worker caching strategies
- Background sync when online
- Conflict resolution for offline changes

**🛡️ Security Enhancements**
- Biometric unlock (WebAuthn)
- Hardware security key support (FIDO2)
- Audit logging for sensitive operations
- Rate limiting on authentication endpoints
- CSRF protection
- Content Security Policy (CSP) headers
- HTTP Strict Transport Security (HSTS)
- X-Frame-Options: DENY
- X-Content-Type-Options: nosniff

**👨‍💼 Admin Panel**
- User management interface
- Team overview and statistics
- System settings configuration
- User account activation/deactivation
- Audit log viewer
- Storage usage monitoring

**🎨 UI/UX Improvements**
- Dark mode support
- Responsive design (mobile-first)
- Improved accessibility (WCAG 2.1 AA)
- Loading states and skeleton screens
- Toast notifications
- Drag-and-drop account reordering

**🚀 DevOps & Infrastructure**
- CI/CD pipeline (GitHub Actions)
- Automated testing (PHPUnit, Jest)
- Docker production configuration
- Redis for session and cache storage
- MySQL 8.0 database
- Health check endpoints
- Logging and monitoring

### Changed

**🔐 Encryption Now Default**
- Encryption is **ON by default** (was optional in 2FAuth)
- Master password required for all accounts
- Automatic encryption of legacy data on first login
- Migration script for unencrypted 2FAuth data

**📊 Database Schema**
- Multi-user schema (teams, roles, memberships)
- Foreign key constraints for referential integrity
- Optimized indexes for team queries
- Audit log table for compliance

**🔑 Authentication Flow**
- Separate master password for encryption (not login password)
- Master password never sent to server
- Client-side key derivation
- Session management with Redis

**📦 Backup Format**
- Changed from `.json` (plaintext) to `.vault` (encrypted)
- Breaking change: Old 2FAuth backups require migration
- Migration tool provided (see MIGRATION.md)

**🎯 Project Metadata**
- Forked from 2FAuth v6.1.3
- Renamed to 2FA-Vault
- New branding and logo
- Updated documentation

### Fixed

- **Security:** XSS vulnerability in account name rendering
- **Security:** CSRF token validation on backup export
- **Performance:** N+1 query on team accounts listing
- **Bug:** Service worker not updating on new deployment
- **Bug:** IndexedDB quota exceeded on large accounts (&gt;100 items)
- **Bug:** Push notifications not working on Firefox Android

### Security

- **CRITICAL:** Implemented E2EE to protect against server-side data breaches
- **HIGH:** Argon2id prevents GPU-based password cracking
- **MEDIUM:** Rate limiting prevents brute-force attacks
- **MEDIUM:** CSP headers prevent XSS attacks

### Deprecated

- **Legacy encryption method** (Laravel's built-in encryption)
  - Still supported for migration only
  - Will be removed in v2.0.0
  - Migrate to Argon2id + AES-256-GCM

### Removed

- **Single-user mode** (replaced with personal teams)
- **Plaintext JSON backups** (replaced with encrypted .vault)
- **Password recovery** (impossible with zero-knowledge encryption)
  - Users must backup .vault file
  - No "forgot password" feature by design

### Breaking Changes

⚠️ **Migration from 2FAuth required** - see [MIGRATION.md](MIGRATION.md)

1. **Backup format:** `.json` → `.vault` (encrypted)
2. **Database schema:** Single-user → Multi-user
3. **API endpoints:** New E2EE endpoints, some modified
4. **Environment variables:** New required variables (see .env.example)
5. **Password system:** Login password ≠ Master password

### Migration Path

```bash
# Export from 2FAuth
Settings → Backup → Export accounts → 2fauth-backup.json

# Install 2FA-Vault
docker-compose up -d

# Import to 2FA-Vault
Settings → Import → Choose "2FAuth JSON" → Upload file
```

Detailed migration guide: [MIGRATION.md](MIGRATION.md)

## [Unreleased]

### Planned for v1.1.0

- [ ] Mobile apps (React Native - iOS/Android)
- [ ] End-to-end encrypted account sharing
- [ ] TOTP sync across devices via encrypted cloud
- [ ] Encrypted search (searchable encryption)
- [ ] Browser extension autofill improvements
- [ ] Desktop apps (Electron - Windows/macOS/Linux)

### Planned for v2.0.0

- [ ] Post-quantum cryptography (Kyber, Dilithium)
- [ ] Federated authentication (SSO with Keycloak, Authelia)
- [ ] Advanced RBAC (custom roles, fine-grained permissions)
- [ ] Multi-factor recovery (social recovery, Shamir's Secret Sharing)
- [ ] Encrypted vault export to multiple cloud providers

## [0.x.x] - 2FAuth Versions

This project is a fork of [2FAuth](https://github.com/Bubka/2FAuth) v6.1.3.

For 2FAuth changelog history, see:
https://github.com/Bubka/2FAuth/blob/master/CHANGELOG.md

---

## Versioning Scheme

- **MAJOR.MINOR.PATCH** (Semantic Versioning)
- **MAJOR:** Breaking changes, major features
- **MINOR:** New features, backward-compatible
- **PATCH:** Bug fixes, security patches

## Support

- **GitHub Issues:** https://github.com/yourusername/2FA-Vault/issues
- **Security:** security@2fa-vault.example.com
- **Documentation:** https://docs.2fa-vault.example.com

## Acknowledgments

- **2FAuth:** Original project by [Bubka](https://github.com/Bubka)
- **Laravel:** Backend framework
- **Vue.js:** Frontend framework
- **Argon2:** Password hashing by Daniel J. Bernstein et al.
- **Web Crypto API:** Browser encryption primitives

---

[1.0.0]: https://github.com/yourusername/2FA-Vault/releases/tag/v1.0.0
[Unreleased]: https://github.com/yourusername/2FA-Vault/compare/v1.0.0...HEAD
