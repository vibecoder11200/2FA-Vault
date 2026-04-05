# 2FA-Vault Development Environment Setup

## Hướng dẫn Setup nhanh

### 1. Prerequisites

- **Docker** 20.10+ và **Docker Compose** v2.0+
- **Git**
- Port 8000, 5173, 3306, 6379, 8080, 8025 phải available

### 2. Clone và Setup

```bash
# Clone repository
git clone <your-repo-url>
cd 2FA-Vault

# Copy environment file
cp .env.example .env.dev

# Tạo database directory
mkdir -p database
touch database/database.sqlite

# Tạo storage directories
mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chmod -R 775 storage bootstrap/cache
```

### 3. Start Development Environment

```bash
# Build và start tất cả services
docker-compose -f docker-compose.dev.yml up -d

# Hoặc start từng service cụ thể
docker-compose -f docker-compose.dev.yml up -d app      # Backend only
docker-compose -f docker-compose.dev.yml up -d vite     # Frontend only
docker-compose -f docker-compose.dev.yml up -d mysql    # Database
```

### 4. Xem logs

```bash
# All services
docker-compose -f docker-compose.dev.yml logs -f

# Specific service
docker-compose -f docker-compose.dev.yml logs -f app
docker-compose -f docker-compose.dev.yml logs -f vite
```

### 5. Access Applications

- **Backend API**: http://localhost:8000
- **Vite Dev Server**: http://localhost:5173
- **phpMyAdmin**: http://localhost:8080 (user: root, password: root)
- **MailHog UI**: http://localhost:8025
- **MySQL**: localhost:3306 (user: 2fa_vault, password: secret)
- **Redis**: localhost:6379

## Development Workflow

### Running Commands Inside Container

```bash
# Enter app container
docker-compose -f docker-compose.dev.yml exec app sh

# Run artisan commands
docker-compose -f docker-compose.dev.yml exec app php artisan migrate
docker-compose -f docker-compose.dev.yml exec app php artisan tinker

# Run tests
docker-compose -f docker-compose.dev.yml exec app php artisan test
docker-compose -f docker-compose.dev.yml exec app composer test

# Run linters
docker-compose -f docker-compose.dev.yml exec app ./vendor/bin/pint
docker-compose -f docker-compose.dev.yml exec app ./vendor/bin/phpstan analyse

# NPM commands
docker-compose -f docker-compose.dev.yml exec vite npm install
docker-compose -f docker-compose.dev.yml exec vite npm run build
```

### Database Management

#### SQLite (Default)

```bash
# Access SQLite database
docker-compose -f docker-compose.dev.yml exec app sqlite3 /srv/database/database.sqlite

# Reset database
docker-compose -f docker-compose.dev.yml exec app php artisan migrate:fresh --seed
```

#### Switch to MySQL

1. Update `.env.dev`:

```env
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=2fa_vault_dev
DB_USERNAME=2fa_vault
DB_PASSWORD=secret
```

2. Restart app:

```bash
docker-compose -f docker-compose.dev.yml restart app
docker-compose -f docker-compose.dev.yml exec app php artisan migrate:fresh
```

### Testing

#### Run All Tests

```bash
docker-compose -f docker-compose.dev.yml exec app composer test
```

#### Run Specific Test Suite

```bash
# Unit tests only
docker-compose -f docker-compose.dev.yml exec app vendor/bin/phpunit tests/Unit

# Feature tests only
docker-compose -f docker-compose.dev.yml exec app vendor/bin/phpunit tests/Feature

# API tests only
docker-compose -f docker-compose.dev.yml exec app vendor/bin/phpunit tests/Api

# Single test file
docker-compose -f docker-compose.dev.yml exec app vendor/bin/phpunit tests/Feature/Http/Controllers/EncryptionControllerTest.php

# Single test method
docker-compose -f docker-compose.dev.yml exec app vendor/bin/phpunit --filter testUserCanSetupEncryption
```

#### Run Tests with Coverage

```bash
docker-compose -f docker-compose.dev.yml exec app composer test-coverage-html
# Coverage report: tests/Coverage/index.html
```

#### Parallel Testing

```bash
docker-compose -f docker-compose.dev.yml exec app composer test-para
```

### Code Quality

```bash
# Laravel Pint (code formatting)
docker-compose -f docker-compose.dev.yml exec app ./vendor/bin/pint

# PHPStan (static analysis)
docker-compose -f docker-compose.dev.yml exec app ./vendor/bin/phpstan analyse

# ESLint (JavaScript)
docker-compose -f docker-compose.dev.yml exec vite npx eslint resources/js/**/*.{js,vue}
```

### Frontend Development

```bash
# Install dependencies
docker-compose -f docker-compose.dev.yml exec vite npm install

# Start dev server (with HMR)
docker-compose -f docker-compose.dev.yml up vite

# Build for production
docker-compose -f docker-compose.dev.yml exec vite npm run build
```

### Debugging

#### Enable Xdebug

1. Add to `Dockerfile.dev`:

```dockerfile
RUN pecl install xdebug && docker-php-ext-enable xdebug
```

2. Rebuild container:

```bash
docker-compose -f docker-compose.dev.yml build app
docker-compose -f docker-compose.dev.yml up -d app
```

#### View Logs

```bash
# Laravel logs
docker-compose -f docker-compose.dev.yml exec app tail -f storage/logs/laravel.log

# All logs
docker-compose -f docker-compose.dev.yml logs -f
```

## Troubleshooting

### Container won't start

```bash
# View logs
docker-compose -f docker-compose.dev.yml logs app

# Rebuild containers
docker-compose -f docker-compose.dev.yml build --no-cache
docker-compose -f docker-compose.dev.yml up -d
```

### Permission errors

```bash
# Fix storage permissions
chmod -R 775 storage bootstrap/cache
```

### Database issues

```bash
# Reset SQLite database
rm database/database.sqlite
touch database/database.sqlite
docker-compose -f docker-compose.dev.yml exec app php artisan migrate:fresh

# Reset MySQL database
docker-compose -f docker-compose.dev.yml exec mysql mysql -u root -proot -e "DROP DATABASE 2fa_vault_dev; CREATE DATABASE 2fa_vault_dev;"
docker-compose -f docker-compose.dev.yml exec app php artisan migrate:fresh
```

### Clear all caches

```bash
docker-compose -f docker-compose.dev.yml exec app php artisan optimize:clear
docker-compose -f docker-compose.dev.yml exec app php artisan config:clear
docker-compose -f docker-compose.dev.yml exec app php artisan cache:clear
docker-compose -f docker-compose.dev.yml exec app php artisan view:clear
docker-compose -f docker-compose.dev.yml exec app php artisan route:clear
```

### Composer dependency issues

```bash
# Clear composer cache
docker-compose -f docker-compose.dev.yml exec app composer clear-cache

# Fresh install
docker-compose -f docker-compose.dev.yml exec app rm -rf vendor
docker-compose -f docker-compose.dev.yml exec app composer install
```

### NPM issues

```bash
# Clear npm cache
docker-compose -f docker-compose.dev.yml exec vite npm cache clean --force

# Fresh install
docker-compose -f docker-compose.dev.yml exec vite rm -rf node_modules package-lock.json
docker-compose -f docker-compose.dev.yml exec vite npm install
```

## Stop and Cleanup

```bash
# Stop all services
docker-compose -f docker-compose.dev.yml down

# Stop and remove volumes (deletes database!)
docker-compose -f docker-compose.dev.yml down -v

# Remove all (including images)
docker-compose -f docker-compose.dev.yml down --rmi all -v
```

## Development Tips

### Hot Reload

- **Backend**: Code changes require restart (or use `php artisan serve` for auto-reload)
- **Frontend**: Vite provides HMR (Hot Module Replacement) - changes reflect immediately

### Useful Aliases (add to ~/.bashrc or ~/.zshrc)

```bash
alias dc-dev="docker-compose -f docker-compose.dev.yml"
alias dc-dev-up="docker-compose -f docker-compose.dev.yml up -d"
alias dc-dev-down="docker-compose -f docker-compose.dev.yml down"
alias dc-dev-logs="docker-compose -f docker-compose.dev.yml logs -f"
alias dc-dev-exec="docker-compose -f docker-compose.dev.yml exec app"
alias dc-dev-test="docker-compose -f docker-compose.dev.yml exec app composer test"
```

### VSCode Integration

Install the following extensions:
- **Docker** (ms-azuretools.vscode-docker)
- **PHP Intelephense** (bmewburn.vscode-intelephense-client)
- **Volar** (Vue.volar) for Vue 3
- **ESLint** (dbaeumer.vscode-eslint)

## Next Steps

1. ✅ Setup môi trường Docker
2. 📝 Phân tích test coverage hiện tại
3. 🧪 Viết E2E tests cho tính năng mới (E2EE, Teams, Browser Extension, PWA)
4. 🔧 Fix các test đang fail
5. 📚 Cập nhật documentation

Xem `docs/TESTING-PLAN.md` (sẽ tạo sau) để biết chi tiết về test plan.
