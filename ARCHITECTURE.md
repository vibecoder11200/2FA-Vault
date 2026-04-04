# 2FAuth Architecture Documentation

## End-to-End Encryption (E2EE) - Zero-Knowledge Architecture

### Overview

2FAuth implements **zero-knowledge end-to-end encryption** to ensure that OTP secrets are never exposed to the server in plaintext. All encryption and decryption happens client-side in the user's browser.

### Security Principles

1. **Server NEVER sees plaintext secrets** - All OTP secrets are encrypted in the browser before transmission
2. **Server NEVER has access to encryption keys** - Keys are derived from the master password client-side only
3. **Master password NEVER sent to server** - Password verification happens using encrypted test values
4. **Zero-knowledge architecture** - The server cannot decrypt user data, even if compromised

### Architecture Components

#### Client-Side (Vue 3)

**Crypto Module** (`resources/js/services/crypto.js`)
- Key derivation using Argon2id
- AES-256-GCM encryption/decryption using Web Crypto API
- Salt generation
- Account encryption/decryption helpers

**Crypto Store** (`resources/js/stores/crypto.js`)
- Pinia store for encryption state management
- Manages encryption key in memory (session-based)
- Vault locking/unlocking
- Account encryption/decryption workflows

**UI Components**
- `SetupEncryption.vue` - First-time encryption setup wizard
- `UnlockVault.vue` - Vault unlock screen (shown on each session)
- Account forms - Handle encryption before server submission

#### Server-Side (Laravel 12)

**Database Schema**

Users table:
```sql
encryption_salt          VARCHAR(255)  -- Salt for Argon2id key derivation
encryption_test_value    TEXT          -- Encrypted test value for verification
encryption_version       TINYINT       -- Encryption version (for future compatibility)
vault_locked             BOOLEAN       -- Vault lock status (session-based)
```

TwoFAccounts table:
```sql
encrypted                BOOLEAN       -- Flag indicating if secret is encrypted
secret                   TEXT          -- Stores encrypted data as JSON: {ciphertext, iv, authTag}
```

**EncryptionController** (`app/Http/Controllers/EncryptionController.php`)

Endpoints:
- `POST /api/v1/encryption/setup` - Setup E2EE (stores salt + test value)
- `GET /api/v1/encryption/info` - Get encryption info (salt + test value)
- `POST /api/v1/encryption/verify` - Verify password (zero-knowledge)
- `POST /api/v1/encryption/lock` - Lock vault
- `DELETE /api/v1/encryption/disable` - Disable E2EE (with password confirmation)

All endpoints are protected by authentication and rate limiting.

### E2EE Flow Diagrams

#### First-Time Setup Flow

```
User                Browser (Vue)                     Server (Laravel)
 |                       |                                  |
 |-- Enter master pwd --▶|                                  |
 |                       |-- Generate salt ----------------▶|
 |                       |-- Derive key (Argon2id) ---------|
 |                       |-- Encrypt test value ------------|
 |                       |-- POST /encryption/setup -------▶|
 |                       |   {salt, test_value, version}    |
 |                       |                                  |-- Store salt + test_value
 |                       |◀-- Success ----------------------|
 |◀-- Encryption enabled |                                  |
```

**Important:** 
- Master password NEVER leaves the browser
- Server only receives salt (needed for key derivation) and encrypted test value
- Server NEVER receives the encryption key

#### Vault Unlock Flow (Each Session)

```
User                Browser (Vue)                     Server (Laravel)
 |                       |                                  |
 |                       |-- GET /encryption/info ---------▶|
 |                       |◀-- {salt, test_value} -----------|
 |                       |                                  |
 |-- Enter master pwd --▶|                                  |
 |                       |-- Derive key (Argon2id) ---------|
 |                       |   from password + salt           |
 |                       |-- Decrypt test_value ------------|
 |                       |-- Verify === "TEST_VALUE" ------|
 |                       |                                  |
 |                       |-- POST /encryption/verify ------▶|
 |                       |   {verification_result: true}    |
 |                       |                                  |-- Set vault_locked = false
 |                       |◀-- Success ----------------------|
 |◀-- Vault unlocked ---|                                  |
 |                       |-- Store key in memory -----------|
```

**Important:**
- Password verification happens client-side
- Server only confirms the verification result
- Encryption key stored in browser memory (not localStorage)
- Key is lost when user closes browser/logs out

#### Account Creation Flow (with E2EE)

```
User                Browser (Vue)                     Server (Laravel)
 |                       |                                  |
 |-- Create account ----▶|                                  |
 |   {secret: "ABC123"}  |                                  |
 |                       |-- Encrypt secret ----------------|
 |                       |   using stored key               |
 |                       |   Result: {ciphertext, iv, tag}  |
 |                       |                                  |
 |                       |-- POST /twofaccounts -----------▶|
 |                       |   {secret: JSON.stringify(...)}  |
 |                       |   {encrypted: true}              |
 |                       |                                  |-- Store encrypted secret
 |                       |◀-- Success ----------------------|
 |◀-- Account created ---|                                  |
```

**Important:**
- Secret is encrypted BEFORE sending to server
- Server stores encrypted secret as-is
- Server CANNOT decrypt the secret

#### Account Retrieval Flow (with E2EE)

```
User                Browser (Vue)                     Server (Laravel)
 |                       |                                  |
 |-- View accounts -----▶|                                  |
 |                       |-- GET /twofaccounts ------------▶|
 |                       |◀-- [{secret: "{...}", encrypted}]|
 |                       |                                  |
 |                       |-- Decrypt all accounts ----------|
 |                       |   using stored key               |
 |                       |   Parse JSON and decrypt         |
 |                       |                                  |
 |◀-- Show accounts ----|                                  |
 |   {secret: "ABC123"}  |                                  |
```

**Important:**
- Server returns encrypted secrets
- Decryption happens in browser
- If vault is locked, user must unlock first

### Cryptographic Specification

#### Key Derivation (Argon2id)
```javascript
{
    time: 3,           // Number of iterations
    mem: 65536,        // Memory cost: 64 MB
    hashLen: 32,       // Hash length: 32 bytes (256 bits)
    parallelism: 1,    // Parallelism factor
    type: Argon2id     // Algorithm: Argon2id (recommended)
}
```

**Why Argon2id?**
- Winner of Password Hashing Competition (PHC)
- Resistant to GPU/ASIC attacks
- Protects against side-channel attacks
- Memory-hard function

#### Encryption (AES-256-GCM)
```javascript
{
    algorithm: 'AES-GCM',
    keyLength: 256,      // 256-bit key
    ivLength: 12,        // 12-byte IV (96 bits)
    tagLength: 128       // 128-bit auth tag
}
```

**Why AES-GCM?**
- AEAD (Authenticated Encryption with Associated Data)
- Provides both confidentiality and authenticity
- Resistant to tampering
- Industry standard

#### Encrypted Data Format
```json
{
    "ciphertext": "base64_encoded_ciphertext",
    "iv": "base64_encoded_initialization_vector",
    "authTag": "base64_encoded_authentication_tag"
}
```

Stored in database as JSON string in the `secret` field.

### Security Considerations

#### ✅ Strengths

1. **Zero-knowledge** - Server cannot access user secrets
2. **Client-side encryption** - All crypto happens in browser
3. **Strong cryptography** - Argon2id + AES-256-GCM
4. **No key storage** - Keys exist only in memory
5. **Forward secrecy** - Past sessions unaffected by current compromise
6. **Rate limiting** - Protection against brute-force attacks
7. **CSRF protection** - All endpoints protected
8. **Audit logging** - Encryption events are logged

#### ⚠️ Important Limitations

1. **Password recovery impossible** - If user forgets master password, data is lost forever
2. **Browser security critical** - Compromised browser = compromised secrets
3. **XSS vulnerabilities** - Could expose keys in memory
4. **Requires JavaScript** - Cannot work without JS enabled
5. **Performance impact** - Encryption/decryption adds overhead
6. **Backward compatibility** - Non-encrypted accounts still work

#### 🔒 Best Practices

**For Users:**
- Use a strong, unique master password
- Store master password in a password manager
- Keep browser updated
- Use HTTPS only
- Enable 2FA on the account itself

**For Developers:**
- Never log plaintext secrets
- Never send keys over network
- Validate all inputs
- Use CSP headers
- Regular security audits
- Keep crypto libraries updated

### Backward Compatibility

2FAuth supports mixed mode:
- Users without E2EE continue to work normally
- Secrets stored without encryption are handled separately
- Users can enable E2EE at any time
- Migration tools planned for future versions

**Detection:**
```javascript
if (account.encrypted) {
    // E2EE enabled - decrypt client-side
    account = await cryptoStore.decryptAccount(account)
} else {
    // Legacy account - secret is plaintext
    // No decryption needed
}
```

### Testing

**Unit Tests** (`tests/Unit/Services/CryptoTest.php`)
- Verify server never stores plaintext
- Verify server never receives keys
- Verify correct model fields

**Feature Tests** (`tests/Feature/EncryptionControllerTest.php`)
- Encryption setup flow
- Vault locking/unlocking
- Password verification
- Rate limiting
- Authentication requirements

### Future Enhancements

1. **Key rotation** - Support for changing master password
2. **Recovery codes** - Backup codes for account recovery
3. **Hardware key support** - WebAuthn for master password
4. **Shared accounts** - Key sharing for team accounts
5. **Audit trail** - Detailed encryption event logs
6. **Migration tools** - Bulk encryption of existing accounts
7. **Mobile apps** - Native iOS/Android with E2EE

### API Reference

#### POST /api/v1/encryption/setup
Setup E2EE for authenticated user.

**Request:**
```json
{
    "encryption_salt": "base64_encoded_salt",
    "encryption_test_value": "{\"ciphertext\":\"...\",\"iv\":\"...\",\"authTag\":\"...\"}",
    "encryption_version": 1
}
```

**Response:**
```json
{
    "message": "End-to-end encryption enabled successfully",
    "encryption_enabled": true
}
```

**Rate Limit:** 3 requests per minute per IP

---

#### GET /api/v1/encryption/info
Get encryption info for authenticated user.

**Response (encryption enabled):**
```json
{
    "encryption_enabled": true,
    "encryption_salt": "base64_encoded_salt",
    "encryption_test_value": "{...}",
    "encryption_version": 1,
    "vault_locked": false
}
```

**Response (encryption not enabled):**
```json
{
    "encryption_enabled": false
}
```

---

#### POST /api/v1/encryption/verify
Verify master password (zero-knowledge).

**Request:**
```json
{
    "verification_result": true
}
```

**Response:**
```json
{
    "message": "Vault unlocked successfully",
    "vault_locked": false
}
```

**Rate Limit:** 5 requests per minute per IP

---

#### POST /api/v1/encryption/lock
Lock the vault.

**Response:**
```json
{
    "message": "Vault locked successfully",
    "vault_locked": true
}
```

---

#### DELETE /api/v1/encryption/disable
Disable E2EE (requires password confirmation).

**Request:**
```json
{
    "password": "user_account_password",
    "confirm": true
}
```

**Response:**
```json
{
    "message": "Encryption disabled successfully",
    "encryption_enabled": false
}
```

**Rate Limit:** 2 requests per hour per IP

---

### Glossary

- **E2EE**: End-to-End Encryption
- **Zero-Knowledge**: Server has no knowledge of plaintext data
- **Argon2id**: Password-based key derivation function
- **AES-GCM**: Authenticated Encryption with Associated Data
- **Salt**: Random data used in key derivation
- **IV**: Initialization Vector (random data for encryption)
- **Auth Tag**: Authentication tag (ensures data integrity)
- **Master Password**: User's password for encryption key derivation
- **Vault**: User's encrypted OTP account collection

### References

- [OWASP Cryptographic Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html)
- [Web Crypto API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Crypto_API)
- [Argon2 Specification](https://github.com/P-H-C/phc-winner-argon2/blob/master/argon2-specs.pdf)
- [NIST SP 800-38D (GCM)](https://nvlpubs.nist.gov/nistpubs/Legacy/SP/nistspecialpublication800-38d.pdf)
