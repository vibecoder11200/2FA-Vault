# Remaining Test Issues

Generated: 2026-04-05

## Summary

| Metric | Count |
|--------|-------|
| Total Tests | 1,381 |
| Passing | 1,339 (97%) |
| Failing | 20 |
| Errors | 22 |
| Skipped | 2 |

**Target Status:** ✅ 95%+ ACHIEVED (97%)

---

## Issue Breakdown

### P0 - Critical (Blocks Release)

**None** - All critical tests passing.

---

### P1 - High Priority (Should Fix)

#### Category: E2EE Account Tests (10 failures)

**File:** `tests/Feature/AccountEncryptionE2ETest.php`

| Test | Issue | Fix Effort |
|------|-------|------------|
| `test_can_create_encrypted_twofaccount` | Validation errors: missing icon, otp_type, secret format | 2-3 hrs |
| `test_can_update_encrypted_twofaccount` | Same validation errors | 1 hr |
| `test_can_delete_encrypted_twofaccount` | Expects 200, gets 204 (DELETE returns no content) | 30 min |
| `test_can_re_encrypt_accounts_on_password_change` | Expects POST, endpoint may not exist (405) | 2-3 hrs |
| `test_validates_encrypted_secret_structure` | Validation rejects encrypted JSON format | 2-3 hrs |
| `test_can_batch_update_encrypted_secrets` | Same validation errors | 1-2 hrs |
| `test_export_includes_encrypted_accounts` | Missing `ids` parameter in request | 30 min |
| `test_migrate_handles_encrypted_accounts` | Migrator throws exception on encrypted data | 2-3 hrs |
| `test_encrypted_accounts_survive_group_assignment` | API parameter format issue (`ids` vs `id`) | 30 min |
| `test_withdraw_removes_group_but_keeps_encryption` | Same parameter format issue | 30 min |

**Root Cause:** Tests sending encrypted JSON secrets but validation expects Base32 format. The request validation rules need updating to handle E2EE payloads.

**Total Effort:** ~14-16 hours

---

#### Category: Team Management (1 failure)

**File:** `tests/Feature/TeamControllerTest.php`

| Test | Issue | Fix Effort |
|------|-------|------------|
| `test_user_can_join_team_via_invite` | Returns 400, likely validation or invite token issue | 1-2 hrs |

---

#### Category: Backup Service (2 failures)

**File:** `tests/Feature/Services/BackupServiceTest.php`

| Test | Issue | Fix Effort |
|------|-------|------------|
| `test_backup_stats_should_backup_when_old` | Timing/condition logic issue | 1-2 hrs |
| `test_handles_large_backup_count` | Expects 100 accounts, gets 1 - factory issue | 1 hr |

---

### P2 - Medium Priority (Nice to Have)

#### Category: E2E Test Errors (22 errors)

**File:** `tests/Feature/AccountEncryptionE2ETest.php`

All 10 tests above also throw exceptions due to:
1. Invalid migration data format for encrypted accounts
2. Request validation rejecting encrypted payloads

**Total Effort:** Included in P1 estimates above

---

## Detailed Analysis

### AccountEncryptionE2ETest Issues

The `AccountEncryptionE2ETest` tests were written for E2EE functionality but the API validation rules still expect:

1. **Base32-encoded secrets** - E2EE sends `{"ciphertext":"...","iv":"...","authTag":"..."}`
2. **All fields present** - E2EE may omit some fields during partial updates
3. **Specific parameter names** - Some tests use `id` vs `ids` inconsistency

**Recommended Fixes:**

1. Update `TwoFAccountStoreRequest` to accept encrypted JSON format
2. Create separate validation rules for encrypted vs plaintext accounts
3. Update test expectations for DELETE (204 instead of 200)
4. Add `/api/v1/accounts/re-encrypt` endpoint if missing
5. Fix `ids` parameter consistency in group assignment endpoints

### BackupServiceTest Issues

1. **`test_backup_stats_should_backup_when_old`** - Logic checks if backup is "old enough" but condition may never be true in test environment
2. **`test_handles_large_backup_count`** - Factory creating only 1 account instead of 100

**Recommended Fixes:**
1. Adjust time-based conditions for test environment
2. Check factory `count()` parameter usage

### TeamControllerTest Issue

**`test_user_can_join_team_via_invite`** returns 400 - could be:
- Invalid invite token format
- Expired invite
- Missing required fields
- Business logic validation

**Recommended Fix:** Debug the 400 response to identify specific validation error

---

## Fix Priority Recommendations

### Sprint 1 (E2EE Validation) - 14-16 hours
1. Fix AccountEncryptionE2ETest validation issues
2. Update API validation for encrypted payloads
3. Add/fix re-encryption endpoint
4. Fix parameter naming consistency

### Sprint 2 (Edge Cases) - 3-5 hours
1. Fix BackupServiceTest issues
2. Debug and fix TeamControllerTest
3. Adjust test expectations for standard HTTP codes

---

## Test Files Summary

| File | Total | Pass | Fail | Error |
|------|-------|------|------|-------|
| AccountEncryptionE2ETest.php | 12 | 2 | 10 | 0 |
| BackupServiceTest.php | ~15 | ~13 | 2 | 0 |
| TeamControllerTest.php | ~8 | ~7 | 1 | 0 |
| All other files | ~1,346 | ~1,317 | 7 | 22 |

---

## Notes

- All E2EE infrastructure tests (`EncryptionControllerTest`, `EncryptionServiceTest`) are passing ✅
- All authentication tests (`WebAuthn*`, `LoginTest`, etc.) are passing ✅
- All basic account CRUD tests are passing ✅
- The remaining failures are primarily in **new feature areas** still under development

---

## Next Actions

1. **Immediate:** Update validation rules to support E2EE payloads
2. **Short-term:** Fix parameter naming inconsistencies
3. **Medium-term:** Complete E2EE workflow tests
4. **Long-term:** Add more comprehensive integration tests
