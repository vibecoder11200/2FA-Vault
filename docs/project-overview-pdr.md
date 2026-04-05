# Project Overview & Product Design Rationale

High-level project objectives, scope, and design decisions.

## Project Vision

**2FA-Vault** is an enterprise-grade, self-hosted 2FA (Two-Factor Authentication) manager built as an enhanced fork of [2FAuth](https://github.com/Bubka/2FAuth). It provides secure, encrypted, and collaborative management of TOTP/HOTP authentication accounts with zero-knowledge architecture.

### Problem Statement

1. **Security Risk:** Centralized 2FA services (Google Authenticator, Microsoft Authenticator) create single points of failure
2. **Vendor Lock-in:** Proprietary apps offer limited portability and backup options
3. **Collaboration Gap:** No easy way to share 2FA accounts within teams
4. **Privacy Concern:** Server-side encryption exposes secrets to provider
5. **Offline Limitation:** Mobile authenticators don't work offline without the device

### Solution

2FA-Vault provides:
- **Self-hosted:** Full control of data and infrastructure
- **Zero-knowledge:** Server never sees plaintext secrets (E2EE)
- **Team collaboration:** Share accounts with fine-grained permissions
- **Offline-first:** PWA support for offline account access
- **Flexible:** Browser extension, desktop, and mobile web interfaces

## Core Features

### Tier 1: MVP (2FAuth Baseline)
- ✅ TOTP/HOTP generation (RFC 4226, RFC 6238)
- ✅ QR code scanning and import
- ✅ Account organization via groups
- ✅ Web UI for account management
- ✅ Database-backed storage

### Tier 2: Enterprise Features (New in 2FA-Vault)
- ✅ End-to-End Encryption (E2EE) with Argon2id + AES-256-GCM
- ✅ Multi-user support with role-based access control
- ✅ Team management with invitations
- ✅ Encrypted backup/restore with separate password
- ✅ Browser extension (Chrome/Firefox)
- ✅ Progressive Web App with offline support
- ✅ Web Push notifications

### Tier 3: Planned Features (Roadmap)
- Native mobile apps (iOS/Android)
- Advanced sharing policies
- Audit logging for compliance
- Single sign-on (SSO) integration
- API rate limiting and quotas

## Design Principles

### 1. Zero-Knowledge Architecture

**Definition:** Server never has access to plaintext secrets.

**Implementation:**
- Master password never sent to server
- All encryption/decryption happens client-side
- Server stores only encrypted ciphertexts
- Key derivation uses Argon2id (PBKDF2-resistant)

**Benefits:**
- Even database breach doesn't expose secrets
- User is sole key holder
- Compliance with privacy regulations (GDPR, CCPA)

**Trade-offs:**
- Lost passwords cannot be recovered by admin
- Requires JavaScript for encryption operations
- Cannot search encrypted data server-side

### 2. E2EE Implementation

**Architecture:**
```
User Password
    ↓
Argon2id (with salt)
    ↓
Encryption Key (never sent to server)
    ↓
AES-256-GCM (with IV, Auth Tag)
    ↓
Ciphertext → Server Database
```

**Security Properties:**
- **Authenticated encryption:** AEAD mode prevents tampering
- **Key derivation:** Argon2id resistant to GPU/ASIC attacks (100,000 iterations)
- **Randomization:** IV freshly generated per encryption
- **Salt:** Unique per user, stored with encrypted test value

**Verification:**
- User password verified by decrypting test value client-side
- Success = correct password, decrypt key is valid
- Failure = incorrect password or corrupted data

### 3. Multi-User with Team Isolation

**Tenant Model:** Each user has isolated accounts by default.

**Team Model:** Optional team collaboration with explicit sharing:
```
User A (Team Owner)
  ↓
Team (Shared context)
  ├── User B (Admin) → Can manage members, accounts
  ├── User C (Member) → Can view, use, but not manage
  └── User D (Viewer) → Read-only access
```

**Sharing Mechanism:**
- Account owner encrypts with personal key
- Owner shares encrypted account with team
- Team members decrypt with own key after receiving owner's encrypted key
- Fine-grained permissions: view, use, manage

**Benefits:**
- Users maintain privacy (accounts not shared)
- Teams enable collaboration
- Permissions prevent accidental damage
- Audit trail possible per permission level

### 4. Encrypted Backups

**Format:** `.vault` file with double encryption

```
User Secret #1
User Secret #2
...
    ↓
ZIP all data
    ↓
Encrypt with master key (E2EE)
    ↓
Encrypt again with backup password
    ↓
.vault file (importable to any 2FA-Vault instance)
```

**Design Rationale:**
- Separate backup password prevents master password disclosure if backup leaked
- Double encryption for defense-in-depth
- Portable format (ZIP allows future recovery even if 2FA-Vault becomes unavailable)
- Server can't access backups (can't help restore them)

### 5. Browser Extension Design

**Architecture:** Manifest v3 compliant

```
Background Service Worker
  ↓ (listens for tab navigation)
  ↓
Content Script (injected into web pages)
  ↓ (detects login form fields)
  ↓
Popup UI (shows matching TOTP accounts)
  ↓ (user clicks to copy code)
  ↓
Clipboard (TOTP code copied)
```

**Security:**
- Extension can't access 2FA-Vault encryption key (stored in web app memory)
- Separate extension storage requires user re-authentication
- Content script only reads form fields, can't inject data without user action
- HTTPS-only communication between extension and web app

**Limitations:**
- Can't auto-fill without user action (Manifest v3 restriction)
- User must manually paste TOTP from extension

### 6. Offline-First PWA

**Capability:** User can access TOTP accounts without internet connection

**Implementation:**
- Service Worker caches essential app shell
- IndexedDB stores encrypted account data locally
- Background sync queues server updates for when online
- Web Push API for notification support

**Data Sync:**
```
Online Mode
  ↓
Fetch account → Decrypt client-side → Display
  ↓
Save to IndexedDB + server

Offline Mode
  ↓
Fetch from IndexedDB → Decrypt client-side → Display
  ↓
Queue update for later
  ↓
When online → Sync queue with server
```

**Limitations:**
- IndexedDB storage limited (~50MB per origin)
- Encryption key lost if browser cleared (reload required)
- Can't update accounts while offline (queued for sync)

### 7. Graceful Degradation

**Principle:** App works with non-encrypted accounts for backward compatibility.

**Scenario:**
- User migrates from 2FAuth (no encryption)
- Existing accounts remain plaintext initially
- User enables E2EE → progressively encrypts new accounts
- Old accounts still accessible (with `encrypted = false` flag)

**Code Pattern:**
```php
if ($account->encrypted) {
    // Handle encrypted data
} else {
    // Handle plaintext data (legacy)
}
```

## Technology Choices

### Backend: Laravel 12 + PHP 8.4

**Why Laravel:**
- Rich ecosystem (authentication, validation, database)
- Elegant ORM (Eloquent) with relationships
- Built-in testing framework (PHPUnit)
- Solid security features (CSRF, SQL injection prevention)
- Excellent documentation

**Why PHP 8.4:**
- Modern syntax (match expressions, typed properties)
- JIT compilation for performance
- Security improvements (fibers, attributes)
- Maintenance window until Nov 2028

### Frontend: Vue 3 + TypeScript + Vite

**Why Vue 3:**
- Composition API matches JavaScript 2024+ mindset
- Smaller bundle than React
- Excellent documentation
- Strong typing support (TypeScript)

**Why Vite:**
- Fast HMR (Hot Module Replacement)
- Native ES modules support
- Simple configuration
- Production-optimized builds

**Why Pinia (not Vuex):**
- Composition API native
- Simpler syntax
- Better TypeScript support
- Recommended by Vue team

### Crypto: Web Crypto API + Argon2id

**Why Web Crypto API:**
- Browser native (no npm dependencies needed)
- Hardware-accelerated on most platforms
- Audited by security experts
- FIPS 140-2 compliance (on some platforms)

**Why Argon2id:**
- Memory-hard (resistant to GPU/ASIC attacks)
- Time-hard (adjustable iterations)
- OWASP recommended
- Winner of Password Hashing Competition (2015)

**Why AES-256-GCM:**
- AEAD (Authenticated Encryption with Associated Data)
- 256-bit key = post-quantum resistant (theoretically)
- Hardware accelerated (AES-NI on x86/ARM)
- NIST standard

### Database: Flexible (MySQL/PostgreSQL/SQLite)

**Design:** No database-specific SQL in code.

**Supported:**
- MySQL 8.0+
- PostgreSQL 13+
- SQLite 3.8+ (development/small deployments)

**Rationale:**
- Uses Eloquent ORM (abstracts differences)
- Migrations written in database-agnostic PHP
- Allows user choice for deployment

### Testing: PHPUnit + Factories

**Why PHPUnit:**
- Laravel default
- Rich assertion library
- Fast parallel execution
- Well-integrated with Laravel

**Why Factories over Fixtures:**
- Dynamic data generation
- Relationship building simple
- Minimal setup code
- Easy to override for specific tests

## Scope & Constraints

### In Scope
✅ Self-hosted web application
✅ TOTP/HOTP account management
✅ End-to-End Encryption
✅ Team collaboration
✅ Backup/restore
✅ Browser extension
✅ Progressive Web App
✅ Multi-language support (i18n)

### Out of Scope (for MVP)
❌ Mobile native apps (PWA is primary mobile solution)
❌ Hardware security keys for account storage
❌ Decentralized/blockchain features
❌ Social sharing (public accounts)
❌ Complex permission hierarchies

### Constraints
- **Browser support:** Modern browsers only (ES2020+)
- **Encryption:** Client-side JavaScript required
- **Storage:** Disk space for encrypted backups
- **Network:** HTTPS required (no plaintext transmission)
- **Users:** Single-user (original) or team (with features)

## Scalability Considerations

### Horizontal Scaling
- **Stateless API:** No session affinity needed
- **Database separation:** Can scale database independently
- **Encryption:** Client-side (no server-side crypto load)
- **Load balancing:** Standard Laravel deployment

### Vertical Scaling
- **No special requirements:** Standard Laravel app
- **Database optimization:** Indexing (user_id, service, created_at)
- **Caching:** Redis for sessions/cache

### Limitations
- **Backup size:** Large user bases = large backup files
- **Real-time sync:** PWA offline queue not suitable for high-frequency updates
- **WebAuthn:** Requires credential registration (not auto-scalable)

## Migration Path: 2FAuth → 2FA-Vault

### Import Compatibility
Supports importing from:
- 2FAuth (JSON)
- Google Authenticator (QR code export)
- Aegis (JSON, plaintext)
- 2FAS (JSON)
- Other TOTP exporters (URI format)

### Data Preservation
- Service names and icons preserved
- Account names and notes migrated
- Group organization maintained
- Secrets extracted and re-encrypted

### Backwards Compatibility
- Non-encrypted accounts readable by 2FAuth
- Encrypted accounts unreadable by 2FAuth (can't decrypt without E2EE key)
- Original backup format supported for import

### One-Way Migration
- Easy to migrate FROM 2FAuth TO 2FA-Vault
- Difficult to migrate back (encryption barrier)
- Encrypted backups not importable by other apps

## Security Model

### Attack Scenarios & Mitigations

| Scenario | Risk | Mitigation |
|----------|------|-----------|
| Database breach | Attacker gets encrypted secrets + salt | Encryption + Argon2id key derivation makes brute-force impractical |
| Session hijacking | Attacker steals OAuth token | Token short-lived, can revoke devices, HTTPS only |
| Browser malware | Malware steals encryption key | Key in memory only (cleared on reload), separate backup password |
| MITM attack | Attacker intercepts password | HTTPS enforced, HSTS headers, CSP policy |
| Backup leak | Someone finds .vault file | Double encryption + separate backup password |
| Social engineering | Attacker tricks user into sharing key | No recovery mechanism (key holder is sole authority) |

### Trust Boundaries

**User trusts:**
- Server operator (has access to database)
- Browser (has access to memory)
- Local computer (has access to files)

**Server trusts:**
- Database is secure (user's responsibility)
- HTTPS termination is correct
- No code tampering in deployment

**User doesn't trust:**
- Server operator (with plaintext secrets) → E2EE solves this
- Server infrastructure (backups, logs) → E2EE solves this

## Success Metrics

### Adoption
- Number of users
- Number of accounts managed
- Team usage rate
- Extension downloads

### Security
- Zero confirmed plaintext leaks
- No cryptographic breaks
- Vulnerability response time < 48 hours

### Usability
- Setup time < 5 minutes
- Feature discovery rate > 80%
- User retention after 30 days

### Performance
- Page load < 2s
- OTP generation < 100ms
- Encryption/decryption < 500ms

## Roadmap

### Phase 1: Foundation (Complete)
- ✅ E2EE encryption system
- ✅ Multi-user architecture
- ✅ Team management
- ✅ Encrypted backups

### Phase 2: Extensions (Complete)
- ✅ Browser extension (Chrome/Firefox)
- ✅ PWA with offline support
- ✅ Push notifications

### Phase 3: Polish (Current)
- 🔄 Audit logging
- 🔄 API rate limiting
- 🔄 Performance optimization
- 🔄 Documentation completion

### Phase 4: Enterprise (Planned)
- ⏳ SSO integration (OIDC, SAML)
- ⏳ Advanced permission policies
- ⏳ Compliance features (HIPAA, GDPR audits)
- ⏳ Native mobile apps

## Glossary

| Term | Definition |
|------|-----------|
| **2FA** | Two-Factor Authentication |
| **TOTP** | Time-based One-Time Password (RFC 6238) |
| **HOTP** | HMAC-based One-Time Password (RFC 4226) |
| **E2EE** | End-to-End Encryption |
| **OTP** | One-Time Password |
| **PWA** | Progressive Web App |
| **AEAD** | Authenticated Encryption with Associated Data |
| **AES** | Advanced Encryption Standard |
| **GCM** | Galois/Counter Mode (block cipher mode) |
| **IV** | Initialization Vector |
| **PBKDF2** | Password-Based Key Derivation Function 2 |
| **Argon2id** | Memory-hard key derivation function |
| **WebAuthn** | Web Authentication API (passwordless) |
| **CSRF** | Cross-Site Request Forgery |
| **HSTS** | HTTP Strict-Transport-Security |
| **CSP** | Content Security Policy |
