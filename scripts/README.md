# Scripts Directory

Automation scripts for 2FA-Vault development and deployment.

## Development Scripts

### `setup-dev.sh` (Linux/Mac)
Automated development environment setup using Docker.

```bash
chmod +x scripts/setup-dev.sh
./scripts/setup-dev.sh
```

**What it does:**
- Checks Docker and Docker Compose installation
- Creates required directories (storage, database, cache)
- Copies .env.dev to .env
- Builds Docker images
- Starts all containers (app, vite, mysql, redis, mailhog, phpmyadmin)
- Installs Composer and NPM dependencies
- Runs database migrations
- Installs Laravel Passport
- Clears caches
- Creates storage symlink

**Time:** ~5-10 minutes (depending on internet speed)

### `setup-dev.ps1` (Windows)
Same as `setup-dev.sh` but for Windows PowerShell.

```powershell
.\scripts\setup-dev.ps1
```

**Requirements:**
- PowerShell 5.1+
- Docker Desktop for Windows

## Usage Examples

### First-time setup
```bash
# Linux/Mac
./scripts/setup-dev.sh

# Windows
.\scripts\setup-dev.ps1
```

### After setup
```bash
# Start environment
docker-compose -f docker-compose.dev.yml up -d

# View logs
docker-compose -f docker-compose.dev.yml logs -f

# Run tests
docker-compose -f docker-compose.dev.yml exec app composer test

# Stop environment
docker-compose -f docker-compose.dev.yml down
```

## Future Scripts (To be added)

- `test-all.sh` - Run all test suites with coverage
- `deploy-staging.sh` - Deploy to staging environment
- `deploy-prod.sh` - Deploy to production environment
- `backup-db.sh` - Backup database
- `restore-db.sh` - Restore database from backup
- `generate-tests.sh` - Generate test scaffolding
- `update-deps.sh` - Update all dependencies safely

## Contributing

When adding new scripts:
1. Add execute permission: `chmod +x scripts/your-script.sh`
2. Include header comments explaining what the script does
3. Use `set -e` to exit on error
4. Add colored output for better UX
5. Update this README
6. Test on both Linux and Windows (if applicable)
