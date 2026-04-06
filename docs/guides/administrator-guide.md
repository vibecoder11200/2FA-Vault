# 2FA-Vault Administrator Guide

Complete guide for administrators managing 2FA-Vault instances.

---

## Table of Contents

1. [Installation](#installation)
2. [Initial Configuration](#initial-configuration)
3. [User Management](#user-management)
4. [Team Management](#team-management)
5. [Security Configuration](#security-configuration)
6. [Monitoring & Logging](#monitoring--logging)
7. [Backup & Recovery](#backup--recovery)
8. [Performance Tuning](#performance-tuning)
9. [Maintenance Tasks](#maintenance-tasks)
10. [Troubleshooting](#troubleshooting)

---

## Installation

### System Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| PHP | 8.4 | 8.4+ |
| MySQL | 5.7+ | 8.0+ |
| Redis | 5.0+ (optional) | 7.0+ |
| RAM | 1 GB | 2 GB+ |
| Disk | 5 GB | 10 GB+ |

### Quick Install (Docker)

```bash
git clone https://github.com/your-org/2FA-Vault.git
cd 2FA-Vault
cp .env.example .env
nano .env  # Configure your settings
docker-compose up -d
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate --force
```

### Traditional Install

See the [Deployment Guide](./deployment-guide.md) for detailed instructions.

---

## Initial Configuration

### Environment Variables

Edit `.env` after installation:

```bash
# Application
APP_NAME="2FA-Vault"
APP_ENV=production
APP_DEBUG=false  # ALWAYS false in production
APP_URL=https://your-domain.com

# Database
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=2fa_vault
DB_USERNAME=2fa_vault
DB_PASSWORD=strong-random-password

# Cache (use Redis for production)
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Security
ENCRYPTION_ENABLED=true  # E2EE default ON
ALLOW_REGISTRATION=true  # Set false for private deployments

# Mail (required for team invites)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=postmaster@your-domain.com
MAIL_PASSWORD=your-smtp-password
MAIL_ENCRYPTION=tls

# Rate Limiting
THROTTLE_API=60,1    # 60 requests per minute
THROTTLE_LOGIN=5,1   # 5 login attempts per minute
```

### Generate Application Keys

```bash
# Laravel app key
php artisan key:generate

# VAPID keys for push notifications
npx web-push generate-vapid-keys
# Add VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY to .env

# Passport OAuth (if using API tokens)
php artisan passport:install
```

### Create Admin User

```bash
php artisan tinker
>>> User::create([
    'name' => 'Admin',
    'email' => 'admin@your-domain.com',
    'password' => bcrypt('strong-password'),
    'is_admin' => true
]);
```

---

## User Management

### View All Users

**Via Admin UI:**
1. Log in as admin
2. Go to Settings → Admin → Users

**Via API:**
```bash
curl -X GET https://your-domain.com/api/v1/admin/users \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Filter Users

```bash
# Active users only
GET /api/v1/admin/users?status=active

# Admins only
GET /api/v1/admin/users?role=admin

# Paginated
GET /api/v1/admin/users?per_page=50
```

### Update User

**Fields:**
- `name` - Display name
- `email` - Email address (must be unique)
- `is_admin` - Admin status
- `is_active` - Account active status

**Via API:**
```bash
curl -X PUT https://your-domain.com/api/v1/admin/users/123 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"is_admin": true}'
```

### Deactivate User

Soft-deactivates a user (preserves data):

```bash
curl -X DELETE https://your-domain.com/api/v1/admin/users/123 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Important:** Cannot deactivate yourself or the last admin.

### Reset User Password

```bash
php artisan tinker
>>> $user = User::find(123);
>>> $user->password = bcrypt('new-password');
>>> $user->save();
```

Or use the password reset endpoint as the user.

### View User Details

```bash
curl -X GET https://your-domain.com/api/v1/admin/users/123 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Returns:
- User info
- Relationship counts (teams, accounts, groups)
- Team memberships

---

## Team Management

### View All Teams

```bash
php artisan tinker
>>> Team::with('owner', 'users')->get()
```

### Team Limits

Configure in `.env` or `config/2fauth.php`:

```php
'maxTeamsPerUser' => 10,      // Default: 10
'maxMembersPerTeam' => 50,    // Default: 50
```

### Transfer Team Ownership

Via database:
```bash
php artisan tinker
>>> $team = Team::find(1);
>>> $team->owner_id = 456;  # New owner user ID
>>> $team->save();
>>> $team->users()->updateExistingPivot(456, ['role' => 'owner']);
```

### Delete Team

```bash
php artisan tinker
>>> Team::find(1)->delete();  # Soft delete
```

To permanently delete:
```bash
>>> Team::find(1)->forceDelete();
```

---

## Security Configuration

### E2EE Settings

```php
// config/2fauth.php
'encryptionEnabledByDefault' => true,  // Force E2EE on new accounts
'argon2' => [
    'memory' => 65536,    // 64 MB
    'time' => 3,          // 3 iterations
    'threads' => 4,       // 4 threads
],
```

### Authentication Guards

```php
// config/auth.php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    'api' => [
        'driver' => 'passport',  // OAuth2 tokens
        'provider' => 'users',
        'hash' => false,
    ],
],
```

### Content Security Policy

Enable in `.env`:
```bash
CONTENT_SECURITY_POLICY=true
```

Configure CSP headers in `app/Http/Middleware/TrustProxies.php`.

### Rate Limiting

```php
// app/Providers/RouteServiceProvider.php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip());
});
```

### HTTPS Enforcement

In production, ensure:

```bash
# .env
APP_URL=https://your-domain.com
FORCE_HTTPS=true
```

And configure your web server (Nginx/Apache) to redirect HTTP to HTTPS.

---

## Monitoring & Logging

### Log Files

| Log | Location | Purpose |
|-----|----------|---------|
| Laravel | `storage/logs/laravel.log` | Application logs |
| Queue | `storage/logs/queue.log` | Job processing |
| PHP-FPM | `/var/log/php8.4-fpm.log` | PHP errors |
| Nginx | `/var/log/nginx/error.log` | Web server errors |

### Log Levels

Configure in `.env`:
```bash
LOG_LEVEL=warning  # debug, info, notice, warning, error, critical, alert, emergency
```

### Health Checks

2FA-Vault includes a health endpoint:

```bash
curl https://your-domain.com/up
```

Returns HTTP 200 with health status.

### Monitoring Commands

```bash
# Check disk usage
df -h

# Check memory
free -h

# Check running processes
ps aux | grep php

# Docker stats (if using Docker)
docker stats

# Check queue jobs
php artisan queue:failed
```

### Error Notifications

Configure email for critical errors:

```bash
# .env
LOG_CHANNEL=daily
MAIL_MAILER=smtp
# ... mail configuration
```

Or use a service like:
- **Sentry** - Error tracking
- **Bugsnag** - Error monitoring
- **Rollbar** - Exception tracking

---

## Backup & Recovery

### Database Backup

**Automated (cron):**
```bash
# Daily backup at 2 AM
0 2 * * * /usr/bin/mysqldump -u root -pPASSWORD 2fa_vault | gzip > /backups/db-$(date +\%Y\%m\%d).sql.gz

# Keep last 30 days
0 3 * * 0 find /backups -name "db_*.sql.gz" -mtime +30 -delete
```

**Manual:**
```bash
mysqldump -u root -p 2fa_vault > backup-$(date +%Y%m%d).sql
```

### Application Backup

```bash
tar -czf app-backup-$(date +%Y%m%d).tar.gz \
    /var/www/2FA-Vault/storage \
    /var/www/2FA-Vault/.env \
    /var/www/2FA-Vault/public/uploads
```

### Encrypted Backup Encryption

User backups use double encryption:
1. Client-side with user's backup password
2. AES-256-GCM encryption

You cannot decrypt user backups - this is by design for zero-knowledge architecture.

### Disaster Recovery

**To restore from database backup:**
```bash
# Stop the application
sudo systemctl stop nginx php8.4-fpm

# Restore database
gunzip < /backups/db-20260401.sql.gz | mysql -u root -p 2fa_vault

# Restore application files
tar -xzf app-backup-20260401.tar.gz -C /

# Restart services
sudo systemctl start nginx php8.4-fpm
```

---

## Performance Tuning

### Enable Caching

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize autoloader
composer dump-autoload --optimize
```

### Use Redis

```bash
# .env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### Queue Workers

For production, use dedicated queue workers:

```bash
# systemd service: /etc/systemd/system/2fa-vault-worker.service
[Unit]
Description=2FA-Vault Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/2FA-Vault
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3
Restart=always

[Install]
WantedBy=multi-user.target
```

Enable:
```bash
sudo systemctl enable 2fa-vault-worker
sudo systemctl start 2fa-vault-worker
```

### PHP OPcache

Ensure OPcache is enabled in `php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
```

### Database Optimization

```sql
-- Add indexes for common queries
CREATE INDEX idx_twofaccounts_user_id ON twofaccounts(user_id);
CREATE INDEX idx_twofaccounts_group_id ON twofaccounts(group_id);
CREATE INDEX idx_teams_owner_id ON teams(owner_id);
```

---

## Maintenance Tasks

### Scheduled Tasks

Add to crontab (`crontab -e`):

```bash
# Laravel scheduler (runs every minute)
* * * * * cd /var/www/2FA-Vault && php artisan schedule:run >> /dev/null 2>&1

# Daily database backup
0 2 * * * /usr/bin/mysqldump -u root -pPASSWORD 2fa_vault | gzip > /backups/db-$(date +\%Y\%m\%d).sql.gz

# Weekly log cleanup
0 3 * * 0 find /var/www/2FA-Vault/storage/logs -name "*.log" -mtime +30 -delete

# Monthly cache clear
0 4 1 * * cd /var/www/2FA-Vault && php artisan cache:clear
```

### Update Procedure

```bash
# Backup current version
tar -czf /backups/pre-update-$(date +%Y%m%d).tar.gz /var/www/2FA-Vault

# Pull latest code
cd /var/www/2FA-Vault
git pull origin main

# Update dependencies
composer install --no-dev --optimize-autoloader
npm install
npm run build

# Run migrations
php artisan migrate --force

# Clear cache
php artisan cache:clear
php artisan config:clear
php artisan route:cache
php artisan view:cache

# Restart services
sudo systemctl reload nginx
sudo systemctl restart php8.4-fpm
sudo systemctl restart 2fa-vault-worker
```

### Health Monitoring

Set up monitoring for:
- Server uptime (UptimeRobot, Pingdom)
- SSL certificate expiry
- Disk space (> 80% alert)
- Memory usage (> 90% alert)
- Application health endpoint

---

## Troubleshooting

### High CPU Usage

**Diagnose:**
```bash
top  # Check which process is using CPU
```

**Solutions:**
- Restart queue worker
- Clear cache
- Check for runaway PHP processes
- Consider upgrading server resources

### High Memory Usage

**Diagnose:**
```bash
free -h
ps aux --sort=-%mem | head
```

**Solutions:**
- Increase PHP memory limit
- Restart services
- Check for memory leaks in custom code
- Enable Redis for cache/sessions

### Database Slow Queries

**Diagnose:**
```bash
# Enable slow query log
# /etc/mysql/my.cnf
slow_query_log = 1
long_query_time = 2
```

**Solutions:**
- Add indexes to slow queries
- Use eager loading to prevent N+1
- Consider read replicas for high traffic

### Email Not Sending

**Diagnose:**
```bash
php artisan tinker
>>> Mail::raw('Test', fn($msg) => $msg->to('test@example.com'))->send();
```

**Solutions:**
- Check SMTP credentials
- Verify mail server is reachable
- Check spam folder
- Try alternative mail driver

### Queue Jobs Stuck

**Diagnose:**
```bash
php artisan queue:failed
```

**Solutions:**
```bash
# Retry failed jobs
php artisan queue:retry all

# Clear stuck jobs
php artisan queue:flush

# Restart worker
sudo systemctl restart 2fa-vault-worker
```

---

## Additional Resources

- [Deployment Guide](./deployment-guide.md) - Production deployment
- [API Documentation](../reference/api-documentation.md) - API reference
- [Security Guidelines](../development/security-guidelines.md) - Security best practices
- [User Guide](./user-guide.md) - End-user documentation
- [Troubleshooting Guide](./troubleshooting.md) - Common issues

---

**Version:** 1.0.0
**Last Updated:** April 2026
