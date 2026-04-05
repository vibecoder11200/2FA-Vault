# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **📚 After context compaction:** Always read the documentation files listed below to rebuild context:
> 1. [codebase-summary.md](docs/codebase-summary.md) — Directory structure and file organization
> 2. [system-architecture.md](docs/system-architecture.md) — Technical architecture and data flows
> 3. [code-standards.md](docs/code-standards.md) — Coding patterns and conventions
> 4. [project-overview-pdr.md](docs/project-overview-pdr.md) — Project vision and design rationale

## Quick Start — Common Commands

### Backend (PHP/Laravel)
```bash
composer install              # Install dependencies
composer test                 # Run all tests (PHPUnit with SQLite)
composer test-para            # Run tests in parallel
./vendor/bin/pint             # Auto-fix code style
./vendor/bin/phpstan analyse  # Static analysis
php artisan serve             # Start dev server (http://127.0.0.1:8000)
php artisan migrate           # Run migrations
```

### Frontend (Vue 3/TypeScript)
```bash
npm install                   # Install dependencies
npm run dev                   # Dev server with hot-reload (http://127.0.0.1:5173)
npm run build                 # Production build
npm run rebuild               # Watch mode for development
npx eslint resources/js       # Lint frontend code
```

### Running Specific Tests
```bash
# Single test file
vendor/bin/phpunit tests/Feature/Http/Auth/UserControllerTest.php

# Specific test method
vendor/bin/phpunit --filter testUserCanLogin tests/Feature/Http/Auth/UserControllerTest.php

# Test coverage HTML report
composer test-coverage-html   # Output to tests/Coverage/
```

## Project Overview

**2FA-Vault** is an enhanced fork of [2FAuth](https://github.com/Bubka/2FAuth) — a Laravel 12 + Vue 3 web app for managing two-factor authentication accounts.

### Enterprise Features
- **End-to-End Encryption (E2EE)**: Client-side only, server never sees plaintext secrets
- **Multi-User & Team Management**: Role-based access control and secure sharing
- **Browser Extension**: Chrome/Firefox for seamless OTP access
- **Progressive Web App (PWA)**: Offline support with background sync
- **Encrypted Backups**: Double-encrypted `.vault` format

### Tech Stack
| Layer | Technology |
|-------|-----------|
| Backend | Laravel 12, PHP 8.4+ |
| Frontend | Vue 3 (Composition API), TypeScript 5, Vite |
| State | Pinia stores (auto-imported) |
| Crypto | Argon2id key derivation, AES-256-GCM encryption |
| OTP | Spomky-Labs/OTPHP (RFC 4226/6238 compliant) |
| Auth | Laravel Passport, WebAuthn (passwordless) |

## Architecture at a Glance

### Zero-Knowledge E2EE
**Critical principle:** Server never sees plaintext secrets. All encryption/decryption happens client-side.
- Client derives key from password via Argon2id → encrypts secrets → sends ciphertext to server
- Server stores encrypted secrets as JSON: `{ciphertext, iv, authTag}`
- Client decrypts on fetch using key stored in memory (Pinia store, cleared on reload)

**Code locations:**
- Client crypto: `resources/js/services/crypto.js`
- Encryption state: `resources/js/stores/crypto.js`
- Server E2EE: `app/Http/Controllers/EncryptionController.php`

### Backend Architecture
```
routes/api/v1.php → Controllers (app/Api/v1/Controllers/) 
  ↓
Services (app/Services/) — Business logic layer
  ↓
Models (app/Models/) — Eloquent with relationships
  ↓
Database (app/Models + migrations)
```

**Key services:** TwoFAccountService, GroupService, BackupService, IconService, QrCodeService

### Frontend Architecture
```
resources/js/
├── services/       # API clients & crypto functions
├── stores/         # Pinia state management (auto-imported)
├── composables/    # Vue 3 composables (auto-imported)
├── components/     # Reusable Vue components (auto-imported)
├── views/          # Page components
└── router/         # Vue Router configuration
```

**Auto-imported:** Vue 3 composables, store definitions, services, components. No explicit imports needed for these.

## Key Patterns & Conventions

### Backend
- **Service layer pattern:** All business logic in `app/Services/`, controllers delegate to services
- **Encryption handling:** Always check `encrypted` flag before processing secrets. Never decrypt server-side.
- **API responses:** Consistent JSON format with status codes (200, 201, 422 for validation errors)
- **Eager loading:** Use `.with('relation')` to prevent N+1 queries

### Frontend
- **Composition API only:** All Vue components use `<script setup>`
- **Encryption workflow:** Encrypt secrets before API calls, decrypt after fetch
- **Store pattern:** Use Pinia stores for all state mutations (no direct component mutations)
- **TypeScript:** Use in services and composables for type safety

### Testing
- **Unit tests:** `tests/Unit/` (isolated, no DB)
- **Feature tests:** `tests/Feature/` (with database)
- **API tests:** `tests/Api/v1/` (endpoint testing)
- **Test pattern:** Use factories, act as user, assert response + database state

### Database
- **Encrypted secrets:** Stored as JSON strings `{ciphertext, iv, authTag}`
- **Migration naming:** `YYYY_MM_DD_HHMMSS_create_table.php` or `add_column_to_table.php`

For detailed conventions and security guidelines, see [DEVELOPMENT.md](DEVELOPMENT.md).

## GitNexus — Code Intelligence

This project is indexed by GitNexus as **2FA-Vault** (4261 symbols, 11127 relationships, 185 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> If any GitNexus tool warns the index is stale, run `npx gitnexus analyze` in terminal first.

### Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `gitnexus_impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `gitnexus_detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `gitnexus_query({query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `gitnexus_context({name: "symbolName"})`.

### When Debugging

1. `gitnexus_query({query: "<error or symptom>"})` — find execution flows related to the issue
2. `gitnexus_context({name: "<suspect function>"})` — see all callers, callees, and process participation
3. `READ gitnexus://repo/2FA-Vault/process/{processName}` — trace the full execution flow step by step
4. For regressions: `gitnexus_detect_changes({scope: "compare", base_ref: "main"})` — see what your branch changed

### When Refactoring

- **Renaming**: MUST use `gitnexus_rename({symbol_name: "old", new_name: "new", dry_run: true})` first. Review the preview — graph edits are safe, text_search edits need manual review. Then run with `dry_run: false`.
- **Extracting/Splitting**: MUST run `gitnexus_context({name: "target"})` to see all incoming/outgoing refs, then `gitnexus_impact({target: "target", direction: "upstream"})` to find all external callers before moving code.
- After any refactor: run `gitnexus_detect_changes({scope: "all"})` to verify only expected files changed.

### Never Do

- NEVER edit a function, class, or method without first running `gitnexus_impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `gitnexus_rename` which understands the call graph.
- NEVER commit changes without running `gitnexus_detect_changes()` to check affected scope.

### Tools Quick Reference

| Tool | When to use | Command |
|------|-------------|---------|
| `query` | Find code by concept | `gitnexus_query({query: "auth validation"})` |
| `context` | 360-degree view of one symbol | `gitnexus_context({name: "validateUser"})` |
| `impact` | Blast radius before editing | `gitnexus_impact({target: "X", direction: "upstream"})` |
| `detect_changes` | Pre-commit scope check | `gitnexus_detect_changes({scope: "staged"})` |
| `rename` | Safe multi-file rename | `gitnexus_rename({symbol_name: "old", new_name: "new", dry_run: true})` |
| `cypher` | Custom graph queries | `gitnexus_cypher({query: "MATCH ..."})` |

### Impact Risk Levels

| Depth | Meaning | Action |
|-------|---------|--------|
| d=1 | WILL BREAK — direct callers/importers | MUST update these |
| d=2 | LIKELY AFFECTED — indirect deps | Should test |
| d=3 | MAY NEED TESTING — transitive | Test if critical path |

### Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/2FA-Vault/context` | Codebase overview, check index freshness |
| `gitnexus://repo/2FA-Vault/clusters` | All functional areas |
| `gitnexus://repo/2FA-Vault/processes` | All execution flows |
| `gitnexus://repo/2FA-Vault/process/{name}` | Step-by-step execution trace |

### Self-Check Before Finishing

Before completing any code modification task, verify:
1. `gitnexus_impact` was run for all modified symbols
2. No HIGH/CRITICAL risk warnings were ignored
3. `gitnexus_detect_changes()` confirms changes match expected scope
4. All d=1 (WILL BREAK) dependents were updated

### Keeping the Index Fresh

After committing code changes, the GitNexus index becomes stale. Re-run analyze to update it:

```bash
npx gitnexus analyze
```

If the index previously included embeddings, preserve them by adding `--embeddings`:

```bash
npx gitnexus analyze --embeddings
```

To check whether embeddings exist, inspect `.gitnexus/meta.json` — the `stats.embeddings` field shows the count (0 means no embeddings). **Running analyze without `--embeddings` will delete any previously generated embeddings.**

> Claude Code users: A PostToolUse hook handles this automatically after `git commit` and `git merge`.

### GitNexus Skills

| Task | Skill File |
|------|-----------|
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |

## Resources

### Core Documentation (Read First)
- [docs/codebase-summary.md](docs/codebase-summary.md) — File structure, directories, key files, services, stores
- [docs/system-architecture.md](docs/system-architecture.md) — Layered architecture, data flows, encryption, database schema
- [docs/code-standards.md](docs/code-standards.md) — Backend/frontend/testing conventions, security guidelines
- [docs/project-overview-pdr.md](docs/project-overview-pdr.md) — Vision, design principles, scope, technology choices

### Additional Documentation
- [DEVELOPMENT.md](DEVELOPMENT.md) — Detailed conventions, security guidelines, common pitfalls
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — Technical architecture details
- [docs/SECURITY.md](docs/SECURITY.md) — Security model and threat analysis
- [docs/MIGRATION.md](docs/MIGRATION.md) — Migration guide from 2FAuth to 2FA-Vault
- [README.md](README.md) — Feature overview and installation guides

## Commit Standards

**Format:** `[type] Brief description` (no Co-Authored-By footer)

**Examples:**
```
feat: Add E2EE setup wizard
fix: Prevent N+1 queries in account list
refactor: Move crypto logic to service layer
test: Add E2EE encryption tests
docs: Update architecture documentation
```

**Types:** `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`

**Guidelines:**
- Concise commit messages (50 chars title max)
- Describe the **why**, not just the **what**
- Keep commits atomic (one logical change per commit)
- Run `npm run lint` and `composer test` before committing