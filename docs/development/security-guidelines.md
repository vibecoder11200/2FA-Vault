# Security Policy

## 🔒 Security Architecture

2FA-Vault is built with **zero-knowledge end-to-end encryption (E2EE)** as its foundation. Your TOTP secrets are encrypted on your device before ever reaching our servers.

### Zero-Knowledge Encryption

**What we protect:**
- ✅ TOTP secrets (the core cryptographic material)
- ✅ Account names, issuers, notes
- ✅ All metadata associated with your 2FA accounts
- ✅ Backup files (.vault format)

**What we can see:**
- ❌ Your encrypted data (server stores ciphertext only)
- ❌ Your master password or encryption key
- ❌ Your TOTP codes or secrets
- ✅ Your email address (authentication identifier)
- ✅ Team membership and roles (access control)
- ✅ Encrypted backup metadata (timestamps, sizes)

### Encryption Implementation

**Key Derivation: Argon2id**
```
Master Password → Argon2id (time: 3, memory: 65536 KB, parallelism: 4) → 256-bit Encryption Key
```

**Data Encryption: AES-256-GCM**
- Algorithm: AES-256 in Galactic Counter Mode
- Authenticated encryption (prevents tampering)
- Unique IV (initialization vector) per operation
- Authentication tags verify data integrity

**Key Parameters:**
- Memory cost: 64 MB (prevents GPU attacks)
- Time cost: 3 iterations (balances security/UX)
- Parallelism: 4 threads (leverages modern CPUs)
- Salt: Random 16 bytes per user

**Encryption Flow:**
```
1. User enters master password
2. Argon2id derives encryption key (client-side)
3. AES-256-GCM encrypts TOTP data (client-side)
4. Ciphertext sent to server
5. Server stores encrypted data (cannot decrypt)
```

**Decryption Flow:**
```
1. User enters master password
2. Argon2id derives same encryption key
3. Client fetches ciphertext from server
4. AES-256-GCM decrypts data (client-side)
5. TOTP codes generated locally
```

### Client-Side vs Server-Side Responsibilities

**Client-Side (Browser/Extension/PWA):**
- ✅ Master password input and storage (in memory, cleared on reload)
- ✅ Argon2id key derivation (password + salt → encryption key)
- ✅ AES-256-GCM encryption/decryption operations
- ✅ TOTP code generation from decrypted secrets
- ✅ Backup password handling (separate from master password)

**Server-Side (Laravel Backend):**
- ✅ Store encryption salt (for key derivation)
- ✅ Store encrypted test value (for zero-knowledge verification)
- ✅ Store encrypted secrets as JSON: `{ciphertext, iv, authTag}`
- ✅ Manage vault lock state (`vault_locked` flag)
- ✅ Validate encryption format and version
- ✅ NEVER decrypt client secrets (zero-knowledge principle)

**The server's role is validation and storage only - all cryptographic operations happen client-side.**

### Encrypted Data Format

**Encrypted Secret Structure:**
```json
{
  "ciphertext": "base64-encoded-encrypted-data",
  "iv": "base64-encoded-initialization-vector",
  "authTag": "base64-encoded-authentication-tag"
}
```

**Detection:** Server identifies encrypted secrets by checking if the value starts with `{` and contains `"ciphertext"`.

**Storage:** Encrypted secrets are stored as-is in the database, without Base32 formatting (which is applied only to plaintext secrets).

### Key Lifecycle Management

**1. Initial Setup (E2EE Enablement):**
```
Client generates:
- Random 16-byte salt
- Master password derived encryption key (Argon2id)
- Encrypted test value (for verification)

Server stores:
- encryption_salt (the salt)
- encryption_test_value (encrypted test data)
- encryption_version (format version)
- vault_locked = false
```

**2. Normal Operation (Vault Unlocked):**
- Encryption key stored in browser memory (Pinia store)
- Key cleared on page reload/browser close
- Vault must be unlocked with master password for each session

**3. Vault Lock:**
```
Server: Sets vault_locked = true
Client: Clears encryption key from memory
Result: All encrypted data remains inaccessible until re-authentication
```

**4. Vault Unlock:**
```
User: Enters master password
Client: Derives key using stored salt
Client: Decrypts test value, sends verification
Server: Validates verification, sets vault_locked = false
Client: Stores key in memory for session
```

**5. Master Password Change:**
```
⚠️ WARNING: Cannot change master password directly!
- Key is derived from password + salt
- Changing password would require re-encrypting ALL secrets
- Current implementation: Disable E2EE, re-enable with new password
- Future enhancement: Implement secure password rotation

Note: Disabling encryption is blocked when encrypted accounts exist.
Users must decrypt or delete all encrypted accounts before disabling E2EE.
```

### What Data Is Encrypted

**Always Encrypted (when E2EE enabled):**
- ✅ TOTP secret (the core cryptographic material)
- ✅ Account service name (if encrypted during account creation)
- ✅ Account identifier/email (if encrypted during account creation)

**Never Encrypted:**
- ❌ Account ID, order, group assignments
- ❌ OTP type (TOTP/HOTP), digits, period, algorithm
- ❌ Team membership and roles
- ❌ User email, name, preferences
- ❌ Timestamps, metadata

**Backup File Encryption:**
- Double-encrypted format:
  1. Client-side: AES-256-GCM with backup password
  2. Server stores: Encrypted backup file as-is
- Backup password is separate from master password
- Server cannot decrypt user backups
- Backup files are encrypted at rest on the `backups` filesystem disk
- Stale backup files cleaned up automatically via the `CleanupBackupFiles` artisan command

### Team Sharing Security Model

**Shared Account Access:**
- Team members can view shared OTP codes (read-only access)
- Server enforces role-based permissions (Owner, Admin, Member, Viewer)
- Each member's client decrypts shared secrets independently
- No master key sharing - each user maintains their own encryption

**Permission Hierarchy:**
```
Owner → Full control, can delete team, manage all members
Admin → Can invite/remove members, update roles
Member → Can view/use shared accounts
Viewer → Read-only access to shared accounts
```

**Security Isolation:**
- Team A members cannot access Team B accounts
- Personal accounts never shared without explicit action
- Audit logging tracks all team membership changes

## 🛡️ Threat Model

### What We Protect Against

| Threat | Protection | Status |
|--------|------------|--------|
| **Server breach** | E2EE ensures stolen DB is useless | ✅ Protected |
| **Network eavesdropping** | HTTPS + encrypted payloads | ✅ Protected |
| **Weak passwords** | Argon2id makes cracking expensive | ✅ Mitigated |
| **Password reuse** | Unique encryption key per user | ✅ Protected |
| **Session hijacking** | HTTP-only cookies, CSRF tokens | ✅ Protected |
| **XSS attacks** | CSP headers, input sanitization | ✅ Mitigated |
| **Brute force** | Rate limiting on auth, registration (5/hr), and password reset (3/hr) endpoints | ✅ Protected |
| **Man-in-the-middle** | HTTPS required, HSTS enabled | ✅ Protected |

### What We DON'T Protect Against

| Threat | Limitation | Mitigation |
|--------|------------|------------|
| **Client-side malware** | Keyloggers can capture master password | Use biometric unlock, trusted devices |
| **Forgot password** | No password recovery (zero-knowledge) | Backup your .vault file! |
| **Compromised device** | Attacker with device access can extract data | Enable biometric auth, lock screen |
| **Social engineering** | Phishing can trick users into revealing passwords | User education, 2FA on email |

### Assumptions

Our security model assumes:
1. **User chooses strong master password** (12+ characters, mixed case, numbers, symbols)
2. **Client device is trusted** (no malware, up-to-date OS)
3. **HTTPS is enforced** (never use HTTP in production)
4. **Server operators are honest** (we can't decrypt, but we could modify client code)

### Attack Surface Considerations

**Protected Attack Vectors:**
| Attack Vector | Protection Mechanism | Status |
|---------------|---------------------|--------|
| **Database breach** | Encrypted secrets useless without master key | ✅ Protected |
| **Server compromise** | No decryption keys on server | ✅ Protected |
| **Network interception** | HTTPS + encrypted payload | ✅ Protected |
| **Server-side injection** | Parameterized queries, input sanitization | ✅ Protected |
| **Session hijacking** | HTTP-only, Secure, SameSite cookies | ✅ Protected |
| **CSRF attacks** | Token validation on state changes | ✅ Protected |
| **Brute force (auth)** | Rate limiting (5 attempts/minute, registration 5/hr, password reset 3/hr) | ✅ Protected |
| **Brute force (API)** | Rate limiting (60 requests/minute) | ✅ Protected |

**Remaining Attack Surface:**
| Vector | Risk | Mitigation |
|--------|------|------------|
| **Client-side XSS** | Could extract encryption key from memory | CSP headers, input sanitization |
| **Malicious client code** | Compromised frontend could steal keys | Code signing, subresource integrity (SRI) |
| **Physical device access** | Unlocked device could access in-memory key | Auto-lock timeout, biometric auth |
| **Weak master password** | Dictionary attacks on derived key | Password strength requirements, rate limiting |

**Web Crypto API Security:**
- All cryptographic operations performed in browser
- Uses native crypto APIs (not JavaScript implementations)
- Keys never accessible to JavaScript code
- Secure random number generation via `crypto.getRandomValues()`

## 🔐 Operational Security

### Vault Lock/Unlock State Management

**Vault States:**
1. **Unlocked** - Encryption key in memory, OTP codes accessible
2. **Locked** - Key cleared from memory, requires re-authentication
3. **Not Encrypted** - E2EE not enabled (plaintext storage)

**Lock Behavior:**
- Manual lock: User clicks "Lock Vault" → key cleared
- Auto-lock: Configurable timeout (recommended: 5-15 minutes)
- After copying code: Optional auto-lock for security
- Page refresh: Always clears key (security by design)

**Unlock Verification:**
- Zero-knowledge password verification
- Client decrypts test value, sends result to server
- Server confirms validity, unlocks vault
- Failed attempts rate-limited (5/minute)

### Password Change Procedure

**Current Limitation:**
```
⚠️ DIRECT PASSWORD CHANGE NOT SUPPORTED

To change master password:
1. Decrypt all accounts (unlock vault)
2. Disable E2EE (stores plaintext temporarily)
   - Note: This step is blocked if any encrypted accounts still exist
   - All encrypted accounts must be decrypted or removed first
3. Re-enable E2EE with new password
4. Re-encrypt all accounts with new key
```

**Security Implications:**
- Brief window where data is plaintext during transition
- Should be done over trusted network (not public Wi-Fi)
- Consider implementing secure rotation in future version

### Session Driver & Session Revocation

- `.env.example` now ships `SESSION_DRIVER=database` so the "revoke session"
  feature can actually evict server-side session rows (the `sessions` table,
  migration `2024_09_12_151037`). With the `file` driver, revocation is
  bookkeeping-only — the `EnsureSessionValid` middleware safely no-ops there.
- The `EnsureSessionValid` middleware rejects cookie-session requests whose
  underlying `sessions` row was deleted (revoked via the session manager or a
  password change/reset). Bearer/PAT requests pass through untouched.
- `user_sessions.token_id` stores the Laravel session id for web-guard rows
  and the Passport token id for PAT rows.

### Personal Access Token Scopes

- Passport defines four scopes: `read`, `otp`, `write`, `admin`
  (`AppServiceProvider::boot`). New PATs default to a 90-day expiry and MUST
  request at least one valid scope — creating a PAT with omitted/empty scopes
  returns 422 (`PersonalAccessTokenController::store`).
- Route-level enforcement uses the `pat.scopes:<scopes>` middleware across
  `/api/v1` (read routes accept `read|otp`; destructive routes — deletes,
  preference rewrites — require `write`; admin routes require `admin`).
- **Legacy marker**: PATs created before scopes existed were stamped by the
  one-time migration `2026_09_02_000001_stamp_legacy_pat_full_access_scopes`
  with the explicit `legacy_full_access` marker, which grants full access.
  Only that marker is grandfathered — a plain empty scope set has no access
  (this prevents forging new "legacy" tokens, since Passport defaults omitted
  scopes to `[]`).

### Backup Password Handling

**Separation of Concerns:**
```
Master Password: Protects account secrets (stored in database)
Backup Password: Protects .vault file (user-managed file)
```

**Backup Security:**
- Backup password entered only during export/import
- Never stored on server
- Required for restoring to any 2FA-Vault instance
- Lost backup password = lost backup (no recovery mechanism)

**Recommended Practice:**
- Store backup password in password manager
- Different from master password (defense in depth)
- Include backup password in offsite backup strategy

### Team Sharing Security Model

**Permission Enforcement:**
```php
// Server-side policy checks (app/Policies/TeamPolicy.php)
- Only team owners/admins can invite members
- Only owners can delete team or transfer ownership
- Members cannot leave if they are the owner
- Removed members immediately lose access

// Client-side decryption
- Each member decrypts shared secrets independently
- No shared decryption keys
- Compromised member account = lost access to shared accounts
```

**Data Isolation:**
- Personal accounts: Never shared without explicit action
- Team accounts: Encrypted same as personal, access controlled via database
- Cross-team access: Prevented by authorization policies
- Audit trail: All membership changes logged

### Rate Limiting Configuration

**Production-Recommended Settings:**
```env
# .env
THROTTLE_API=60,1      # API requests per minute
THROTTLE_LOGIN=5,1     # Login attempts per minute
THROTTLE_REGISTER=5,1  # Registration attempts: 5 per hour
THROTTLE_PASSWORD_RESET=3,1  # Password reset attempts: 3 per hour
THROTTLE_IMPORT=3,1    # Backup imports per hour
THROTTLE_EXPORT=5,1    # Backup exports per hour
```

**Rate Limit Enforcement:**
- Per IP address for unauthenticated requests
- Per user ID for authenticated requests
- Registration limited to 5 attempts per hour to prevent bulk account creation
- Password reset limited to 3 attempts per hour to prevent abuse
- Uses Laravel's built-in rate limiter
- Configured in `app/Providers/RouteServiceProvider.php`

**Auth-adjacent endpoint throttle grid:**

Defense-in-depth is applied across every route that an unauthenticated or
recently-authenticated attacker could abuse for brute force, email bombing, or
enumeration. A single un-throttled endpoint undoes the rest (the lesson from
Vaultwarden CVE-2026-43914, where an un-throttled `send_email_login` bypassed
login rate limiting).

| Endpoint | Method | Limit | Keyed by | Notes |
| --- | --- | --- | --- | --- |
| `user` (register) | POST | 5 / 60s | IP | `routes/web.php` |
| `user/password/lost` | POST | 3 / 60s | IP | email-bombing protection |
| `user/password/reset` | POST | 3 / 60s | IP | brute-force reset tokens |
| `user/login` | POST | 10 / 1min + 5 / window | IP, then email+IP | route throttle + `ThrottlesLogins` trait |
| `webauthn/login` | POST | 10 / 1min + 5 / window | IP, then email+IP | same layered defense as password login |
| `webauthn/login/options` | POST | 10 / 1min | IP | challenge-generation endpoint (flooding/enumeration) |
| `webauthn/recover` | POST | 10 / 1min | IP | WebAuthn recovery flow |
| `webauthn/lost` | POST | 3 / 60s | IP + email | device-lost recovery email; also throttled by the WebAuthn credential broker (`passwords.throttled`) |
| `socialite/{redirect,callback}` | GET | 10 / 1min | IP | SSO handshake |
| `encryption/setup` | POST | 3 / 60s | IP | manual `RateLimiter` in `EncryptionController` |
| `encryption/verify` | POST | 5 / 60s | IP | manual `RateLimiter` |
| `encryption/disable` | DELETE | 2 / hour | IP | manual `RateLimiter` |
| `backups/export` | POST | 5 / hour | user | manual `RateLimiter` |
| `backups/import` | POST | 3 / hour | user | manual `RateLimiter` |
| All `/api/v1/*` routes | any | 60 / 1min | IP | global `api` limiter (`RouteServiceProvider`); import context raises to `THROTTLE_API_DURING_IMPORT` |

Authenticated state-changing routes (PAT create/revoke, WebAuthn register,
team invite/accept/share, emergency-access store/request/approve, admin user
create/invite) are protected by the global `api` 60/min-IP cap plus ownership
policies; they are lower brute-force risk because they require a valid session
or PAT first.

### Secure Random Generation

**Random Values Used In:**
- Encryption salts (16 bytes, cryptographically secure)
- IV initialization vectors (12 bytes for AES-GCM)
- Team invite tokens (32 bytes, random string)
- API tokens (Laravel Passport, secure generation)

**Implementation:**
```php
// Server-side secure random
random_bytes(16);  // For salts, tokens

// Client-side secure random (crypto.js)
crypto.getRandomValues(new Uint8Array(16));  // For IV generation
```

### Timing Attack Prevention

**Password Verification:**
```php
// Laravel's Hash::check() uses timing-safe comparisons
if (Hash::check($inputPassword, $user->password)) {
    // Login successful
}
```

**API Token Validation:**
- Laravel Passport uses timing-safe string comparison
- Constant-time comparison prevents timing attacks on token matching

**Secret Comparison:**
- Avoid direct string comparison for secrets
- Use `hash_equals()` for comparing sensitive values

### For Users

**Strong Master Password:**
```
❌ Bad: password123
❌ Bad: MyName2024
✅ Good: correct-horse-battery-staple-7$Zq
✅ Good: Use a passphrase (4+ random words)
```

**Backup Strategy:**
1. Export .vault backup file regularly
2. Store backup in encrypted storage (1Password, Bitwarden, USB drive)
3. Test restore process periodically
4. Never email/share backup without additional encryption

**Account Security:**
- Enable 2FA on your 2FA-Vault email account (yes, meta!)
- Use unique password for 2FA-Vault (password manager recommended)
- Enable biometric unlock if supported
- Log out on shared devices

### For Administrators

**Deployment Security:**

**1. HTTPS Only (Required)**
```nginx
# Force HTTPS redirect
server {
    listen 80;
    server_name 2fa-vault.example.com;
    return 301 https://$server_name$request_uri;
}

# HTTPS with modern TLS
server {
    listen 443 ssl http2;
    ssl_certificate /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
}
```

**2. Environment Variables (Never hardcode secrets)**
```bash
# .env (never commit this file!)
DB_PASSWORD=<generate with: openssl rand -base64 32>
APP_KEY=<generate with: php artisan key:generate>
REDIS_PASSWORD=<generate with: openssl rand -base64 32>
VAPID_PRIVATE_KEY=<generate with web-push CLI>
```

**3. Database Hardening**
```yaml
# docker-compose.prod.yml
mysql:
  environment:
    MYSQL_ROOT_PASSWORD: <strong-password>
  volumes:
    - ./mysql-data:/var/lib/mysql  # Persistent storage
  networks:
    - internal  # Not exposed to public
```

**4. Rate Limiting**
```env
# .env
THROTTLE_LOGIN=5,1  # 5 attempts per minute
THROTTLE_API=60,1   # 60 requests per minute
```

**5. Security Headers**
```php
// Already configured in app/Http/Middleware/CspMiddleware.php
// Applied to web, behind-auth, and api.v1 middleware groups
Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Strict-Transport-Security: max-age=31536000
```

**5b. CORS Configuration (Production)**
```env
# .env — configure CORS for production hardening
CORS_ALLOWED_ORIGINS=https://2fa-vault.example.com
CORS_ALLOWED_METHODS=GET,POST,PUT,PATCH,DELETE
CORS_ALLOWED_HEADERS=Content-Type,Authorization,X-Requested-With
CORS_MAX_AGE=86400
```

**5c. Session Configuration**
```env
# .env — session lifetime and encryption
SESSION_LIFETIME=120          # Session timeout in minutes
SESSION_ENCRYPT=true          # Encrypt session data at rest
```

**6. Monitoring**
```bash
# Check failed login attempts
tail -f storage/logs/laravel.log | grep "Failed login"

# Monitor for suspicious activity
tail -f storage/logs/laravel.log | grep "rate_limit"
```

**7. Regular Updates**
```bash
# Update dependencies (review changelogs first!)
composer update --with-dependencies
npm update

# Update base image
docker-compose pull
docker-compose up -d --build
```

**8. Backup Strategy**
```bash
# Database backups (daily cron)
0 2 * * * docker exec 2fa-vault-mysql mysqldump -u root -p$MYSQL_ROOT_PASSWORD 2fa_vault > /backups/2fa-vault-$(date +\%Y\%m\%d).sql

# Encrypt backups
gpg --symmetric --cipher-algo AES256 /backups/2fa-vault-$(date +\%Y\%m\%d).sql
```

**9. Access Control**
```bash
# Restrict SSH access
AllowUsers admin@trusted-ip

# Firewall rules (UFW example)
ufw default deny incoming
ufw allow 22/tcp    # SSH (restrict to known IPs)
ufw allow 80/tcp    # HTTP (redirects to HTTPS)
ufw allow 443/tcp   # HTTPS
ufw enable
```

**10. Audit Logging**
```bash
# Enable audit logs
# Already implemented in app/Models/AuditLog.php

# Review logs periodically
php artisan audit:review --days=7
```

## 🐛 Reporting Vulnerabilities

We take security seriously. If you discover a vulnerability:

### Responsible Disclosure Process

**1. DO NOT open a public GitHub issue** (this puts users at risk)

**2. Report privately via:**
- **Email:** security@2fa-vault.example.com (preferred)
- **GitHub Security Advisory:** Use the "Security" tab (if available)

**3. Include in your report:**
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if available)
- Your contact information (for follow-up)

**4. We will:**
- Acknowledge within 48 hours
- Investigate and provide updates within 7 days
- Fix critical issues within 30 days
- Credit you in release notes (if desired)

### Severity Levels

| Severity | Impact | Response Time |
|----------|--------|---------------|
| **Critical** | RCE, data breach, auth bypass | 24 hours |
| **High** | Privilege escalation, XSS, CSRF | 7 days |
| **Medium** | Information disclosure, DoS | 14 days |
| **Low** | Minor issues, hardening suggestions | 30 days |

### Bug Bounty

Currently, we do not offer a bug bounty program. However, we deeply appreciate security researchers and will:
- Publicly acknowledge your contribution (if desired)
- Provide swag/stickers (if applicable)
- Fast-track feature requests from responsible reporters

## 📚 Security Resources

**Cryptography:**
- [Argon2 RFC 9106](https://datatracker.ietf.org/doc/html/rfc9106)
- [AES-GCM NIST SP 800-38D](https://csrc.nist.gov/publications/detail/sp/800-38d/final)

**Web Security:**
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [Mozilla Web Security Guidelines](https://infosec.mozilla.org/guidelines/web_security)

**Laravel Security:**
- [Laravel Security Best Practices](https://laravel.com/docs/10.x/security)
- [Laravel Encryption Documentation](https://laravel.com/docs/10.x/encryption)

## ✅ Security Audit & Test Coverage

### Automated Security Testing

**Security Test Suite:**
- ✅ 1590/1590 tests passing, 0 failures (v1.2.0, 2026-06-14)
  - Earlier snapshots (2026-04-05) reported 1,339/1,381 (97%); the remaining
    42 issues were resolved during the v1.2.0 stabilization sprint. See
    `docs/reference/changelog.md` and `DEV-STATUS-AND-ROADMAP.md`.
- ✅ All encryption endpoints fully tested
- ✅ Authentication flows comprehensively tested
- ✅ Authorization/permission policies validated
- ✅ Rate limiting verified
- ✅ Input sanitization confirmed

### Security Component Test Results

| Component | Tests | Coverage | Status |
|-----------|-------|----------|--------|
| **Encryption** | 25 | 100% | ✅ All Passing |
| **Authentication** | 120 | 96% | ✅ Secure |
| **Authorization** | 55 | 95% | ✅ Policies Working |
| **E2EE Flows** | 20 | 100% | ✅ Zero-Knowledge Verified |
| **WebAuthn** | 45 | 89% | ✅ Passwordless Working |
| **Rate Limiting** | 15 | 100% | ✅ Brute Force Protected |
| **Input Validation** | 80+ | 98%+ | ✅ Sanitization Working |

### Security Test Cases Implemented

**E2EE Security Tests:**
- ✅ Server never decrypts client secrets
- ✅ Encrypted payload format validated
- ✅ Key derivation with Argon2id verified
- ✅ Vault lock/unlock functionality tested
- ✅ Encryption setup prevents downgrade attacks
- ✅ Mixed encrypted/unencrypted account handling

**Authentication Security Tests:**
- ✅ Password hashing with bcrypt verified
- ✅ Session management secure
- ✅ CSRF protection enabled
- ✅ HTTP-only cookies configured
- ✅ Login rate limiting enforced
- ✅ WebAuthn passwordless flow tested

**Authorization Security Tests:**
- ✅ Team permission policies enforced
- ✅ Resource ownership verified
- ✅ Role-based access control working
- ✅ Admin-only endpoints protected
- ✅ Cross-team data isolation validated

**Data Protection Tests:**
- ✅ SQL injection prevention (Eloquent ORM)
- ✅ XSS protection (Blade templating, CSP)
- ✅ CSRF token validation
- ✅ Mass assignment protection ($fillable)
- ✅ File upload validation

### Security Audits Performed

**Code Review (2026-04-05):**
- ✅ All E2EE code paths reviewed
- ✅ Team permission policies audited
- ✅ Backup encryption verified
- ✅ Rate limiting configurations validated
- ✅ Security headers confirmed
- ✅ Database query safety checked

**Penetration Test Areas:**
- ✅ Authentication bypass attempts (prevented)
- ✅ Authorization escalation attempts (blocked)
- ✅ SQL injection attempts (sanitized)
- ✅ XSS payload injection (escaped)
- ✅ CSRF token forgery (validated)
- ✅ Rate limit bypass attempts (throttled)

### Production Security Checklist

**Pre-Deployment Security Verification:**
- [x] All secrets stored encrypted at rest
- [x] TLS 1.2+ enforced for all connections
- [x] Security headers configured (CSP with base-uri, form-action, frame-ancestors across all route groups)
- [x] CORS origins configured via environment variables
- [x] Rate limiting enabled on auth, registration (5/hr), and password reset (3/hr) endpoints
- [x] Input validation on all user inputs
- [x] SQL injection prevention (parameterized queries)
- [x] XSS protection (output encoding)
- [x] CSRF protection on all state-changing operations
- [x] Authentication logging enabled
- [x] Error messages don't leak sensitive information (stack traces stripped)
- [x] Debug mode disabled in production
- [x] Secure cookie flags (HttpOnly, Secure, SameSite)
- [x] Session encryption at rest enabled
- [x] Backup files encrypted at rest on the `backups` disk
- [x] Encryption disable blocked when encrypted accounts exist

**Ongoing Security Monitoring:**
- [ ] Automated dependency scanning (Suggested: GitHub Dependabot)
- [ ] Periodic security audits (Recommended: Quarterly)
- [ ] Bug bounty program (Future: When budget allows)
- [ ] Penetration testing (Recommended: Annual)

## 🔄 Security Changelog

### v1.3.1 (2026-09) - Auth/session/credential hardening (Phase 2)
- ✅ PAT scopes (`read`/`otp`/`write`/`admin`) + 90-day default expiry, with
  the `legacy_full_access` marker grandfathering pre-cutover tokens (see
  "Personal Access Token Scopes" above).
- ✅ `SESSION_DRIVER=database` default in `.env.example` + real session-row
  eviction on revocation and password change/reset
  (`CredentialRevocationService`).
- ✅ Deactivated users (admin action) are rejected on API and web requests
  and cannot log back in (`EnsureUserIsActive`).
- ✅ Session id regeneration on every authentication path (password,
  WebAuthn, registration, Socialite, recovery).
- ✅ Logout is now POST + CSRF; the legacy GET route answers 410 Gone.
- ✅ New throttles: PAT CRUD 10/min, WebAuthn register 10/min,
  `PATCH /user/password` 5/min, `PUT/PATCH /user*` 5/min keyed by user,
  `GET /refresh-csrf` 30/min, metrics endpoint 10/min with timing-safe
  (`hash_equals`) bearer-token comparison; `/api/v1` limiter keys by user id
  when authenticated (IP fallback). `THROTTLE_API=0` still works but logs a
  boot warning.
- ✅ `.env.example` sets `SESSION_SECURE_COOKIE=true` (comment out for
  plain-HTTP LAN self-hosting).
- ✅ WebAuthn login options return a 200-shaped generic error for unknown
  emails (enumeration oracle closed); password login/forgot keep their
  exists-rules (throttled, upstream UX parity — documented tradeoff).
- ✅ SSO-only email lookup returns identical responses for admin vs
  non-admin emails (no admin enumeration).

### v1.3.0 (2026-08) - Hardening sweep
- ✅ Tightened the default CORS policy: code fallback and `.env.example` no
  longer ship `CORS_ALLOWED_ORIGINS=*`; operators must explicitly opt in.
- ✅ Session lifetime fallback corrected (was a stale 90-day literal; now 120
  minutes, matching the shipped `.env.example` value).
- ✅ Backup file rotation scheduled: the existing `backup:cleanup` command now
  runs hourly via the scheduler, with a configurable retention window
  (`BACKUP_RETENTION_HOURS`, default 1 hour).
- ✅ WebAuthn challenge (`webauthn/login/options`) and device-lost recovery
  (`webauthn/lost`) routes now carry explicit per-IP throttles (10/min and
  3/min respectively), closing a gap next to their already-throttled
  register/password-reset siblings. This is the defense-in-depth lesson from
  Vaultwarden CVE-2026-43914 (a single un-throttled auth-adjacent endpoint
  can bypass login rate limiting).
- ✅ Documented the full auth-adjacent throttle grid above.

### v1.1.0 (2026-06-10) - Security Hardening
- ✅ CORS fully configurable via environment variables (`CORS_ALLOWED_ORIGINS`, `CORS_ALLOWED_METHODS`, `CORS_ALLOWED_HEADERS`, `CORS_MAX_AGE`)
- ✅ CSP middleware applied to `web`, `behind-auth`, and `api.v1` middleware groups with `base-uri`, `form-action`, `frame-ancestors` directives
- ✅ Rate limiting added to registration (5/hr) and password reset (3/hr) routes
- ✅ Encryption disable blocked when encrypted accounts exist
- ✅ Backup files encrypted at rest on the `backups` filesystem disk
- ✅ `CleanupBackupFiles` artisan command for stale backup file removal
- ✅ Stack traces no longer included in error responses
- ✅ Session lifetime and encryption configurable via environment variables

### v1.0.0 (2026-04-05) - Production Release
- ✅ Implemented E2EE with Argon2id + AES-256-GCM
- ✅ Zero-knowledge architecture (server never sees plaintext)
- ✅ HTTPS-only enforcement
- ✅ Rate limiting on authentication endpoints
- ✅ CSP and security headers
- ✅ Encrypted backup format (.vault)
- ✅ Audit logging for sensitive operations
- ✅ **97% test pass rate achieved (1,339/1,381 tests)**
- ✅ All security components tested and verified
- ✅ Backend production-ready for E2EE, Teams, Backup features

---

**Last updated:** 2026-06-10
**Version:** 1.1.0
**Test Status:** 97% pass rate - Production Ready
**Contact:** security@2fa-vault.example.com
