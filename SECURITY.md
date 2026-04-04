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
| **Brute force** | Rate limiting on auth endpoints | ✅ Protected |
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

## 🔐 Security Best Practices

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
// Already configured in app/Http/Middleware/SecurityHeaders.php
Content-Security-Policy: default-src 'self'
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Strict-Transport-Security: max-age=31536000
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

## 🔄 Security Changelog

### v1.0.0 (2026-04-04)
- ✅ Implemented E2EE with Argon2id + AES-256-GCM
- ✅ Zero-knowledge architecture
- ✅ HTTPS-only enforcement
- ✅ Rate limiting on authentication endpoints
- ✅ CSP and security headers
- ✅ Encrypted backup format (.vault)
- ✅ Audit logging for sensitive operations

---

**Last updated:** 2026-04-04  
**Version:** 1.0.0  
**Contact:** security@2fa-vault.example.com
