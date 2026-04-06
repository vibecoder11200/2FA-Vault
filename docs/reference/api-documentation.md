# 2FA-Vault API Documentation

**Base URL:** `/api/v1`
**Authentication:** Bearer token (Laravel Passport) or session cookie
**Content-Type:** `application/json` (unless noted otherwise)

All authenticated endpoints require the `Authorization: Bearer {token}` header or an active session cookie.

---

## Table of Contents

- [Authentication](#authentication)
- [User & Preferences](#user--preferences)
- [2FA Accounts](#2fa-accounts)
- [Groups](#groups)
- [QR Codes & Icons](#qr-codes--icons)
- [End-to-End Encryption (E2EE)](#end-to-end-encryption-e2ee)
- [Backups](#backups)
- [Teams](#teams)
- [Push Notifications](#push-notifications)
- [Feature Flags](#feature-flags)
- [Admin: User Management](#admin-user-management)
- [Admin: Settings](#admin-settings)
- [Admin: System](#admin-system)
- [Error Responses](#error-responses)
- [Rate Limiting](#rate-limiting)

---

## Authentication

Authentication is handled via web routes (session-based), not the API prefix.

### Register

```
POST /user
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Display name |
| `email` | string | Yes | Email address |
| `password` | string | Yes | Password (min 8 characters) |
| `password_confirmation` | string | Yes | Must match password |

**Response:** `201 Created`

### Login

```
POST /user/login
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | Yes | Email address |
| `password` | string | Yes | Password |

**Response:** `200 OK` with session cookie and CSRF token

**Rate limit:** 10 requests per minute per IP

### Logout

```
GET /user/logout
```

**Response:** `200 OK`

### WebAuthn (Passwordless)

```
POST /webauthn/login/options     # Get login options
POST /webauthn/login             # Complete login
POST /webauthn/register/options  # Get registration options (authenticated)
POST /webauthn/register          # Complete registration (authenticated)
GET  /webauthn/credentials       # List credentials (authenticated)
PATCH /webauthn/credentials/{id}/name  # Rename credential
DELETE /webauthn/credentials/{id}      # Delete credential
POST /webauthn/lost              # Send recovery email
POST /webauthn/recover           # Recover with recovery token
```

### Password Management

```
POST  /user/password/lost    # Send reset link
POST  /user/password/reset   # Reset with token
PATCH /user/password         # Update password (authenticated)
```

### Personal Access Tokens

```
GET    /oauth/personal-access-tokens           # List tokens
POST   /oauth/personal-access-tokens           # Create token
DELETE /oauth/personal-access-tokens/{token_id} # Revoke token
```

### SSO / Socialite

```
GET /socialite/redirect/{driver}   # Redirect to SSO provider
GET /socialite/callback/{driver}   # SSO callback handler
```

---

## User & Preferences

### Get Current User

```
GET /api/v1/user
```

**Response:** `200 OK`
```json
{
  "id": 1,
  "name": "John Doe",
  "email": "john@example.com",
  "is_admin": false,
  "preferences": { ... }
}
```

### Update User

```
PUT /user
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | No | Display name |
| `email` | string | No | Email address |
| `password` | string | No | New password |

### Delete User

```
DELETE /user
```

Permanently deletes the authenticated user's account and all associated data.

### Get All Preferences

```
GET /api/v1/user/preferences
```

**Response:** `200 OK`
```json
[
  {
    "key": "showOtpAsDot",
    "value": false,
    "locked": false
  },
  {
    "key": "closeOtpOnCopy",
    "value": true,
    "locked": false
  }
]
```

### Get Single Preference

```
GET /api/v1/user/preferences/{preferenceName}
```

**Response:** `200 OK`
```json
{
  "key": "showOtpAsDot",
  "value": false,
  "locked": false
}
```

### Set Preference

```
PUT /api/v1/user/preferences/{preferenceName}
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `value` | mixed | Yes | Preference value (type depends on preference) |

**Response:** `200 OK`

---

## 2FA Accounts

### List Accounts

```
GET /api/v1/twofaccounts
```

**Query parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `ids` | string | Comma-separated list of account IDs to filter |

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "service": "GitHub",
      "account": "user@example.com",
      "icon": "github.png",
      "otp_type": "totp",
      "digits": 6,
      "period": 30,
      "group_id": null,
      "order_column": 0
    }
  ]
}
```

> **Note:** When E2EE is enabled, `secret`, `account`, and `service` fields contain encrypted JSON objects `{ciphertext, iv, authTag}`. Decryption happens client-side.

### Get Single Account

```
GET /api/v1/twofaccounts/{id}
```

**Response:** `200 OK` with full account details

### Create Account

```
POST /api/v1/twofaccounts
```

**Option A - Via URI (QR code scan):**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `uri` | string | Yes | otpauth:// URI |
| `icon` | string | No | Icon filename |

**Option B - Manual entry:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `service` | string | Yes | Service name |
| `account` | string | Yes | Account identifier |
| `otp_type` | string | Yes | `totp` or `hotp` |
| `secret` | string | Yes | Base32 encoded secret (or encrypted JSON if E2EE) |
| `digits` | integer | No | OTP length (default: 6) |
| `period` | integer | No | TOTP period in seconds (default: 30) |
| `counter` | integer | No | HOTP counter (default: 0) |
| `algorithm` | string | No | `sha1`, `sha256`, `sha512` (default: `sha1`) |
| `icon` | string | No | Icon filename |

**Response:** `201 Created`

### Update Account

```
PUT /api/v1/twofaccounts/{id}
```

Same fields as create. **Response:** `200 OK`

### Delete Account

```
DELETE /api/v1/twofaccounts/{id}
```

**Response:** `204 No Content`

### Batch Delete

```
DELETE /api/v1/twofaccounts
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `ids` | array | Yes | Array of account IDs to delete |

### Generate OTP

```
GET  /api/v1/twofaccounts/{id}/otp   # For existing account
POST /api/v1/twofaccounts/otp        # For URI or parameters
```

**Response:** `200 OK`
```json
{
  "password": "123456",
  "otp_type": "totp",
  "generated_at": 1680000000,
  "period": 30
}
```

### Get Account Count

```
GET /api/v1/twofaccounts/count
```

**Response:** `200 OK`
```json
{
  "count": 42
}
```

### Reorder Accounts

```
POST /api/v1/twofaccounts/reorder
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `orderedIds` | array | Yes | Array of account IDs in desired order |

### Withdraw from Group

```
PATCH /api/v1/twofaccounts/withdraw
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `ids` | array | Yes | Array of account IDs to withdraw from their groups |

### Export Accounts

```
GET /api/v1/twofaccounts/export
```

Returns accounts in export format.

### Import/Migrate Accounts

```
POST /api/v1/twofaccounts/migration
```

Import accounts from another authenticator app (Google Authenticator, Aegis, etc.).

### Preview URI

```
POST /api/v1/twofaccounts/preview
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `uri` | string | Yes | otpauth:// URI to preview |

**Response:** `200 OK` with parsed account details (without saving)

---

## Groups

### List Groups

```
GET /api/v1/groups
```

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 0,
      "name": "All",
      "twofaccounts_count": 42
    },
    {
      "id": 1,
      "name": "Work",
      "twofaccounts_count": 15
    }
  ]
}
```

> Group `id: 0` is the virtual "All" pseudo-group, always included first.

### Create Group

```
POST /api/v1/groups
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Group name |

**Response:** `201 Created`

### Get Group

```
GET /api/v1/groups/{id}
```

### Update Group

```
PUT /api/v1/groups/{id}
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | New group name |

### Delete Group

```
DELETE /api/v1/groups/{id}
```

Accounts in the deleted group are moved to the default group.

### List Group Accounts

```
GET /api/v1/groups/{id}/twofaccounts
```

### Assign Accounts to Group

```
POST /api/v1/groups/{id}/assign
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `ids` | array | Yes | Array of account IDs to assign |

### Reorder Groups

```
POST /api/v1/groups/reorder
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `orderedIds` | array | Yes | Array of group IDs in desired order |

---

## QR Codes & Icons

### Get Account QR Code

```
GET /api/v1/twofaccounts/{id}/qrcode
```

**Response:** `200 OK` with QR code image data

### Decode QR Code

```
POST /api/v1/qrcode/decode
```

**Body:** `multipart/form-data`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `qrcode` | file | Yes | Image file containing QR code |

**Response:** `200 OK`
```json
{
  "data": "otpauth://totp/Service:user@example.com?secret=BASE32SECRET&issuer=Service"
}
```

### Upload Icon

```
POST /api/v1/icons
```

**Body:** `multipart/form-data`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `icon` | file | Yes | Icon image file (PNG, SVG, JPG) |

**Response:** `201 Created`
```json
{
  "filename": "abc123.png"
}
```

### Fetch Default Icon

```
POST /api/v1/icons/default
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `service` | string | Yes | Service name to fetch icon for |

### Get Icon Packs

```
GET /api/v1/icons/packs
```

### Delete Icon

```
DELETE /api/v1/icons/{icon}
```

---

## End-to-End Encryption (E2EE)

All E2EE endpoints enforce rate limiting in production.

### Setup Encryption

```
POST /api/v1/encryption/setup
```

**Rate limit:** 3 attempts per minute per IP

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `encryption_salt` | string | Yes | Random salt for key derivation (base64) |
| `encryption_test_value` | string | Yes | Encrypted test data for verification |
| `encryption_version` | integer | Yes | Encryption version (currently `1`) |

**Response:** `200 OK`
```json
{
  "message": "End-to-end encryption enabled successfully",
  "encryption_enabled": true
}
```

**Errors:**
- `400` - Encryption already enabled
- `429` - Rate limit exceeded

> **Security note:** The server never receives the master password or encryption key. Only the salt and an encrypted test value are stored.

### Get Encryption Info

```
GET /api/v1/encryption/info
```

**Response (E2EE enabled):** `200 OK`
```json
{
  "encryption_enabled": true,
  "encryption_salt": "base64-encoded-salt",
  "encryption_test_value": "encrypted-test-data",
  "encryption_version": 1,
  "vault_locked": false
}
```

**Response (E2EE not enabled):**
```json
{
  "encryption_enabled": false
}
```

### Check Encryption Status

```
GET /api/v1/encryption/status
```

**Response:** `200 OK`
```json
{
  "encryption_enabled": true,
  "encryption_version": 1,
  "vault_locked": false,
  "has_backup": true,
  "last_backup_at": "2026-04-01T12:00:00+00:00",
  "should_prompt_setup": false
}
```

### Verify Master Password (Zero-Knowledge)

```
POST /api/v1/encryption/verify
```

**Rate limit:** 5 attempts per minute per IP

The client derives the key locally and decrypts the test value. This endpoint confirms the result.

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `verification_result` | boolean | Yes | `true` if client successfully decrypted test value |

**Response (success):** `200 OK`
```json
{
  "message": "Vault unlocked successfully",
  "vault_locked": false
}
```

**Response (failure):** `401 Unauthorized`
```json
{
  "message": "Verification failed",
  "vault_locked": true
}
```

### Lock Vault

```
POST /api/v1/encryption/lock
```

**Response:** `200 OK`
```json
{
  "message": "Vault locked successfully",
  "vault_locked": true
}
```

### Disable Encryption

```
DELETE /api/v1/encryption/disable
```

**Rate limit:** 2 attempts per hour per IP

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `password` | string | Yes | Account password for verification |
| `confirm` | boolean | Yes | Must be `true` |

**Response:** `200 OK`
```json
{
  "message": "Encryption disabled successfully",
  "encryption_enabled": false
}
```

---

## Backups

### Export Encrypted Backup

```
POST /api/v1/backups/export
```

**Rate limit:** 5 exports per hour per user

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `password` | string | Yes | Backup encryption password (min 8 characters) |

**Response (JSON):** `200 OK`
```json
{
  "filename": "2fa-vault-backup-2026-04-05-120000.vault",
  "size": 4096,
  "accounts_count": 42
}
```

**Response (download):** Streamed `.vault` file download

### Import Backup

```
POST /api/v1/backups/import
```

**Rate limit:** 3 imports per hour per user

**Body:** `multipart/form-data`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `backup_file` | file | Yes | `.vault` or `.json` backup file |
| `password` | string | Yes | Backup decryption password (min 8 characters) |
| `format` | string | No | `vault` or `2fauth` (auto-detected if omitted) |

**Response:** `200 OK`
```json
{
  "imported_count": 40,
  "skipped_count": 2,
  "errors": []
}
```

### Get Backup Metadata

```
POST /api/v1/backups/metadata
```

**Body:** `multipart/form-data`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `backup_file` | file | Yes | Backup file (max 10MB, `.vault` or `.json`) |

Returns metadata about the backup without decrypting it.

### Get Backup Info

```
GET /api/v1/backups/info
```

**Response:** `200 OK`
```json
{
  "has_backup": true,
  "last_backup_at": "2026-04-01T12:00:00+00:00",
  "days_since_backup": 4,
  "should_backup": false
}
```

---

## Teams

### List Teams

```
GET /api/v1/teams
```

**Response:** `200 OK`
```json
[
  {
    "id": 1,
    "name": "Engineering",
    "owner_id": 1,
    "owner_name": "John Doe",
    "role": "owner",
    "members_count": 5,
    "created_at": "2026-03-01T00:00:00Z",
    "invite_code": "abc123"
  }
]
```

> `invite_code` is only visible to the team owner.

### Create Team

```
POST /api/v1/teams
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Team name (max 255 characters) |

**Response:** `201 Created`

**Limits:** Maximum 10 teams per user (configurable via `2fauth.maxTeamsPerUser`).

### Get Team Details

```
GET /api/v1/teams/{id}
```

**Response:** `200 OK`
```json
{
  "id": 1,
  "name": "Engineering",
  "owner_id": 1,
  "owner_name": "John Doe",
  "invite_code": "abc123",
  "members": [
    {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "role": "owner",
      "joined_at": "2026-03-01T00:00:00Z"
    }
  ],
  "created_at": "2026-03-01T00:00:00Z"
}
```

> `invite_code` only visible to users with invite permission.

### Update Team

```
PUT /api/v1/teams/{id}
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | New team name |

**Authorization:** Team owner or admin only.

### Delete Team

```
DELETE /api/v1/teams/{id}
```

**Authorization:** Team owner only.

### Invite Member

```
POST /api/v1/teams/{id}/invite
POST /api/v1/teams/{id}/invitations
```

Both endpoints create an invitation.

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | Yes | Email of the person to invite |
| `role` | string | No | `admin`, `member`, or `viewer` (default: `member`) |

**Response:** `201 Created`
```json
{
  "message": "Invitation sent successfully",
  "invitation": {
    "id": 1,
    "team_id": 1,
    "email": "jane@example.com",
    "role": "member",
    "token": "random-token-string",
    "status": "pending"
  }
}
```

### Accept Invitation

```
POST /api/v1/teams/invitations/{token}/accept
```

**Validation:**
- Token must exist and be in `pending` status
- Authenticated user's email must match invitation email
- Team must not have reached max members (default: 50)

**Response:** `200 OK`

### Join via Invite Code

```
POST /api/v1/teams/join
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `invite_code` | string | Yes | Team invite code |

Joins the team with `member` role. **Limits:** Max 50 members per team (configurable via `2fauth.maxMembersPerTeam`).

### Leave Team

```
POST /api/v1/teams/{id}/leave
```

Team owners cannot leave. They must transfer ownership or delete the team.

### Remove Member

```
DELETE /api/v1/teams/{id}/members/{userId}
```

**Authorization:** Requires `removeMember` permission. Cannot remove the team owner.

### Update Member Role

```
PUT /api/v1/teams/{id}/members/{userId}/role
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `role` | string | Yes | `admin`, `member`, or `viewer` |

**Authorization:** Requires `updateRole` permission. Cannot change the owner's role.

### Team Roles

| Role | Permissions |
|------|-------------|
| `owner` | Full control, delete team, manage all members |
| `admin` | Invite members, remove members, update roles |
| `member` | View and use shared accounts |
| `viewer` | View shared accounts only (read-only) |

---

## Push Notifications

### Subscribe

```
POST /api/v1/push/subscribe
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `endpoint` | string | Yes | Push service URL |
| `p256dh` | string | Yes | Public key for encryption |
| `auth` | string | Yes | Auth secret |
| `content_encoding` | string | No | Encoding type (default: `aesgcm`) |

**Response:** `201 Created`
```json
{
  "id": 1,
  "endpoint": "https://fcm.googleapis.com/...",
  "created_at": "2026-04-05T12:00:00Z"
}
```

### Unsubscribe

```
DELETE /api/v1/push/unsubscribe
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `endpoint` | string | Yes | Push service URL to unsubscribe |

### List Subscriptions

```
GET /api/v1/push/subscriptions
```

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "endpoint": "https://fcm.googleapis.com/...",
      "created_at": "2026-04-05T12:00:00Z"
    }
  ]
}
```

---

## Feature Flags

### List All Features

```
GET /api/v1/features
```

### Get Feature Status

```
GET /api/v1/features/{feature}
```

---

## Admin: User Management

All admin endpoints require the `admin` middleware. Requests from non-admin users return `403 Forbidden`.

### List Users (Admin API)

```
GET /api/v1/admin/users
```

**Query parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Filter: `active` or `inactive` |
| `role` | string | Filter: `admin` or `user` |
| `per_page` | integer | Items per page (default: 20) |

**Response:** `200 OK` (paginated)

### Get User Details (Admin API)

```
GET /api/v1/admin/users/{id}
```

Includes relationship counts: `teams_count`, `owned_teams_count`, `twofaccounts_count`, `groups_count`.

### Update User (Admin API)

```
PUT /api/v1/admin/users/{id}
```

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | No | User name |
| `email` | string | No | Email (must be unique) |
| `is_admin` | boolean | No | Admin status |
| `is_active` | boolean | No | Active status |

Cannot demote the last administrator.

### Deactivate User (Admin API)

```
DELETE /api/v1/admin/users/{id}
```

Soft-deactivates the user (sets `is_active = false`). Cannot deactivate self or the last administrator.

### Legacy Admin Endpoints

```
GET    /api/v1/users              # List users (paginated)
GET    /api/v1/users/{id}         # Show user
POST   /api/v1/users              # Create user
DELETE /api/v1/users/{id}         # Delete user
GET    /api/v1/users/{id}/authentications    # User auth logs
PATCH  /api/v1/users/{id}/password/reset     # Reset password
PATCH  /api/v1/users/{id}/promote            # Toggle admin
DELETE /api/v1/users/{id}/pats               # Revoke all PATs
DELETE /api/v1/users/{id}/credentials        # Revoke WebAuthn
```

---

## Admin: Settings

All settings endpoints require the `admin` middleware.

### List All Settings

```
GET /api/v1/settings
```

### Get Setting

```
GET /api/v1/settings/{settingName}
```

### Create Setting

```
POST /api/v1/settings
```

### Update Setting

```
PUT /api/v1/settings/{settingName}
```

### Delete Setting

```
DELETE /api/v1/settings/{settingName}
```

---

## Admin: System

Available via web routes, requires admin authentication.

```
GET  /system/infos          # System information (PHP, Laravel, DB versions)
POST /system/test-email     # Send test email
GET  /system/latestRelease  # Check for latest release
GET  /system/optimize       # Optimize application
GET  /system/clear-cache    # Clear application cache
```

---

## Error Responses

All errors follow a consistent JSON format:

```json
{
  "message": "Human-readable error description",
  "errors": {
    "field_name": ["Validation error message"]
  }
}
```

### HTTP Status Codes

| Code | Meaning |
|------|---------|
| `200` | Success |
| `201` | Created |
| `204` | No Content (successful deletion) |
| `400` | Bad Request (invalid operation) |
| `401` | Unauthorized (not authenticated or verification failed) |
| `403` | Forbidden (insufficient permissions) |
| `404` | Not Found |
| `422` | Validation Error (with field-level errors) |
| `429` | Too Many Requests (rate limited) |
| `500` | Internal Server Error |

---

## Rate Limiting

Rate limits are applied per-endpoint in production. They are disabled in the `testing` environment.

| Endpoint | Limit | Window |
|----------|-------|--------|
| `POST /user/login` | 10 | 1 minute |
| `POST /webauthn/recover` | 10 | 1 minute |
| `POST /encryption/setup` | 3 | 1 minute |
| `POST /encryption/verify` | 5 | 1 minute |
| `DELETE /encryption/disable` | 2 | 1 hour |
| `POST /backups/export` | 5 | 1 hour |
| `POST /backups/import` | 3 | 1 hour |

Rate limit responses include:
```json
{
  "message": "Too many attempts. Please try again in {seconds} seconds."
}
```

---

## Encryption Data Format

When E2EE is enabled, sensitive fields are stored as encrypted JSON:

```json
{
  "ciphertext": "base64-encoded-encrypted-data",
  "iv": "base64-encoded-initialization-vector",
  "authTag": "base64-encoded-authentication-tag"
}
```

**Key derivation:** Argon2id (client-side) from master password + salt
**Encryption algorithm:** AES-256-GCM
**The server never has access to plaintext secrets.**

---

## Legacy Endpoints

The following legacy backup routes are maintained for backward compatibility and will be removed in a future version:

```
GET  /api/v1/backup/export     -> Use POST /api/v1/backups/export
POST /api/v1/backup/import     -> Use POST /api/v1/backups/import
POST /api/v1/backup/metadata   -> Use POST /api/v1/backups/metadata
GET  /api/v1/backup/info       -> Use GET  /api/v1/backups/info
```

The deprecated user name endpoint returns a deprecation header:
```
GET /api/v1/user/name          -> Use GET /api/v1/user
```
