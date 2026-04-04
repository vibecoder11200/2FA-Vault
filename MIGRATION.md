# Migration Guide: 2FAuth → 2FA-Vault

This guide helps you migrate from the original **2FAuth** to **2FA-Vault** (the zero-knowledge E2EE fork).

## 🔄 Overview

**2FA-Vault** is a hard fork of 2FAuth v6.1.3 with significant architectural changes:

- **E2EE encryption** (zero-knowledge, client-side encryption)
- **Multi-user support** (teams, roles, invites)
- **Encrypted backups** (.vault format instead of .json)
- **Browser extension** (Chrome/Firefox)
- **PWA support** (installable, offline mode)
- **Breaking changes** (see below)

## ⚠️ Breaking Changes

| Feature | 2FAuth | 2FA-Vault | Impact |
|---------|--------|-----------|--------|
| **Encryption** | Optional | **Mandatory** | All data encrypted by default |
| **Backup format** | `.json` | `.vault` (encrypted) | Old backups incompatible |
| **Password** | Laravel auth | **Master password** (Argon2id) | Separate from login password |
| **Database schema** | Single-user | Multi-user (teams/roles) | Requires migration script |
| **API** | `/api/v1/` | `/api/v1/` + new E2EE endpoints | Some endpoints changed |
| **WebAuthn** | Auth only | Auth + biometric unlock | Extended functionality |

## 📋 Migration Path

### Option A: Fresh Install (Recommended)

**Best for:** Small number of accounts (&lt;20), prefer clean start

1. **Export from 2FAuth:**
   - Log into 2FAuth
   - Settings → Backup → Export accounts
   - Save `2fauth-backup-YYYY-MM-DD.json`

2. **Install 2FA-Vault:**
   ```bash
   git clone https://github.com/yourusername/2FA-Vault.git
   cd 2FA-Vault
   docker-compose up -d
   ```

3. **Create account in 2FA-Vault:**
   - Visit http://localhost:8000
   - Register new account
   - Set strong master password (write it down!)

4. **Import accounts:**
   - Settings → Import
   - Choose "2FAuth JSON format"
   - Upload `2fauth-backup-YYYY-MM-DD.json`
   - Accounts will be encrypted automatically

5. **Verify:**
   - Check all accounts imported correctly
   - Test TOTP code generation
   - Export encrypted backup (.vault)

6. **Decommission old 2FAuth:**
   ```bash
   cd /path/to/old-2fauth
   docker-compose down
   # Keep backup file in safe place!
   ```

### Option B: In-Place Migration (Advanced)

**Best for:** Large deployments, preserve database history

**⚠️ WARNING:** This modifies your existing database. **Backup first!**

1. **Backup 2FAuth database:**
   ```bash
   docker exec 2fauth-mysql mysqldump -u root -p 2fauth > 2fauth-backup.sql
   ```

2. **Export accounts:**
   ```bash
   # From 2FAuth UI
   Settings → Backup → Export all accounts
   ```

3. **Stop 2FAuth:**
   ```bash
   docker-compose down
   ```

4. **Clone 2FA-Vault:**
   ```bash
   git clone https://github.com/yourusername/2FA-Vault.git /path/to/2FA-Vault
   cd /path/to/2FA-Vault
   ```

5. **Copy environment:**
   ```bash
   cp /path/to/old-2fauth/.env .env
   # Edit .env to add new variables (see .env.example)
   ```

6. **Run database migration:**
   ```bash
   # This script transforms single-user DB to multi-user schema
   docker-compose up -d mysql
   docker-compose exec app php artisan migrate:2fauth
   ```

   **The migration script will:**
   - Create `teams`, `team_user`, `roles` tables
   - Create default "Personal" team for existing user
   - Migrate `twofaccounts` to use team ownership
   - Preserve all existing data

7. **Set master password:**
   ```bash
   # First login will prompt for master password setup
   # This encrypts all existing accounts
   docker-compose exec app php artisan encrypt:legacy-data
   ```

8. **Verify:**
   ```bash
   docker-compose logs -f app
   # Check for encryption success messages
   ```

9. **Start 2FA-Vault:**
   ```bash
   docker-compose up -d
   ```

10. **Test:**
    - Log in with existing email/password
    - Set master password (one-time setup)
    - Verify all accounts visible and working
    - Export encrypted backup

## 🗂️ Database Migration Details

### Schema Changes

**New tables:**
```sql
-- Teams (multi-user support)
CREATE TABLE teams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Team membership with roles
CREATE TABLE team_user (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(50) DEFAULT 'member',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Team invites
CREATE TABLE team_invites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'member',
    code VARCHAR(64) UNIQUE NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);

-- Push notification subscriptions
CREATE TABLE push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    endpoint TEXT NOT NULL,
    public_key VARCHAR(255),
    auth_token VARCHAR(255),
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Audit logs
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    action VARCHAR(255) NOT NULL,
    model VARCHAR(255),
    model_id BIGINT UNSIGNED,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
```

**Modified tables:**
```sql
-- Add team ownership to twofaccounts
ALTER TABLE twofaccounts ADD COLUMN team_id BIGINT UNSIGNED AFTER user_id;
ALTER TABLE twofaccounts ADD FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE;

-- Add encryption metadata
ALTER TABLE users ADD COLUMN encryption_salt VARCHAR(255) AFTER password;
ALTER TABLE users ADD COLUMN encryption_enabled BOOLEAN DEFAULT TRUE AFTER encryption_salt;
```

### Migration Script

The `php artisan migrate:2fauth` command runs:

```php
// database/migrations/2026_04_04_000000_migrate_from_2fauth.php

public function up()
{
    // 1. Get existing user (2FAuth is single-user)
    $user = User::first();
    
    if ($user) {
        // 2. Create default "Personal" team
        $team = Team::create([
            'name' => 'Personal',
            'owner_id' => $user->id,
        ]);
        
        // 3. Attach user to team as owner
        $team->users()->attach($user->id, ['role' => 'owner']);
        
        // 4. Migrate all accounts to team
        TwoFAccount::where('user_id', $user->id)
            ->update(['team_id' => $team->id]);
    }
    
    // 5. Create audit log entry
    AuditLog::create([
        'action' => 'migration_from_2fauth',
        'ip_address' => request()->ip(),
    ]);
}
```

## 🔐 Encryption Migration

### Unencrypted → Encrypted

If your 2FAuth accounts were **not encrypted**:

1. **First login to 2FA-Vault:**
   - System detects unencrypted accounts
   - Prompts for new master password
   - Encrypts all accounts with Argon2id + AES-256-GCM

2. **Run manual encryption (if needed):**
   ```bash
   docker-compose exec app php artisan encrypt:legacy-data
   ```

   ```
   🔐 Encrypting legacy data...
   
   Enter master password: ********
   Confirm master password: ********
   
   ✓ Derived encryption key (Argon2id)
   ✓ Encrypted 47 accounts
   ✓ Updated encryption metadata
   
   Migration complete! All data now encrypted.
   ```

### 2FAuth Encrypted → 2FA-Vault Encrypted

If your 2FAuth accounts were **already encrypted** (with old encryption):

1. **Export from 2FAuth** (decrypts during export)
2. **Import to 2FA-Vault** (re-encrypts with stronger Argon2id)

**Why re-encrypt?**
- 2FAuth used Laravel's built-in encryption (simpler)
- 2FA-Vault uses Argon2id + AES-256-GCM (stronger, zero-knowledge)

## 📦 Backup Format Migration

### Old Format (2FAuth JSON)

```json
{
  "app": "2FAuth",
  "version": "6.1.3",
  "datetime": "2026-04-04T10:30:00Z",
  "accounts": [
    {
      "service": "GitHub",
      "account": "user@example.com",
      "secret": "JBSWY3DPEHPK3PXP",
      "algorithm": "sha1",
      "digits": 6,
      "period": 30
    }
  ]
}
```

### New Format (2FA-Vault Encrypted)

```json
{
  "app": "2FA-Vault",
  "version": "1.0.0",
  "datetime": "2026-04-04T10:30:00Z",
  "encryption": {
    "algorithm": "aes-256-gcm",
    "kdf": "argon2id",
    "memory": 65536,
    "iterations": 3,
    "parallelism": 4,
    "salt": "base64-encoded-salt"
  },
  "data": "base64-encoded-encrypted-blob",
  "iv": "base64-encoded-iv",
  "tag": "base64-encoded-auth-tag"
}
```

**Import compatibility:**
- ✅ 2FA-Vault can import 2FAuth JSON (auto-encrypts)
- ❌ 2FAuth cannot import 2FA-Vault .vault files

## 🐳 Docker Configuration

### Old docker-compose.yml (2FAuth)

```yaml
version: '3.8'
services:
  app:
    image: 2fauth/2fauth:6.1.3
    ports:
      - "8000:8000"
    volumes:
      - ./data:/2fauth
```

### New docker-compose.yml (2FA-Vault)

```yaml
version: '3.8'
services:
  app:
    build: .
    ports:
      - "8000:8000"
    environment:
      - DB_HOST=mysql
      - REDIS_HOST=redis
    volumes:
      - ./storage:/var/www/html/storage
    depends_on:
      - mysql
      - redis

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
      MYSQL_DATABASE: 2fa_vault
    volumes:
      - mysql-data:/var/lib/mysql

  redis:
    image: redis:7-alpine
    command: redis-server --requirepass ${REDIS_PASSWORD}
    volumes:
      - redis-data:/data

volumes:
  mysql-data:
  redis-data:
```

## 🔧 Configuration Changes

### New Environment Variables

Add to `.env`:

```bash
# E2EE Settings
ENCRYPTION_ENABLED=true
ARGON2_MEMORY=65536      # 64 MB
ARGON2_TIME=3            # 3 iterations
ARGON2_THREADS=4         # 4 parallel threads

# Multi-user Settings
ALLOW_REGISTRATION=false  # Set true to allow signups
MAX_TEAMS_PER_USER=5      # Limit teams per user

# Push Notifications (Web Push)
VAPID_PUBLIC_KEY=<generate-with-web-push>
VAPID_PRIVATE_KEY=<generate-with-web-push>
VAPID_SUBJECT=mailto:admin@example.com

# PWA Settings
PWA_NAME="2FA-Vault"
PWA_SHORT_NAME="2FA-Vault"
PWA_THEME_COLOR="#4F46E5"
```

**Generate VAPID keys:**
```bash
npm install -g web-push
web-push generate-vapid-keys
```

## ✅ Post-Migration Checklist

- [ ] All accounts imported successfully
- [ ] TOTP codes generating correctly
- [ ] Master password set and saved securely
- [ ] Encrypted backup (.vault) exported
- [ ] Browser extension installed (optional)
- [ ] PWA installed on mobile (optional)
- [ ] Biometric unlock configured (optional)
- [ ] Old 2FAuth backup secured
- [ ] Old 2FAuth instance decommissioned
- [ ] Team members invited (if multi-user)

## 🆘 Troubleshooting

### "Cannot decrypt accounts"
**Cause:** Wrong master password or corrupted data  
**Fix:**
```bash
# Reset encryption (will lose data!)
docker-compose exec app php artisan encrypt:reset
# Re-import from backup
```

### "Migration failed: foreign key constraint"
**Cause:** Database schema mismatch  
**Fix:**
```bash
# Rollback migration
docker-compose exec app php artisan migrate:rollback
# Re-run migration
docker-compose exec app php artisan migrate:2fauth
```

### "Push notifications not working"
**Cause:** Missing VAPID keys  
**Fix:**
```bash
# Generate VAPID keys
npm install -g web-push
web-push generate-vapid-keys --json

# Add to .env
VAPID_PUBLIC_KEY=<public-key>
VAPID_PRIVATE_KEY=<private-key>

# Restart app
docker-compose restart app
```

### "Browser extension not syncing"
**Cause:** API credentials mismatch  
**Fix:**
- Extension → Settings → Re-login
- Generate new API token in web UI
- Update extension credentials

## 📞 Support

**Issues:** https://github.com/yourusername/2FA-Vault/issues  
**Security:** security@2fa-vault.example.com  
**Docs:** https://docs.2fa-vault.example.com

## 📚 Additional Resources

- [2FA-Vault Documentation](https://docs.2fa-vault.example.com)
- [Security Architecture](SECURITY.md)
- [Changelog](CHANGELOG.md)
- [Original 2FAuth](https://github.com/Bubka/2FAuth)

---

**Last updated:** 2026-04-04  
**Migration script version:** 1.0.0
