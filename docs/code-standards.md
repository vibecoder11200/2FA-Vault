# Code Standards & Conventions

Standards and patterns used throughout 2FA-Vault.

## Backend Standards (PHP/Laravel)

### Code Structure

#### Service Layer Pattern
All business logic must live in services (`app/Services/`), NOT in controllers:

```php
// ❌ DON'T: Logic in controller
public function store(Request $request) {
    $account = TwoFAccount::create($request->validated());
    if ($account->encrypted) {
        // Complex encryption logic here...
    }
    return response()->json($account, 201);
}

// ✅ DO: Delegate to service
public function store(Request $request) {
    $account = $this->twofaccountService->create($request->validated());
    return response()->json($account, 201);
}
```

#### Controller Responsibilities
- Validate request (via FormRequest)
- Authorize user (via policy)
- Delegate to service
- Transform response (via Resource)
- Return HTTP response

#### Service Method Organization
```php
class TwoFAccountService
{
    public function create(array $data): TwoFAccount { }
    public function update(TwoFAccount $account, array $data): TwoFAccount { }
    public function delete(TwoFAccount $account): bool { }
    public function getAllWithRelations(): Collection { }
    public function generateOtp(TwoFAccount $account): string { }
}
```

### Encryption Handling

**Critical:** Server never decrypts secrets. Always check the `encrypted` flag:

```php
// ✅ Correct: Check flag, store/retrieve as-is
if ($account->encrypted) {
    // Secret is JSON: {ciphertext, iv, authTag}
    // Just retrieve and return to client
    $encryptedData = $account->secret; // Store or return
} else {
    // Legacy non-encrypted account
    $plainSecret = $account->secret;
}

// ❌ NEVER: Try to decrypt server-side
$decrypted = decrypt($account->secret); // WRONG!
```

### Database Queries

#### Eager Loading (Prevent N+1)
```php
// ✅ DO: Eager load relationships
$accounts = TwoFAccount::with('group', 'icon')->get();

// ❌ DON'T: Lazy load in loop (N+1)
foreach ($accounts as $account) {
    echo $account->group->name; // Query for each!
}
```

#### Eloquent Best Practices
```php
// ✅ DO: Use query builder for complex queries
TwoFAccount::where('user_id', $userId)
    ->where('encrypted', true)
    ->with('group')
    ->orderBy('service')
    ->get();

// ✅ DO: Use scopes for reusable queries
TwoFAccount::encrypted()->forUser($userId)->get();
```

### API Response Format

```php
// Success (200)
return response()->json($resource, 200);

// Created (201)
return response()->json($resource, 201);

// No Content (204)
return response()->noContent();

// Validation Error (422) - automatic via FormRequest
// Error Response (4xx/5xx)
return response()->json(['message' => 'Description'], 422);
```

### Form Request Validation

```php
class TwoFAccountStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'service' => 'required|string|max:255',
            'account' => 'required|string|max:255',
            'secret' => 'required|string', // Can be plaintext or JSON
            'encrypted' => 'boolean',
        ];
    }

    public function authorize(): bool
    {
        return auth()->check();
    }
}
```

### Logging Standards

```php
// ✅ DO: Log non-sensitive info
Log::info('Account created', ['id' => $account->id, 'service' => $account->service]);

// ❌ NEVER: Log secrets
Log::info('Secret: ' . $account->secret); // WRONG!

// ✅ DO: Log encryption status
Log::info('Account encrypted', ['encrypted' => $account->encrypted]);
```

### Type Hints & Return Types

```php
// ✅ Always use type hints
public function create(array $data): TwoFAccount
{
    // ...
}

public function getEncrypted(): Collection
{
    // ...
}

// ✅ Use nullable when appropriate
public function findOrNull(int $id): ?TwoFAccount
{
    // ...
}
```

### Exception Handling

```php
// ✅ Custom exceptions for domain logic
throw new InvalidSecretException('Secret format invalid');

// ✅ Validation exceptions automatically handled
// Form validation throws ValidationException → 422 response

// ✅ Authorization failures automatically handled
// $this->authorize() → 403 response
```

## Frontend Standards (Vue 3/TypeScript)

### Component Architecture

#### Composition API Only
All Vue components use `<script setup>`:

```vue
<script setup>
// Auto-imported: ref, computed, onMounted, reactive, etc.
const accounts = ref([])
const loading = ref(false)
const filtered = computed(() => 
  accounts.value.filter(a => a.service.includes(search.value))
)

onMounted(async () => {
  await fetchAccounts()
})

async function fetchAccounts() {
  loading.value = true
  try {
    accounts.value = await twofaccountService.getAll()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div v-if="loading" class="spinner" />
  <div v-else class="accounts">
    <div v-for="account in filtered" :key="account.id">
      {{ account.service }}
    </div>
  </div>
</template>
```

#### Component Organization
- **One component per file** (named like component)
- **Template first**, then script, then styles
- **Logical grouping** in `components/`, `views/`, `layouts/`
- **No export default** (use `<script setup>`)

### Encryption in Components

#### Before API Calls (Encryption)
```javascript
import { encryptSecret } from '@/services/crypto'
import { useCryptoStore } from '@/stores/crypto'

const cryptoStore = useCryptoStore()

async function createAccount(accountData) {
  if (cryptoStore.isVaultUnlocked && accountData.secret) {
    // Encrypt secret before sending
    const encrypted = await encryptSecret(
      accountData.secret, 
      cryptoStore.encryptionKey
    )
    accountData.secret = JSON.stringify(encrypted)
    accountData.encrypted = true
  }
  
  await twofaccountService.create(accountData)
}
```

#### After API Calls (Decryption)
```javascript
import { decryptSecret } from '@/services/crypto'
import { useCryptoStore } from '@/stores/crypto'

const cryptoStore = useCryptoStore()

async function loadAccounts() {
  const accounts = await twofaccountService.getAll()
  
  // Decrypt each encrypted account
  for (const account of accounts) {
    if (account.encrypted && cryptoStore.isVaultUnlocked) {
      const encryptedData = JSON.parse(account.secret)
      account.secret = await decryptSecret(
        encryptedData,
        cryptoStore.encryptionKey
      )
    }
  }
  
  return accounts
}
```

### Store Pattern (Pinia)

```typescript
export const useTwoFAccountStore = defineStore('twofaccounts', {
  state: () => ({
    accounts: [] as TwoFAccount[],
    loading: false,
    error: null as string | null,
  }),

  getters: {
    encryptedAccounts: (state) => 
      state.accounts.filter(a => a.encrypted),
    
    countByService: (state) => 
      state.accounts.reduce((acc, a) => {
        acc[a.service] = (acc[a.service] || 0) + 1
        return acc
      }, {} as Record<string, number>),
  },

  actions: {
    async fetchAccounts() {
      this.loading = true
      this.error = null
      try {
        this.accounts = await twofaccountService.getAll()
      } catch (e) {
        this.error = e.message
      } finally {
        this.loading = false
      }
    },

    async addAccount(data: TwoFAccount) {
      const created = await twofaccountService.create(data)
      this.accounts.push(created)
    },

    removeAccount(id: number) {
      this.accounts = this.accounts.filter(a => a.id !== id)
    },
  },
})
```

### Auto-Import Convention

These are auto-imported (no explicit `import` needed):
- Vue 3: `ref`, `computed`, `onMounted`, `watch`, `reactive`, etc.
- Vue Router: `useRoute`, `useRouter`
- Pinia: `defineStore`, `storeToRefs`
- All composables in `resources/js/composables/`
- All stores in `resources/js/stores/`
- All components in `resources/js/components/`

**Avoid explicit imports of auto-imported items:**

```vue
<script setup>
// ❌ DON'T: Explicit import of auto-imported
import { ref, computed } from 'vue'
import { useTwoFAccountStore } from '@/stores/twofaccounts'

// ✅ DO: Use auto-imported directly
const count = ref(0)
const store = useTwoFAccountStore()
</script>
```

### TypeScript Usage

Use TypeScript in services and composables for type safety:

```typescript
// services/crypto.ts
export interface EncryptedData {
  ciphertext: string
  iv: string
  authTag: string
}

export interface SecretData {
  secret: string
  encrypted: boolean
}

export async function encryptSecret(
  plaintext: string,
  key: CryptoKey
): Promise<EncryptedData> {
  // Implementation
}

export async function decryptSecret(
  encrypted: EncryptedData,
  key: CryptoKey
): Promise<string> {
  // Implementation
}
```

### Error Handling

```vue
<script setup>
const error = ref<string | null>(null)

async function loadData() {
  try {
    // Call service
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Unknown error'
    // Optionally notify user
    notify.error(error.value)
  }
}
</script>

<template>
  <div v-if="error" class="alert alert-error">
    {{ error }}
  </div>
</template>
```

## Testing Standards

### Test File Organization

```
tests/
├── Unit/Services/TwoFAccountServiceTest.php
├── Feature/Http/Controllers/TwoFAccountControllerTest.php
└── Api/v1/TwoFAccountControllerTest.php
```

### Test Method Naming

```php
// ✅ Descriptive names
public function test_user_can_create_encrypted_account() { }
public function test_user_cannot_access_other_users_accounts() { }
public function test_account_generation_with_invalid_secret() { }

// ❌ Avoid
public function test_create() { }
public function test_access() { }
```

### Test Pattern

```php
public function test_user_can_create_encrypted_account()
{
    // Arrange
    $user = User::factory()->create();
    $this->actingAs($user, 'api-guard');
    
    $data = [
        'service' => 'GitHub',
        'account' => 'user@example.com',
        'secret' => json_encode([
            'ciphertext' => '...',
            'iv' => '...',
            'authTag' => '...',
        ]),
        'encrypted' => true,
    ];
    
    // Act
    $response = $this->postJson('/api/v1/twofaccounts', $data);
    
    // Assert
    $response->assertStatus(201);
    $this->assertDatabaseHas('twofaccounts', [
        'user_id' => $user->id,
        'service' => 'GitHub',
        'encrypted' => true,
    ]);
}
```

### Testing Encrypted Data

```php
public function test_encrypted_secret_never_decrypted_server_side()
{
    // Server should NEVER be able to decrypt
    $account = TwoFAccount::factory()->create([
        'secret' => json_encode([
            'ciphertext' => 'base64...',
            'iv' => 'base64...',
            'authTag' => 'base64...',
        ]),
        'encrypted' => true,
    ]);
    
    // Just return encrypted as-is
    $response = $this->getJson("/api/v1/twofaccounts/{$account->id}");
    $response->assertJsonStructure([
        'data' => ['secret', 'encrypted'],
    ]);
    
    // Verify secret is still encrypted (not decrypted)
    $this->assertTrue($response['data']['encrypted']);
}
```

## Git Conventions

### Commit Message Format

```
[type] Brief description (50 chars or less)

Longer explanation if needed (wrap at 72 chars).
Multiple paragraphs okay.

Fixes #123
Related #456
```

**Types:**
- `feat:` - New feature
- `fix:` - Bug fix
- `refactor:` - Code reorganization
- `test:` - Test additions/changes
- `docs:` - Documentation
- `chore:` - Build, deps, config
- `perf:` - Performance improvements

**Examples:**
```
feat: Add E2EE setup wizard

Adds guided encryption setup for new users.
Implements Argon2id key derivation and test value encryption.

fix: Prevent N+1 queries in account list

Load groups and icons with eager loading.

test: Add integration tests for backup/restore

Covers encrypted and plaintext backup formats.
```

### Branch Naming

```
feature/user-authentication
feature/e2ee-encryption
fix/account-deletion-bug
refactor/service-layer
docs/api-documentation
```

## File Naming Conventions

### Backend
- **Controllers:** `PascalCase` + `Controller` (e.g., `UserController.php`)
- **Models:** `PascalCase` (e.g., `TwoFAccount.php`)
- **Services:** `PascalCase` + `Service` (e.g., `TwoFAccountService.php`)
- **Exceptions:** `PascalCase` + `Exception` (e.g., `InvalidSecretException.php`)
- **Requests:** `PascalCase` + `Request` (e.g., `TwoFAccountStoreRequest.php`)
- **Tests:** Test class name + `Test` (e.g., `UserControllerTest.php`)

### Frontend
- **Components:** `PascalCase.vue` (e.g., `AccountCard.vue`)
- **Views:** `PascalCase.vue` (e.g., `AccountsView.vue`)
- **Stores:** `camelCase.js` (e.g., `twofaccounts.js`)
- **Services:** `camelCase.js` (e.g., `cryptoService.js`)
- **Composables:** `camelCase.js` (e.g., `useAccountList.js`)

## Spacing & Formatting

### PHP
- **Indentation:** 4 spaces
- **Line length:** 120 characters (soft limit)
- **Brace style:** PSR-12 (opening on same line)
- **Use laravel/pint** for auto-formatting: `./vendor/bin/pint`

### JavaScript/Vue
- **Indentation:** 2 spaces
- **Line length:** 100 characters (soft limit)
- **Semicolons:** Always used
- **Quotes:** Single quotes for strings
- **Use ESLint** for linting: `npx eslint resources/js`

## Security Standards

### Never Log Secrets
```php
// ❌ WRONG
Log::info('Processing secret: ' . $secret);

// ✅ CORRECT
Log::info('Processing account', ['id' => $id, 'service' => $service]);
```

### Never Store Keys in LocalStorage
```javascript
// ❌ WRONG
localStorage.setItem('encryptionKey', key);

// ✅ CORRECT
const cryptoStore = useCryptoStore()
cryptoStore.setEncryptionKey(key) // Memory only, cleared on reload
```

### Validate at Boundaries
```php
// Server validates user input (required)
class TwoFAccountStoreRequest extends FormRequest {
    public function rules(): array {
        return ['secret' => 'required|string'];
    }
}

// Trust internal data (no need to re-validate)
$this->twofaccountService->create($validated); // Already validated
```

### CSRF Protection
All API routes protected automatically by Laravel Sanctum. Frontend Axios handles CSRF token:

```javascript
// Automatic via cookie
await axios.post('/api/v1/twofaccounts', data)
```

## Performance Standards

### Database
- Always eager load relationships
- Use pagination for large datasets
- Index frequently queried columns
- Use database transactions for multi-step operations

### Frontend
- Lazy load routes and components
- Memoize expensive computations with `computed()`
- Debounce search and filter inputs
- Use virtual scrolling for large lists

### Caching
- Cache API responses in stores
- Use browser cache headers
- Cache expensive cryptographic operations when possible

## Accessibility Standards

### Frontend
- All form inputs have associated `<label>` elements
- Use semantic HTML (`<button>`, `<nav>`, etc.)
- Color alone doesn't communicate information
- Keyboard navigation fully supported
- ARIA attributes for complex components

### Error Messages
- Clear, user-friendly messages
- Suggest corrective actions
- Don't expose system details to users
