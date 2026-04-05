# 2FA-Vault Development Guide

2FA-Vault is an enhanced fork of [2FAuth](https://github.com/Bubka/2FAuth) adding zero-knowledge end-to-end encryption (E2EE), multi-user support, teams, browser extensions, and PWA capabilities.

## Tech Stack

- **Backend:** Laravel 12, PHP 8.4+
- **Frontend:** Vue 3 (Composition API), TypeScript, Vite
- **State Management:** Pinia stores
- **Crypto:** Argon2id (key derivation), AES-256-GCM (encryption), Web Crypto API
- **OTP Generation:** Spomky-Labs/OTPHP (RFC 4226 HOTP, RFC 6238 TOTP)
- **Browser Extension:** Chrome/Firefox manifest v3

## Build, Test, and Lint Commands

### Backend (PHP/Laravel)

```bash
# Install dependencies
composer install

# Run all tests
composer test                    # PHPUnit with SQLite
composer test-para               # Parallel test execution
composer test-mysql              # Tests with MySQL config

# Run a single test file
vendor/bin/phpunit tests/Feature/Http/Auth/UserControllerTest.php

# Run a specific test method
vendor/bin/phpunit --filter testUserCanLogin tests/Feature/Http/Auth/UserControllerTest.php

# Code formatting (Laravel Pint)
./vendor/bin/pint                # Auto-fix code style

# Static analysis (PHPStan/Larastan)
./vendor/bin/phpstan analyse --memory-limit=2G

# Generate IDE helper files
composer ide-helper
```

### Frontend (Vue/TypeScript)

```bash
# Install dependencies
npm install

# Development server with hot-reload
npm run dev                      # Runs at http://127.0.0.1:5173

# Production build
npm run build

# Watch mode for development
npm run rebuild

# Linting (ESLint)
npx eslint resources/js/**/*.{js,vue}
```

### Database Migrations

```bash
php artisan migrate              # Run migrations
php artisan migrate:fresh        # Drop all tables and re-migrate
php artisan migrate:rollback     # Rollback last batch
```

## Architecture Overview

### Zero-Knowledge E2EE Architecture

**Critical security principle:** The server NEVER sees plaintext OTP secrets. All encryption/decryption happens client-side.

#### Key Components

**Client-Side:**
- `resources/js/services/crypto.js` - Argon2id key derivation, AES-256-GCM encryption/decryption
- `resources/js/stores/crypto.js` - Encryption state management, vault locking/unlocking
- Encryption key stored in memory only (Pinia store), never in localStorage

**Server-Side:**
- `app/Http/Controllers/EncryptionController.php` - E2EE setup, verification, vault locking
- `app/Models/User.php` - Stores `encryption_salt`, `encryption_test_value`, `vault_locked` flag
- `app/Models/TwoFAccount.php` - Stores encrypted secrets as JSON: `{ciphertext, iv, authTag}`

#### Encryption Flow

1. **Setup:** User creates master password → Argon2id derives key → Encrypt test value → Store salt + encrypted test value
2. **Unlock:** User enters password → Derive key from salt → Decrypt test value → Verify → Store key in memory
3. **Encrypt Account:** Secret encrypted with in-memory key → Send ciphertext to server → Store as JSON
4. **Decrypt Account:** Fetch ciphertext from server → Decrypt with in-memory key → Display to user

**Important:** Master password NEVER sent to server. Password verification happens by attempting to decrypt the test value client-side.

### Backend Architecture

#### API Structure

- **Routes:** `routes/api/v1.php` - All API endpoints under `/api/v1/` prefix
- **Controllers:** `app/Api/v1/Controllers/` - API controllers (TOTP accounts, groups, icons, etc.)
- **Services:** `app/Services/` - Business logic layer (TwoFAccountService, GroupService, BackupService, etc.)
- **Models:** `app/Models/` - Eloquent models with relationships

#### Key Services

- `TwoFAccountService` - TOTP/HOTP account management, OTP generation
- `GroupService` - Account grouping and organization
- `BackupService` - Encrypted backup export/import (.vault format)
- `IconService` - Icon fetching and custom icon uploads
- `QrCodeService` - QR code scanning and decoding

#### Authentication

- **Guard:** `api-guard` (Laravel Passport OAuth2)
- **Middleware:** All `/api/v1/*` routes protected by `auth:api-guard`
- **WebAuthn:** Passwordless authentication via `laragear/webauthn` package
- **Social:** OAuth via `laravel/socialite` + `socialiteproviders/manager`

### Frontend Architecture

#### Directory Structure

```
resources/js/
├── components/       # Reusable Vue components
├── composables/      # Vue 3 composables (auto-imported)
├── layouts/          # Page layouts
├── router/           # Vue Router configuration
├── services/         # API clients and business logic
├── stores/           # Pinia stores (auto-imported)
└── views/            # Page components
```

#### Auto-Imports (Vite Plugin)

All exports from these directories are auto-imported (no explicit imports needed):
- `composables/**`
- `components/**`
- `stores/**`
- `services/**`
- Vue core: `ref`, `computed`, `onMounted`, etc.
- Vue Router: `useRoute`, `useRouter`
- Pinia: `defineStore`, `storeToRefs`

#### Key Stores (Pinia)

- `crypto.js` - Encryption state, vault locking, key management
- `twofaccounts.js` - TOTP account list, OTP generation
- `groups.js` - Account groups
- `user.js` - User preferences and settings
- `teams.js` - Team management (multi-user feature)
- `backup.js` - Backup/restore state
- `pwa.js` - PWA installation and offline support

#### Services Layer

- `crypto.js` - Cryptographic operations (Argon2id, AES-GCM)
- `twofaccountService.js` - TOTP account CRUD API calls
- `authService.js` - Authentication API calls
- `offline-totp.js` - Offline OTP generation for PWA
- `push-notifications.js` - Web Push API for notifications

### Browser Extension

Location: `browser-extension/`

**Architecture:**
- **Manifest v3** (Chrome/Firefox compatible)
- **Background service worker:** `background/service-worker.js`
- **Content scripts:** `content/content-script.js` - Detects input fields, auto-fills OTP
- **Popup:** `popup/` - Mini UI for quick OTP access
- **Options page:** `options/` - Extension settings

**Communication:** Extension ↔ Web app via `chrome.runtime.sendMessage()` and `window.postMessage()`

### Teams & Multi-User

**Models:**
- `Team` - Team entity with owner
- `TeamMember` - User membership in teams (roles: owner, admin, member)
- `TeamInvitation` - Pending team invitations
- `SharedAccount` - TOTP accounts shared with teams (with permissions)

**Key Difference from 2FAuth:** Original 2FAuth is single-user only. This fork adds full multi-tenancy with team-based sharing.

## Key Conventions

### Backend Conventions

#### Service Layer Pattern

All business logic lives in services (`app/Services/`), NOT in controllers:

```php
// ❌ Don't put logic in controllers
public function store(Request $request) {
    $account = TwoFAccount::create($request->all());
    // ... complex logic here
}

// ✅ Delegate to services
public function store(Request $request) {
    $account = $this->twofaccountService->create($request->validated());
    return response()->json($account, 201);
}
```

#### Encryption Handling

Always check the `encrypted` flag before processing secrets:

```php
// TwoFAccount model
if ($account->encrypted) {
    // Secret is JSON: {ciphertext, iv, authTag}
    // NEVER decrypt server-side
    $encryptedData = json_decode($account->secret);
} else {
    // Legacy non-encrypted account
    $plainSecret = $account->secret;
}
```

#### API Response Format

Use consistent JSON response structure:

```php
// Success
return response()->json($data, 200);

// Created
return response()->json($resource, 201);

// Error
return response()->json(['message' => 'Error description'], 422);

// Validation errors (automatic via FormRequest)
```

#### Model Relationships

Use Laravel's relationship methods for eager loading:

```php
// ✅ Eager load to avoid N+1
$accounts = TwoFAccount::with('group', 'icon')->get();

// ✅ Use service layer for complex queries
$accounts = $this->twofaccountService->getAllWithRelations();
```

### Frontend Conventions

#### Composition API Only

All Vue components use Composition API (`<script setup>`):

```vue
<script setup>
// Auto-imported: ref, computed, onMounted, etc.
const account = ref(null)
const isEncrypted = computed(() => account.value?.encrypted)

onMounted(() => {
  loadAccount()
})
</script>
```

#### Encryption Before API Calls

ALWAYS encrypt secrets client-side before sending to server:

```javascript
import { encryptSecret } from '@/services/crypto'
import { useCryptoStore } from '@/stores/crypto'

const cryptoStore = useCryptoStore()

async function createAccount(accountData) {
  if (cryptoStore.isVaultUnlocked) {
    // Encrypt secret before API call
    const encrypted = await encryptSecret(accountData.secret, cryptoStore.encryptionKey)
    accountData.secret = JSON.stringify(encrypted)
    accountData.encrypted = true
  }
  
  // Now send to server
  await twofaccountService.create(accountData)
}
```

#### Decryption After API Response

Decrypt encrypted secrets immediately after fetching:

```javascript
import { decryptSecret } from '@/services/crypto'

async function loadAccounts() {
  const accounts = await twofaccountService.getAll()
  
  // Decrypt each account's secret
  for (const account of accounts) {
    if (account.encrypted) {
      const encryptedData = JSON.parse(account.secret)
      account.secret = await decryptSecret(encryptedData, cryptoStore.encryptionKey)
    }
  }
  
  return accounts
}
```

#### Store Pattern

Use Pinia stores for state management. Define actions for all state mutations:

```javascript
export const useTwoFAccountStore = defineStore('twofaccounts', {
  state: () => ({
    accounts: [],
    loading: false,
  }),
  
  getters: {
    encryptedAccounts: (state) => state.accounts.filter(a => a.encrypted),
  },
  
  actions: {
    async fetchAccounts() {
      this.loading = true
      this.accounts = await twofaccountService.getAll()
      this.loading = false
    },
  },
})
```

#### TypeScript Usage

Use TypeScript for services and composables:

```typescript
// services/crypto.ts
export interface EncryptedData {
  ciphertext: string
  iv: string
  authTag: string
}

export async function encryptSecret(
  plaintext: string,
  key: CryptoKey
): Promise<EncryptedData> {
  // Implementation
}
```

### Testing Conventions

#### PHPUnit Test Organization

```
tests/
├── Unit/           # Unit tests (isolated, no DB)
├── Feature/        # Feature tests (with DB)
└── Api/v1/         # API endpoint tests
```

#### API Test Pattern

```php
public function test_user_can_create_encrypted_account()
{
    $user = User::factory()->create();
    $this->actingAs($user, 'api-guard');
    
    $response = $this->postJson('/api/v1/twofaccounts', [
        'service' => 'GitHub',
        'account' => 'user@example.com',
        'secret' => '{"ciphertext":"...","iv":"...","authTag":"..."}',
        'encrypted' => true,
    ]);
    
    $response->assertStatus(201);
    $this->assertDatabaseHas('twofaccounts', [
        'service' => 'GitHub',
        'encrypted' => true,
    ]);
}
```

### Database Conventions

#### Migration Naming

- Create table: `YYYY_MM_DD_HHMMSS_create_table_name_table.php`
- Modify table: `YYYY_MM_DD_HHMMSS_add_column_to_table_name_table.php`

#### Encrypted Column Storage

Encrypted secrets are stored as JSON strings:

```sql
-- TwoFAccounts table
secret TEXT  -- Stores: {"ciphertext":"base64","iv":"base64","authTag":"base64"}
encrypted BOOLEAN DEFAULT FALSE
```

## Security Guidelines

### Never Log Secrets

```php
// ❌ NEVER
Log::info('Secret: ' . $account->secret);

// ✅ Safe
Log::info('Account created', ['id' => $account->id, 'encrypted' => $account->encrypted]);
```

### Validate E2EE Setup

Before enabling E2EE features, ensure:
1. User has set up encryption (`encryption_salt` and `encryption_test_value` exist)
2. Vault is unlocked (`vault_locked = false`)
3. Encryption key is in memory (client-side Pinia store)

### CSRF Protection

All API routes automatically protected by Laravel Sanctum CSRF. For Vue:

```javascript
// Axios automatically handles CSRF tokens via cookie
await axios.post('/api/v1/twofaccounts', data)
```

### Rate Limiting

Authentication endpoints are rate-limited:
- Login: 5 attempts per minute (configurable via `RATE_LIMIT_LOGIN`)
- API: 60 requests per minute (configurable via `RATE_LIMIT_API`)

## Common Pitfalls

### 1. Encryption Key Lifecycle

❌ **Wrong:** Storing encryption key in localStorage
```javascript
localStorage.setItem('encryptionKey', key) // NEVER!
```

✅ **Correct:** Store in Pinia (memory only)
```javascript
const cryptoStore = useCryptoStore()
cryptoStore.setEncryptionKey(key) // Cleared on page reload
```

### 2. Mixed Encrypted/Unencrypted Accounts

The system supports BOTH encrypted and non-encrypted accounts (backward compatibility). Always check:

```javascript
if (account.encrypted) {
  const parsed = JSON.parse(account.secret)
  const decrypted = await decryptSecret(parsed, key)
} else {
  // Legacy plaintext secret
  const secret = account.secret
}
```

### 3. Server-Side Secret Access

❌ **Wrong:** Trying to decrypt secrets server-side
```php
// Server cannot decrypt! No encryption key available
$plainSecret = decrypt($account->secret);
```

✅ **Correct:** Server only stores/retrieves encrypted data
```php
// Just return encrypted data as-is
return response()->json(['secret' => $account->secret, 'encrypted' => true]);
```

### 4. Auto-Import Confusion

With Vite's auto-import plugin, explicit imports may cause conflicts:

❌ **Avoid:**
```vue
<script setup>
import { ref } from 'vue' // Already auto-imported
import { useTwoFAccountStore } from '@/stores/twofaccounts' // Already auto-imported
</script>
```

✅ **Use auto-imports:**
```vue
<script setup>
// Just use directly - they're auto-imported
const count = ref(0)
const store = useTwoFAccountStore()
</script>
```

### 5. Git Branch Strategy

Always make PRs to the `dev` branch (not `main`):

```bash
git checkout dev
git pull origin dev
git checkout -b feature/my-feature
# ... make changes ...
git push origin feature/my-feature
# Create PR targeting 'dev' branch
```

## Related Documentation

- [ARCHITECTURE.md](../docs/ARCHITECTURE.md) - Detailed technical architecture
- [SECURITY.md](../docs/SECURITY.md) - Security model and threat analysis
- [CONTRIBUTING.md](CONTRIBUTING.md) - Contribution guidelines
- [MIGRATION.md](../docs/MIGRATION.md) - Migration from 2FAuth

## GitNexus Integration

This project is indexed by GitNexus for code intelligence. Before editing any symbol, always run impact analysis:

```bash
# Check what breaks if you modify a function
npx gitnexus impact <symbolName>

# After making changes, verify scope
npx gitnexus detect-changes

# Refresh index after commits
npx gitnexus analyze
```

See [CLAUDE.md](../CLAUDE.md) and [AGENTS.md](../AGENTS.md) for AI assistant workflows.
