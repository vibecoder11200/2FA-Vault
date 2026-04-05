# System Architecture

Detailed technical architecture, data flow, and system design of 2FA-Vault.

## System Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                          2FA-Vault System                           │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌──────────────────┐                    ┌──────────────────────┐   │
│  │  Web Browser     │                    │  Browser Extension   │   │
│  │  (Vue 3 SPA)     │                    │  (Popup + Content)   │   │
│  └────────┬─────────┘                    └──────────┬───────────┘   │
│           │                                         │               │
│           └─────────────┬──────────────────────────┘               │
│                         │ HTTPS                                   │
│                         ▼                                         │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │          Laravel 12 REST API (/api/v1)                  │   │
│  │  ┌────────────────────────────────────────────────────┐ │   │
│  │  │ Controllers → Services → Models                   │ │   │
│  │  └────────────────────────────────────────────────────┘ │   │
│  │  ┌────────────────────────────────────────────────────┐ │   │
│  │  │ Authentication: Laravel Passport (OAuth2)         │ │   │
│  │  │ Authorization: Policies + Middleware              │ │   │
│  │  └────────────────────────────────────────────────────┘ │   │
│  └──────────────────────┬───────────────────────────────────┘   │
│                         │                                       │
│           ┌─────────────┴─────────────┐                        │
│           ▼                           ▼                        │
│  ┌──────────────────┐      ┌──────────────────┐              │
│  │  Database        │      │  Redis Cache/    │              │
│  │  (MySQL/PG/      │      │  Session Store   │              │
│  │   SQLite)        │      └──────────────────┘              │
│  └──────────────────┘                                        │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ Encrypted Storage (Secrets at REST)                     │   │
│  │ - All OTP secrets stored as AES-256-GCM ciphertext     │   │
│  │ - Never decrypted server-side                          │   │
│  │ - Client-side encryption/decryption only               │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

## Layered Architecture

### 1. Presentation Layer

#### Web UI (Vue 3)
**Location:** `resources/js/`

```
Routing (Vue Router)
  ↓
Page Components (Views)
  ├── Components (Reusable UI)
  ├── Stores (Pinia state)
  └── Services (API calls + crypto)
```

**Key Flows:**
- User navigates → Router guards check auth → Fetch data → Display
- User submits form → Encrypt (if needed) → API call → Update store → Re-render

#### Browser Extension
**Location:** `browser-extension/`

```
Background Service Worker (listens for navigation)
  ↓
Content Script (injected into login pages)
  ↓
Popup UI (shows matching accounts)
  ↓
User clicks → Copy TOTP → Paste into form
```

**Architecture:** Manifest v3 compliant, separate from web app encryption key

### 2. API Layer

**Location:** `app/Api/v1/`

#### Request Flow
```
HTTP Request (with Bearer token)
  ↓
Route matching (api/v1/...)
  ↓
Middleware stack
  ├── CORS handling
  ├── Auth verification (api-guard)
  ├── Rate limiting
  └── Request logging
  ↓
FormRequest validation
  ├── Authorization (can user access?)
  ├── Validation rules (data format correct?)
  └── Early exit on failure (422 response)
  ↓
Controller receives validated request
  ├── Calls service method
  ├── Handles exceptions
  └── Transforms response
  ↓
Resource class (transforms model to JSON)
  ├── Includes relationships
  ├── Formats timestamps
  └── Excludes sensitive fields
  ↓
HTTP Response (200/201/422/etc)
```

#### Endpoints by Resource

**TwoFAccounts** (`TwoFAccountController`)
```
GET    /api/v1/twofaccounts          # List all user accounts
POST   /api/v1/twofaccounts          # Create new account
GET    /api/v1/twofaccounts/{id}     # Get single account
PUT    /api/v1/twofaccounts/{id}     # Update account
DELETE /api/v1/twofaccounts/{id}     # Delete account
GET    /api/v1/twofaccounts/{id}/otp # Generate OTP
POST   /api/v1/twofaccounts/batch    # Batch import
```

**Groups** (`GroupController`)
```
GET    /api/v1/groups               # List groups
POST   /api/v1/groups               # Create group
PUT    /api/v1/groups/{id}          # Update group
DELETE /api/v1/groups/{id}          # Delete group
```

**Settings** (`SettingController`)
```
GET    /api/v1/settings             # Get all settings
PUT    /api/v1/settings/{setting}   # Update setting
```

**User** (`UserController`)
```
GET    /api/v1/user                 # Get current user
PUT    /api/v1/user                 # Update profile
POST   /api/v1/user/password        # Change password
```

### 3. Service Layer

**Location:** `app/Services/`

Each service encapsulates business logic for a domain:

```php
TwoFAccountService
├── create(array): TwoFAccount       # Validate, encrypt, store
├── update(TwoFAccount, array): void # Update with re-encryption
├── delete(TwoFAccount): bool        # Mark as deleted
├── getAllWithRelations(): Collection
├── generateOtp(TwoFAccount): string # Generate TOTP for account
└── export(Collection): array        # Export for backup

BackupService
├── exportEncrypted(User, pwd): InputStream
├── importEncrypted(InputStream, pwd): Collection
└── validateBackupFile(InputStream): bool

GroupService
├── create(array): Group
├── update(Group, array): void
├── assignAccounts(Group, Collection): void
└── deleteWithAccounts(Group): void

EncryptionService
├── setupEncryption(User, password): void
├── unlockVault(User, password): CryptoKey
├── reEncryptAccount(TwoFAccount, CryptoKey): void
└── verifyEncryptionKey(User, CryptoKey): bool
```

### 4. Model Layer (Eloquent)

**Location:** `app/Models/`

#### Core Models

**User**
```php
User
├── Relationships
│   ├── twofaccounts() → HasMany[TwoFAccount]
│   ├── groups() → HasMany[Group]
│   ├── teams() → BelongsToMany[Team]
│   ├── webauthn_credentials() → HasMany[WebauthnCredential]
│   └── settings() → HasMany[Setting]
├── E2EE Fields
│   ├── encryption_salt: string (unique per user)
│   ├── encryption_test_value: string (encrypted test)
│   ├── vault_locked: boolean (lock status)
│   └── encoding: string (encryption format version)
└── Methods
    ├── hasEncryption(): bool
    ├── isVaultLocked(): bool
    └── setEncryptionPassword(string): void
```

**TwoFAccount**
```php
TwoFAccount
├── Relationships
│   ├── user() → BelongsTo[User]
│   ├── group() → BelongsTo[Group]
│   ├── icon() → BelongsTo[Icon]
│   ├── sharedWith() → BelongsToMany[Team]
│   └── otp_generators() → HasMany[OtpGenerator]
├── Secret Storage
│   ├── secret: string|json (encrypted or plaintext)
│   ├── encrypted: boolean (encryption flag)
│   ├── secret_size: integer (bytes)
│   └── service_field_encryption: array (field encryption metadata)
├── Account Data
│   ├── service: string (GitHub, Google, etc)
│   ├── account: string (username/email)
│   ├── otp_type: enum (totp|hotp|steam)
│   └── algorithm: string (sha1|sha256|sha512)
└── Methods
    ├── otp(): string (generate current OTP)
    ├── getDecryptedSecret(): string (frontend calls - not used)
    └── toArray(): array (JSON serialization)
```

**Group**
```php
Group
├── Relationships
│   ├── user() → BelongsTo[User]
│   └── twofaccounts() → HasMany[TwoFAccount]
├── Fields
│   ├── name: string
│   └── position: integer (for sorting)
└── Methods
    ├── accountCount(): int
    └── moveUp/moveDown(): void
```

**Team** (Multi-user)
```php
Team
├── Relationships
│   ├── owner() → BelongsTo[User]
│   ├── members() → BelongsToMany[User] via TeamMember
│   ├── invitations() → HasMany[TeamInvitation]
│   └── shared_accounts() → BelongsToMany[TwoFAccount]
├── Fields
│   ├── name: string
│   └── created_at: timestamp
└── Methods
    ├── isOwner(User): bool
    ├── isMember(User): bool
    ├── invite(email, role): TeamInvitation
    └── removeUser(User): void
```

### 5. Database Layer

#### Schema Overview

```sql
users
├── id (PK)
├── name, email (unique)
├── password_hash (hashed)
├── encryption_salt (unique, E2EE)
├── encryption_test_value (encrypted test, E2EE)
├── vault_locked (boolean, E2EE)
├── created_at, updated_at

twofaccounts
├── id (PK)
├── user_id (FK → users)
├── group_id (FK → groups, nullable)
├── service, account
├── secret (TEXT, can be plaintext or JSON)
├── encrypted (boolean, E2EE flag)
├── secret_size (integer, bytes)
├── otp_type (enum: totp, hotp, steam)
├── algorithm (enum: sha1, sha256, sha512)
├── period (integer, interval)
├── digits (integer, code length)
├── counter (integer, HOTP state)
├── created_at, updated_at

groups
├── id (PK)
├── user_id (FK → users)
├── name
├── position (ordering)
├── created_at, updated_at

teams
├── id (PK)
├── owner_id (FK → users)
├── name
├── created_at, updated_at

team_members
├── id (PK)
├── team_id (FK → teams)
├── user_id (FK → users)
├── role (enum: owner, admin, member, viewer)
├── joined_at

team_invitations
├── id (PK)
├── team_id (FK → teams)
├── email
├── invited_by_id (FK → users)
├── expires_at

icons
├── id (PK)
├── user_id (FK → users, nullable)
├── service (unique if user_id)
├── data (BLOB, image data)
├── created_at, updated_at

settings
├── id (PK)
├── user_id (FK → users)
├── key (unique per user)
├── value (string or JSON)
├── created_at, updated_at

oauth_clients (Laravel Passport)
├── id, user_id, name, secret, redirect
├── scopes, revoked, created_at

oauth_tokens (Laravel Passport)
├── id, user_id, client_id, token, expires_at
├── revoked, created_at
```

#### Encryption in Database

**Secret Storage (Encrypted):**
```sql
-- Row in twofaccounts
{
  "id": 123,
  "service": "GitHub",
  "account": "user@example.com",
  "secret": "{\"ciphertext\":\"jL2tZp...\",\"iv\":\"3kL9m...\",\"authTag\":\"vX4qP...\"}",
  "encrypted": true,
  ...
}
```

**Decryption Happens Client-Side:**
1. JavaScript fetches encrypted secret
2. Calls `decryptSecret(parsedJSON, encryptionKey)`
3. Returns plaintext to user
4. Never persisted in plaintext in browser localStorage

### 6. Authentication & Authorization

#### Authentication Flow

```
User Login (Email + Password)
  ↓
LoginController validates credentials
  ├── Check email exists
  ├── Hash password matches
  └── Return success/failure
  ↓
  ▼ (Success)
  Laravel Passport OAuth2 token generation
  ├── Create personal access token (or authorization code flow)
  ├── Return Bearer token to frontend
  └── Token stored in memory (not localStorage)
  ↓
API Requests
  ├── Include header: Authorization: Bearer {token}
  ├── Middleware verifies token
  ├── Request proceeds with auth()->user()
  └── Middleware rejects if invalid/expired
```

**Token Storage:** Axios automatically includes from cookies (Sanctum CSRF)

#### Authorization (Policies)

```php
// TwoFAccountPolicy
can($user, 'view', TwoFAccount $account)
  → $account->user_id === $user->id || $user->isTeamMember($account->team)

can($user, 'update', TwoFAccount $account)
  → $account->user_id === $user->id || $user->isTeamAdmin($account->team)

can($user, 'delete', TwoFAccount $account)
  → $account->user_id === $user->id
```

### 7. Encryption Architecture

#### Client-Side Encryption Stack

```
User Master Password
  ↓
Argon2id Key Derivation
├── Input: password + user salt (from server)
├── Parameters: 100,000 iterations, 32 bytes output
└── Output: Encryption key (never sent to server)
  ↓
AES-256-GCM Encryption
├── Input: plaintext secret, encryption key
├── Parameters: 12-byte random IV, 128-bit auth tag
└── Output: {ciphertext, iv, authTag} as JSON
  ↓
Server Storage
└── JSON string stored in database.twofaccounts.secret
```

#### Encryption Verification

```
Setup E2EE
  ├── User enters password
  ├── Derive key with Argon2id + salt
  ├── Encrypt test value
  ├── Store {salt, encrypted_test_value} in users table
  └── Client stores key in memory (Pinia store)

Unlock Vault
  ├── User enters password
  ├── Derive key with stored salt
  ├── Decrypt test value
  ├── Compare: decrypted_value === original_test_value
  ├── If match: password correct, key is valid
  └── If no match: password incorrect, key is invalid

Create Account
  ├── Check: isVaultUnlocked? (key in memory?)
  ├── Encrypt secret with key
  ├── Send JSON ciphertext to server
  └── Store encrypted in database

Fetch Account
  ├── Server returns encrypted JSON
  ├── Client decrypts with key from memory
  ├── Return plaintext to Vue component
  └── Render secret to user
```

### 8. State Management (Pinia)

#### Store Architecture

```
Crypto Store (crypto.js)
├── State
│   ├── encryptionKey: CryptoKey | null (memory only)
│   ├── vaultLocked: boolean
│   ├── encryptionTestValue: string | null
│   └── encryptionSalt: string | null
├── Getters
│   ├── isVaultUnlocked: boolean
│   └── isE2EEEnabled: boolean
└── Actions
    ├── setupEncryption(password)
    ├── unlockVault(password)
    ├── lockVault()
    └── clearKey()

TwoFAccounts Store (twofaccounts.js)
├── State
│   ├── accounts: TwoFAccount[]
│   ├── selectedAccount: TwoFAccount | null
│   └── loading: boolean
├── Getters
│   ├── countByGroup: {[groupId]: count}
│   ├── encryptedAccounts: TwoFAccount[]
│   └── sortedByGroup: TwoFAccount[]
└── Actions
    ├── fetchAll()
    ├── create(data)
    ├── update(id, data)
    ├── delete(id)
    └── generateOtp(accountId)

Groups Store (groups.js)
├── State
│   ├── groups: Group[]
│   └── selectedGroup: Group | null
└── Actions
    ├── fetchAll()
    ├── create(name)
    ├── update(id, name)
    └── delete(id)

User Store (user.js)
├── State
│   ├── user: User | null
│   ├── preferences: {theme, language, ...}
│   └── authenticated: boolean
└── Actions
    ├── fetchUser()
    ├── updateProfile(data)
    └── logout()
```

### 9. Data Flow: Create Account

```
Frontend: CreateAccount.vue
  ├── User fills form (service, account, secret)
  ├── User submits
  └── Call twofaccountService.create(data)

twofaccountService
  ├── Check: is vault unlocked?
  ├── If yes:
  │   ├── Call encryptSecret(data.secret, cryptoStore.key)
  │   ├── Receive {ciphertext, iv, authTag}
  │   ├── Set data.encrypted = true
  │   └── Set data.secret = JSON.stringify(encrypted)
  ├── Call HTTP POST /api/v1/twofaccounts with data
  └── Return created account

Laravel API
  ├── TwoFAccountController.store() receives request
  ├── FormRequest validates data
  ├── Call TwoFAccountService.create(validated)
  └── Service:
      ├── Create model with data
      ├── NO DECRYPTION (secret stays as JSON)
      ├── Save to database
      └── Return account
  ├── TwoFAccountResource transforms to JSON
  └── Return 201 Created response

Frontend (response received)
  ├── twofaccountService returns created account
  ├── twofaccountStore.addAccount(created)
  ├── Component updates UI
  └── User sees new account in list
```

### 10. Data Flow: Fetch & Display Account

```
Frontend: Accounts.vue
  ├── Component mounted
  ├── Call twofaccountStore.fetchAll()
  └── Call twofaccountService.getAll()

twofaccountService.getAll()
  ├── HTTP GET /api/v1/twofaccounts?limit=100
  └── Return array of accounts

Laravel API
  ├── TwoFAccountController.index() receives request
  ├── Authorize: can user see these accounts?
  ├── Eager load: with('group', 'icon')
  ├── Apply pagination
  ├── TwoFAccountCollection transforms
  └── Return JSON response

Frontend (response received)
  ├── Process accounts:
  │   ├── For each account:
  │   │   ├── Check: account.encrypted?
  │   │   ├── If yes:
  │   │   │   ├── Parse JSON secret
  │   │   │   ├── Call decryptSecret(parsed, cryptoStore.key)
  │   │   │   ├── Set account.secret = decrypted plaintext
  │   │   │   └── Mark account as decrypted
  │   │   └── Store in twofaccountStore
  ├── Component renders account.secret (plaintext)
  └── User sees decrypted OTP secret
```

### 11. Request Authentication Flow

```
API Request with Bearer Token
  ├── Frontend includes header:
  │   └── Authorization: Bearer eyJ0eXAi...
  ├── Request travels over HTTPS
  └── Reaches Laravel API

Middleware: auth:api-guard
  ├── Extract token from header
  ├── Query oauth_tokens table
  ├── Verify token exists and not revoked
  ├── Verify token not expired
  ├── Load associated user
  ├── Set auth()->user() for controller
  └── Proceed or reject (401 if invalid)

Controller Action
  ├── auth()->user() available
  ├── auth()->id() available
  ├── Can authorize with policies
  └── Can interact with user's data
```

## Deployment Architecture

### Production Stack

```
User Browser
  ↓ HTTPS
  ↓
Load Balancer (Nginx/HAProxy)
  ├── SSL/TLS termination
  ├── Route to backend servers
  └── Health checks
  ↓
Application Servers (Laravel)
  ├── Stateless API servers (multiple)
  ├── Session store: Redis
  ├── Cache: Redis
  └── File upload: S3 (optional)
  ↓
Database (MySQL/PostgreSQL)
  ├── Primary (read/write)
  ├── Replica (read-only)
  └── Regular backups
  ↓
Redis
  ├── Session storage
  ├── Cache layer
  └── Job queue (optional)
```

### Docker Deployment

```dockerfile
# Dockerfile structure
FROM php:8.4-fpm
RUN apt-get install dependencies
COPY laravel app
RUN composer install --no-dev
EXPOSE 9000
CMD ["php-fpm"]
```

**docker-compose Services:**
- app (Laravel FPM)
- nginx (reverse proxy + static files)
- mysql (database)
- redis (cache + sessions)

## Security Architecture

### Network Security
- HTTPS enforced (HTTP redirects to HTTPS)
- HSTS header (force HTTPS on return visits)
- TLS 1.2+ required
- No sensitive data in logs
- API rate limiting

### Application Security
- CSRF protection (Sanctum tokens)
- SQL injection prevention (Eloquent ORM)
- XSS prevention (Vue 3 auto-escaping, CSP header)
- Authentication: OAuth2 (Laravel Passport)
- Authorization: Policies + middleware
- Input validation: FormRequest on every endpoint

### Data Security
- Passwords hashed (bcrypt + salt)
- OTP secrets encrypted (AES-256-GCM)
- Encryption key never sent to server
- TLS encryption in transit
- Encrypted backups (double encryption)

### Cryptography
- Key derivation: Argon2id (memory-hard, GPU-resistant)
- Encryption: AES-256-GCM (authenticated)
- Authentication: HMAC-based (in OWASP suite)
- Random: Web Crypto API (cryptographically secure)

## Monitoring & Observability

### Logging
```
Laravel Logs: /storage/logs/
├── daily.log (application events)
├── error.log (exceptions)
└── queue.log (background jobs)

Access Logs: /var/log/nginx/
├── access.log (all HTTP requests)
└── error.log (Nginx errors)
```

**Never log:**
- OTP secrets (plaintext or encrypted)
- Encryption keys
- User passwords
- API tokens

**Always log:**
- API endpoint, method, status
- User ID (if authenticated)
- Error messages (without sensitive data)
- Performance metrics (response time)

### Metrics to Monitor
- API response time (p50, p95, p99)
- Database query performance
- Cache hit rate
- Authentication success/failure rate
- Exception error rates
- Disk usage (database, logs, backups)
- Memory usage (application, database)

## Performance Optimizations

### Database
- Indexes on foreign keys (user_id, group_id)
- Indexes on frequently filtered columns (service, encrypted)
- Connection pooling
- Read replicas for heavy queries

### API
- Pagination (default 100 items per page)
- Eager loading (with('relationships'))
- Response compression (gzip)
- HTTP caching headers

### Frontend
- Code splitting (route-based)
- Lazy loading (images, components)
- Service Worker caching (app shell)
- IndexedDB for offline data

### Cryptography
- Cache encryption key in memory (don't re-derive)
- Batch encryption operations
- Lazy decryption (decrypt on-demand, not on fetch)

## Disaster Recovery

### Backup Strategy

**Database Backups:**
- Daily full backup (encrypted)
- Hourly incremental backup
- Retention: 30 days
- Off-site storage (S3, Azure, etc)

**User Backups:**
- Encrypted backup export (.vault format)
- Double encryption (master key + backup password)
- User controls backup storage
- Can be imported to any 2FA-Vault instance

**Recovery:**
- Database: Restore from backup to new instance
- User data: User can restore their .vault backup
- No recovery of lost encryption keys (by design)

### High Availability
- Multi-server deployment (stateless)
- Database replication
- Redis cluster (if using Redis)
- Health checks + automatic failover
- Database backups to multiple locations

### Data Loss Prevention
- Regular automated backups
- Backup testing (restore validation)
- Encrypted storage of backups
- Geographically distributed replicas
- User can always export their own backup

## Appendix: Technology Stack Summary

| Component | Technology | Version |
|-----------|-----------|---------|
| Backend Framework | Laravel | 12 |
| Language | PHP | 8.4+ |
| Frontend Framework | Vue | 3 |
| Build Tool | Vite | 7+ |
| State Management | Pinia | 3 |
| Database | MySQL/PostgreSQL/SQLite | Flexible |
| Cache | Redis | 6+ |
| Authentication | Passport (OAuth2) | 12 |
| Encryption | Web Crypto API | Native |
| Key Derivation | Argon2id | via argon2-browser |
| OTP Generation | Spomky-Labs/OTPHP | 11 |
| Testing | PHPUnit | 11 |
| Code Quality | Pint + ESLint | Latest |
