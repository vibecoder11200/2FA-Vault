# 2FA-Vault

![License](https://img.shields.io/badge/license-AGPL--3.0-blue.svg?style=flat-square)
![PHP](https://img.shields.io/badge/PHP-^8.4-777BB4.svg?style=flat-square&logo=php)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20.svg?style=flat-square&logo=laravel)
![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D.svg?style=flat-square&logo=vue.js)
![TypeScript](https://img.shields.io/badge/TypeScript-5-3178C6.svg?style=flat-square&logo=typescript)

> **2FA-Vault** is a personal fork of [Bubka/2FAuth](https://github.com/Bubka/2FAuth) — a self-hosted web app to manage Two-Factor Authentication (2FA) accounts and generate OTP codes. This fork is a work in progress and experiments with multi-user, team sharing, and end-to-end encryption on top of the upstream project. It is **not** an official 2FAuth release.

## Relationship to upstream

2FA-Vault forks the full 2FAuth ecosystem. All credit for the original design and implementation goes to [Bubka](https://github.com/Bubka) and the 2FAuth contributors.

| Fork | Upstream |
|------|----------|
| [TranAnSE/2FA-Vault](https://github.com/TranAnSE/2FA-Vault) | [Bubka/2FAuth](https://github.com/Bubka/2FAuth) |
| [TranAnSE/2FA-Vault-WebExtension](https://github.com/TranAnSE/2FA-Vault-WebExtension) | [Bubka/2FAuth-WebExtension](https://github.com/Bubka/2FAuth-WebExtension) |
| [TranAnSE/2FA-Vault-Components](https://github.com/TranAnSE/2FA-Vault-Components) | [Bubka/2FAuth-Components](https://github.com/Bubka/2FAuth-Components) |
| [TranAnSE/2FA-Vault-Docs](https://github.com/TranAnSE/2FA-Vault-Docs) | [Bubka/2FAuth-Docs](https://github.com/Bubka/2FAuth-Docs) |
| [TranAnSE/2FA-Vault-API](https://github.com/TranAnSE/2FA-Vault-API) | [Bubka/2FAuth-API](https://github.com/Bubka/2FAuth-API) |

If you want the stable, official product, please use the upstream repos above.

## What this fork changes / explores

These are the directions 2FA-Vault is experimenting with. Not all are complete — see the codebase and commit history for actual state.

- **End-to-end encryption (E2EE).** Upstream offers optional at-rest encryption using `APP_KEY`; this fork experiments with client-side key derivation (Argon2id) and AES-256-GCM so the server never sees plaintext secrets.
- **Multi-user & teams.** Upstream is single-user by design. This fork explores multi-user accounts and team sharing with role-based access.
- **Encrypted backup format.** Exports encrypted with a user-chosen password.
- **Browser extension fork.** Tracks upstream `2FAuth-WebExtension` with adjustments for the features above.
- **PWA tweaks.** Offline behavior and install flow.

## Tech stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 12, PHP 8.4+ |
| Frontend | Vue 3 (Composition API), TypeScript 5, Vite |
| State | Pinia |
| Crypto | Argon2id (KDF), AES-256-GCM (Web Crypto API) |
| OTP | [Spomky-Labs/OTPHP](https://github.com/Spomky-Labs/otphp) (RFC 4226 / 6238) |
| Auth | Laravel Passport, WebAuthn |

## Getting started (development)

```bash
git clone https://github.com/TranAnSE/2FA-Vault.git
cd 2FA-Vault

composer install
cp .env.example .env
php artisan key:generate
php artisan migrate

npm install
npm run dev       # hot-reload
php artisan serve # http://127.0.0.1:8000
```

For production deployment, the upstream [2FAuth install docs](https://docs.2fauth.app/getting-started/installation/) are still largely applicable — this fork has not diverged in deployment model.

## Project documentation

Internal docs live under [`docs/`](docs/):

- [Architecture](docs/architecture/system-architecture.md) — data flows, encryption model
- [Codebase summary](docs/architecture/codebase-summary.md) — directory layout
- [Code standards](docs/development/code-standards.md)
- [Security notes](docs/development/security-guidelines.md)
- [Migration from 2FAuth](docs/guides/migration-from-2fauth.md)

These describe the *intended* design of the fork; sections may be ahead of the code.

## Contributing

This is primarily a personal fork. Upstream contributions (bug fixes, features useful to everyone) are better sent to [Bubka/2FAuth](https://github.com/Bubka/2FAuth). Issues and PRs specific to the fork-only features (E2EE, multi-user, teams) are welcome here.

## License

[AGPL-3.0](https://www.gnu.org/licenses/agpl-3.0.html) — same as upstream 2FAuth.

## Acknowledgments

- [Bubka/2FAuth](https://github.com/Bubka/2FAuth) and all its contributors — this fork would not exist without your work.
- [Spomky-Labs/OTPHP](https://github.com/Spomky-Labs/otphp) for RFC-compliant OTP generation.
- [Laravel](https://laravel.com/) and [Vue.js](https://vuejs.org/).
