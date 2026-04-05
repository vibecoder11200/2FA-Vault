# Codebase Summary

A quick reference guide to navigate the 2FA-Vault codebase structure.

## Directory Structure

```
2FA-Vault/
├── app/                          # Backend application code
│   ├── Api/v1/                   # REST API implementation
│   │   ├── Controllers/          # API endpoint handlers
│   │   ├── Requests/             # Request validation (FormRequest)
│   │   └── Resources/            # JSON response transformers
│   ├── Http/
│   │   ├── Controllers/          # Web controllers (auth, backup, etc.)
│   │   ├── Middleware/           # Request/response middleware
│   │   └── Requests/             # Web form validation
│   ├── Models/                   # Eloquent ORM models
│   ├── Services/                 # Business logic layer
│   ├── Console/
│   │   ├── Commands/             # Artisan CLI commands
│   │   └── Kernel.php            # Console scheduling
│   ├── Events/                   # Laravel events
│   ├── Exceptions/               # Custom exceptions
│   ├── Factories/                # Factory classes
│   ├── Facades/                  # Service facades
│   ├── Extensions/               # Extended Laravel classes
│   └── Helpers/                  # Utility functions
│
├── resources/                    # Frontend assets
│   ├── js/
│   │   ├── app.js                # Vite entry point
│   │   ├── App.vue               # Root Vue component
│   │   ├── components/           # Reusable Vue components (auto-imported)
│   │   ├── composables/          # Vue 3 composables (auto-imported)
│   │   ├── services/             # API clients & crypto functions
│   │   ├── stores/               # Pinia state management (auto-imported)
│   │   ├── views/                # Page/route components
│   │   ├── router/               # Vue Router configuration
│   │   ├── layouts/              # Page layout components
│   │   └── helpers.js            # Frontend utility functions
│   ├── css/                      # Global stylesheets
│   ├── lang/                     # i18n translation files
│   └── views/                    # Blade templates (API views)
│
├── routes/
│   ├── api/
│   │   └── v1.php                # REST API routes (/api/v1/*)
│   ├── auth.php                  # Authentication routes
│   ├── web.php                   # Web routes (SPA routing to index)
│   └── console.php               # Console routes
│
├── database/
│   ├── migrations/               # Database schema migrations
│   ├── factories/                # Model factories for testing
│   ├── seeders/                  # Database seeders
│   └── schema.sql                # Current database schema
│
├── tests/
│   ├── Unit/                     # Unit tests (no database)
│   ├── Feature/                  # Feature tests (with database)
│   ├── Api/v1/                   # API endpoint tests
│   ├── CreatesApplication.php    # Test setup trait
│   └── TestCase.php              # Base test class
│
├── config/                       # Laravel configuration files
├── storage/                      # Runtime storage (logs, cache, sessions)
├── public/                       # Web-accessible files (index.php, assets)
├── browser-extension/            # Chrome/Firefox extension code
├── scripts/                      # Development and deployment scripts
├── docker/                       # Docker configuration
└── docs/                         # Documentation (you are here)
```

## Key Files

### Backend Configuration
| File | Purpose |
|------|---------|
| `app/Models/User.php` | User model with E2EE fields |
| `app/Models/TwoFAccount.php` | TOTP account model (encrypted) |
| `app/Models/Group.php` | Account grouping model |
| `app/Models/Team.php` | Multi-user team model |
| `config/auth.php` | Authentication configuration |
| `config/database.php` | Database connection config |

### Frontend Entry Points
| File | Purpose |
|------|---------|
| `resources/js/app.js` | Vite entry point, Vue app initialization |
| `resources/js/App.vue` | Root Vue component with layout |
| `resources/js/router/index.js` | Vue Router configuration, route guards |
| `resources/js/stores/crypto.js` | Encryption state management |
| `resources/js/services/crypto.js` | Cryptographic operations |

### Core API Endpoints
| Controller | Routes | Purpose |
|-----------|--------|---------|
| `TwoFAccountController` | `/api/v1/twofaccounts/*` | TOTP account CRUD |
| `GroupController` | `/api/v1/groups/*` | Account grouping |
| `UserController` | `/api/v1/user/*` | User profile |
| `SettingController` | `/api/v1/settings/*` | User preferences |
| `QrCodeController` | `/api/v1/qrcode/*` | QR code scanning |
| `IconController` | `/api/v1/icons/*` | Icon management |

## Service Layer

All business logic is in `app/Services/`:

| Service | Responsibility |
|---------|-----------------|
| `TwoFAccountService` | TOTP/HOTP account management, OTP generation |
| `GroupService` | Account grouping and organization |
| `BackupService` | Encrypted backup export/import |
| `IconService` | Icon fetching and storage |
| `QrCodeService` | QR code scanning and decoding |
| `SettingService` | User settings management |
| `AuthenticationService` | Authentication logic |

## Store Layer (Pinia)

All state management is in `resources/js/stores/`:

| Store | State |
|-------|-------|
| `crypto.js` | Encryption key, vault lock status, encryption test value |
| `twofaccounts.js` | TOTP account list, active account, OTP codes |
| `groups.js` | Account groups, selected group |
| `user.js` | User profile, preferences, settings |
| `teams.js` | Team list, team members, invitations |
| `backup.js` | Backup/restore state, file format |
| `appSettings.js` | Global app configuration |
| `pwa.js` | PWA installation state, offline capability |
| `bus.js` | Event bus for component communication |

## Authentication Flow

```
User Login (HTTP)
  ↓
Auth\LoginController → authService.login()
  ↓
Laravel Passport (OAuth2 token)
  ↓
API requests sent with Authorization: Bearer token
  ↓
auth:api-guard middleware validates token
  ↓
Request proceeds to controller
```

## Encryption Flow

```
User enters master password
  ↓
crypto.js: Argon2id derives key from password + salt
  ↓
crypto.js: Decrypt test value to verify password
  ↓
cryptoStore: Store key in memory (Pinia, cleared on reload)
  ↓
When creating account:
  - Encrypt secret with key → {ciphertext, iv, authTag}
  - Send JSON to server
  ↓
Server stores encrypted JSON in database
  ↓
When fetching account:
  - Client receives encrypted JSON
  - Decrypt with in-memory key
  - Display plaintext secret to user
```

## Request-Response Pattern

### API Request Flow
1. Frontend calls `apiService.post('/api/v1/path', data)`
2. Request includes Bearer token from authentication
3. Middleware validates token → authenticates user
4. Controller validates input with FormRequest
5. Service handles business logic
6. Response transformed by Resource class
7. JSON returned to frontend

### Validation Pattern
- **Frontend:** Vue form components with client-side validation (optional UX)
- **Backend:** FormRequest classes enforce server-side validation (required)
- **Error Response:** 422 status with validation errors in `errors` field

## Data Encryption at Rest

```
User secret (plaintext)
  ↓
Client-side Argon2id key derivation
  ↓
AES-256-GCM encryption
  ↓
{
  "ciphertext": "base64-encoded-ciphertext",
  "iv": "base64-encoded-initialization-vector",
  "authTag": "base64-encoded-authentication-tag"
}
  ↓
Stored as JSON string in database.twofaccounts.secret
```

**Critical:** Server never stores or handles plaintext secrets. All encryption/decryption is client-side only.

## Test Organization

```
tests/
├── Unit/
│   ├── Services/              # Service class tests
│   ├── Models/                # Model tests
│   └── ...
├── Feature/
│   ├── Http/
│   │   ├── Auth/             # Authentication feature tests
│   │   └── Controllers/       # Web controller tests
│   ├── Services/             # Service integration tests
│   └── ...
├── Api/v1/
│   ├── TwoFAccountControllerTest.php
│   ├── GroupControllerTest.php
│   └── ...
└── CreatesApplication.php    # Test setup
```

## Database Schema

### Core Tables
- `users` - User accounts with E2EE fields
- `twofaccounts` - TOTP/HOTP accounts (secret is encrypted)
- `groups` - Account groupings
- `icons` - Custom icons
- `teams` - Multi-user teams
- `team_members` - Team membership with roles
- `team_invitations` - Pending invitations

### Supporting Tables
- `oauth_*` - Laravel Passport OAuth2 tables
- `personal_access_tokens` - API tokens
- `settings` - User preferences
- `webauthn_credentials` - WebAuthn credentials
- `backups` - Backup metadata

## Environment Configuration

Key `.env` variables:
- `APP_NAME`, `APP_URL`, `APP_ENV` - Application identity
- `DB_*` - Database connection
- `CACHE_DRIVER`, `SESSION_DRIVER` - State storage
- `E2EE_*` - Encryption configuration
- `VAPID_*` - PWA push notification keys
- `RATE_LIMIT_*` - Rate limiting thresholds

See `.env.example` for all available variables.
