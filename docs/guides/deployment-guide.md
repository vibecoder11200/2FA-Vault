# 2FA-Vault Deployment Guide

Complete guide for deploying 2FA-Vault in production environments.

---

## Table of Contents

1. [System Requirements](#system-requirements)
2. [Deployment Methods](#deployment-methods)
3. [Docker Deployment](#docker-deployment)
4. [Traditional Server Deployment](#traditional-server-deployment)
5. [Kubernetes Deployment](#kubernetes-deployment)
6. [Environment Configuration](#environment-configuration)
7. [SSL/TLS Configuration](#ssl-tls-configuration)
8. [Database Setup](#database-setup)
9. [Production Checklist](#production-checklist)
10. [Monitoring & Maintenance](#monitoring--maintenance)
11. [Backup & Recovery](#backup--recovery)
12. [Troubleshooting](#troubleshooting)

---

## System Requirements

### Minimum Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| **PHP** | 8.4 | 8.4+ |
| **MySQL/MariaDB** | 5.7+ / 10.3+ | 8.0+ / 10.6+ |
| **Redis** (optional) | 5.0+ | 7.0+ |
| **Node.js** (build only) | 18+ | 20+ |
| **RAM** | 1 GB | 2 GB+ |
| **Disk** | 5 GB | 10 GB+ |
| **CPU** | 1 core | 2+ cores |

### PHP Extensions

Required extensions (typically included in PHP 8.4+):
- `bcmath`, `ctype`, `curl`, `date`, `dom`, `fileinfo`, `filter`, `hash`
- `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`
- `sqlite`, `xml`, `zip`, `gd`, `intl`

### Web Server

- **Nginx** 1.18+ (recommended)
- **Apache** 2.4+ with `mod_rewrite`

---

## Deployment Methods

Choose based on your infrastructure:

| Method | Best For | Complexity |
|--------|----------|------------|
| **Docker Compose** | Small to medium deployments, VPS | Easy |
| **Kubernetes** | Large-scale, multi-instance, cloud-native | Advanced |
| **Traditional** | Shared hosting, bare metal, full control | Moderate |

---

## Docker Deployment

### Quick Start (Docker Compose)

```bash
# Clone repository
git clone https://github.com/your-org/2FA-Vault.git
cd 2FA-Vault

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Build and start
docker-compose up -d

# Run migrations
docker-compose exec app php artisan migrate

# Create admin user
docker-compose exec app php artisan tinker
>>> User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('your-password'), 'is_admin' => true]);
```

### Production Docker Compose

Create `docker-compose.prod.yml`:

```yaml
version: '3.8'

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile.prod
    restart: always
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./storage:/srv/storage
      - ./bootstrap/cache:/srv/bootstrap/cache
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - APP_URL=https://your-domain.com
      - DB_CONNECTION=mysql
      - DB_HOST=db
      - DB_DATABASE=2fa_vault
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
    networks:
      - 2fa-vault

  db:
    image: mysql:8.0
    restart: always
    volumes:
      - mysql-data:/var/lib/mysql
    environment:
      - MYSQL_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
      - MYSQL_DATABASE=2fa_vault
      - MYSQL_USER=${DB_USERNAME}
      - MYSQL_PASSWORD=${DB_PASSWORD}
    networks:
      - 2fa-vault

  redis:
    image: redis:7-alpine
    restart: always
    volumes:
      - redis-data:/data
    networks:
      - 2fa-vault

  worker:
    build:
      context: .
      dockerfile: Dockerfile.prod
    restart: always
    command: php artisan queue:work --sleep=3 --tries=3
    networks:
      - 2fa-vault
    depends_on:
      - redis

networks:
  2fa-vault:

volumes:
  mysql-data:
  redis-data:
```

### Production Dockerfile

```dockerfile
FROM php:8.4-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    freetype-dev \
    libjpeg-turbo-dev \
    webp-dev \
    oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp

# Install PHP extensions
RUN docker-php-ext-install \
    bcmath \
    gd \
    mbstring \
    pdo \
    pdo_mysql \
    zip \
    opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /srv

# Copy application files
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader
RUN npm install && npm run build

# Set permissions
RUN chown -R www-data:www-data /srv \
    && chmod -R 755 /srv/storage \
    && chmod -R 755 /srv/bootstrap/cache

# Copy PHP configuration
COPY docker/php/php.ini $PHP_INI_DIR/conf.d/custom.ini
COPY docker/php/opcache.ini $PHP_INI_DIR/conf.d/opcache.ini

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
```

---

## Traditional Server Deployment

### Ubuntu/Debian Setup

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install PHP 8.4 and extensions
sudo apt install -y \
    php8.4 \
    php8.4-fpm \
    php8.4-mysql \
    php8.4-sqlite3 \
    php8.4-gd \
    php8.4-mbstring \
    php8.4-xml \
    php8.4-curl \
    php8.4-zip \
    php8.4-bcmath \
    php8.4-intl \
    php8.4-redis \
    composer

# Install Nginx
sudo apt install -y nginx

# Install MySQL
sudo apt install -y mysql-server
sudo mysql_secure_installation

# Clone application
cd /var/www
sudo git clone https://github.com/your-org/2FA-Vault.git
cd 2FA-Vault

# Set permissions
sudo chown -R www-data:www-data /var/www/2FA-Vault
sudo chmod -R 755 /var/www/2FA-Vault/storage
sudo chmod -R 755 /var/www/2FA-Vault/bootstrap/cache

# Install dependencies
composer install --no-dev --optimize-autoloader

# Build frontend
npm install
npm run build

# Configure environment
cp .env.example .env
php artisan key:generate
nano .env  # Edit configuration

# Run migrations
php artisan migrate --force

# Create storage link
php artisan storage:link

# Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Nginx Configuration

Create `/etc/nginx/sites-available/2fa-vault`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name your-domain.com;

    # Redirect to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name your-domain.com;

    root /var/www/2FA-Vault/public;

    # SSL configuration
    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css text/xml application/json application/javascript;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable the site:
```bash
sudo ln -s /etc/nginx/sites-available/2fa-vault /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## Kubernetes Deployment

### Namespace and ConfigMap

```yaml
apiVersion: v1
kind: Namespace
metadata:
  name: 2fa-vault

---
apiVersion: v1
kind: ConfigMap
metadata:
  name: 2fa-vault-config
  namespace: 2fa-vault
data:
  APP_ENV: "production"
  APP_DEBUG: "false"
  APP_URL: "https://your-domain.com"
  DB_CONNECTION: "mysql"
  DB_HOST: "mysql-service"
  DB_DATABASE: "2fa_vault"
  CACHE_DRIVER: "redis"
  SESSION_DRIVER: "redis"
  QUEUE_CONNECTION: "redis"
  REDIS_HOST: "redis-service"
```

### Secret

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: 2fa-vault-secret
  namespace: 2fa-vault
type: Opaque
stringData:
  APP_KEY: "base64:your-app-key"
  DB_USERNAME: "2fa_vault"
  DB_PASSWORD: "your-db-password"
  REDIS_PASSWORD: "your-redis-password"
```

### Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: 2fa-vault
  namespace: 2fa-vault
spec:
  replicas: 2
  selector:
    matchLabels:
      app: 2fa-vault
  template:
    metadata:
      labels:
        app: 2fa-vault
    spec:
      containers:
      - name: app
        image: your-registry/2fa-vault:latest
        ports:
        - containerPort: 9000
        envFrom:
        - configMapRef:
            name: 2fa-vault-config
        - secretRef:
            name: 2fa-vault-secret
        volumeMounts:
        - name: storage
          mountPath: /srv/storage
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "512Mi"
            cpu: "500m"
        livenessProbe:
          httpGet:
            path: /up
            port: 9000
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /up
            port: 9000
          initialDelaySeconds: 5
          periodSeconds: 5
      volumes:
      - name: storage
        persistentVolumeClaim:
          claimName: 2fa-vault-storage
```

### Service and Ingress

```yaml
apiVersion: v1
kind: Service
metadata:
  name: 2fa-vault-service
  namespace: 2fa-vault
spec:
  selector:
    app: 2fa-vault
  ports:
  - port: 80
    targetPort: 9000
  type: ClusterIP

---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: 2fa-vault-ingress
  namespace: 2fa-vault
  annotations:
    cert-manager.io/cluster-issuer: "letsencrypt-prod"
    nginx.ingress.kubernetes.io/ssl-redirect: "true"
spec:
  tls:
  - hosts:
    - your-domain.com
    secretName: 2fa-vault-tls
  rules:
  - host: your-domain.com
    http:
      paths:
      - path: /
        pathType: Prefix
        backend:
          service:
            name: 2fa-vault-service
            port:
              number: 80
```

---

## Environment Configuration

### Production .env Settings

```bash
# Application
APP_NAME="2FA-Vault"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_KEY=base64:your-generated-key

# Database
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=2fa_vault
DB_USERNAME=2fa_vault
DB_PASSWORD=strong-password-here

# Cache & Session (use Redis in production)
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Mail (configure SMTP)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=postmaster@your-domain.com
MAIL_PASSWORD=your-mailgun-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@your-domain.com
MAIL_FROM_NAME="${APP_NAME}"

# Security
ENCRYPTION_ENABLED=true         # E2EE on by default
ARGON2_MEMORY=65536             # 64 MB
ARGON2_TIME=3                  # 3 iterations
ARGON2_THREADS=4               # 4 threads

# Rate Limiting
THROTTLE_API=60,1
THROTTLE_LOGIN=5,1

# WebAuthn
WEBAUTHN_NAME="2FA-Vault"
WEBAUTHN_ID=your-domain.com
WEBAUTHN_USER_VERIFICATION=required

# VAPID (generate with: npx web-push generate-vapid-keys)
VAPID_PUBLIC_KEY=your-public-key
VAPID_PRIVATE_KEY=your-private-key
VAPID_SUBJECT=mailto:admin@your-domain.com

# Logging
LOG_CHANNEL=daily
LOG_LEVEL=warning

# Multi-user
ALLOW_REGISTRATION=true        # Set false for private deployments
MAX_TEAMS_PER_USER=10
MAX_MEMBERS_PER_TEAM=50
```

---

## SSL/TLS Configuration

### Let's Encrypt with Certbot

```bash
# Install Certbot
sudo apt install certbot python3-certbot-nginx

# Obtain certificate
sudo certbot --nginx -d your-domain.com

# Auto-renewal (configured automatically)
sudo certbot renew --dry-run
```

### Manual Certificate Installation

```bash
# Place certificates
sudo cp your-cert.crt /etc/ssl/certs/2fa-vault.crt
sudo cp your-key.key /etc/ssl/private/2fa-vault.key

# Set permissions
sudo chmod 644 /etc/ssl/certs/2fa-vault.crt
sudo chmod 600 /etc/ssl/private/2fa-vault.key
```

Update Nginx config to use your certificates (see Traditional Server section).

---

## Database Setup

### MySQL

```sql
CREATE DATABASE 2fa_vault CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '2fa_vault'@'localhost' IDENTIFIED BY 'strong-password';
GRANT ALL PRIVILEGES ON 2fa_vault.* TO '2fa_vault'@'localhost';
FLUSH PRIVILEGES;
```

### PostgreSQL

```sql
CREATE DATABASE 2fa_vault;
CREATE USER 2fa_vault WITH PASSWORD 'strong-password';
GRANT ALL PRIVILEGES ON DATABASE 2fa_vault TO 2fa_vault;
```

### Run Migrations

```bash
php artisan migrate --force
```

---

## Production Checklist

### Pre-Deployment

- [ ] Generate secure `APP_KEY`
- [ ] Set `APP_DEBUG=false`
- [ ] Configure proper `APP_URL`
- [ ] Set up SSL/TLS certificates
- [ ] Configure database with strong password
- [ ] Set `ENCRYPTION_ENABLED=true`
- [ ] Configure SMTP for emails
- [ ] Generate VAPID keys for push notifications
- [ ] Set appropriate rate limits

### Post-Deployment

- [ ] Verify HTTPS is working
- [ ] Test user registration/login
- [ ] Test E2EE setup
- [ ] Test backup/restore
- [ ] Verify email delivery
- [ ] Check monitoring is active
- [ ] Test backup procedures
- [ ] Configure scheduled backups

### Security Hardening

- [ ] Enable firewall (ufw)
- [ ] Disable SSH password auth
- [ ] Install fail2ban
- [ ] Set up log rotation
- [ ] Configure automatic updates
- [ ] Use Redis for sessions/cache
- [ ] Enable database SSL
- [ ] Configure CSP headers

---

## Monitoring & Maintenance

### Health Checks

```bash
# Application health
curl https://your-domain.com/up

# Check disk space
df -h

# Check service status
systemctl status nginx php8.4-fpm mysql
```

### Log Monitoring

```bash
# Laravel logs
tail -f storage/logs/laravel.log

# Nginx logs
tail -f /var/log/nginx/access.log
tail -f /var/log/nginx/error.log

# PHP-FPM logs
tail -f /var/log/php8.4-fpm.log
```

### Scheduled Tasks

Add to crontab (`crontab -e`):

```bash
# Laravel scheduler (runs every minute)
* * * * * cd /var/www/2FA-Vault && php artisan schedule:run >> /dev/null 2>&1

# Daily database backup
0 2 * * * /usr/bin/mysqldump -u root -pPASSWORD 2fa_vault > /backups/db-$(date +\%Y\%m\%d).sql

# Weekly cleanup of old logs
0 3 * * 0 find /var/www/2FA-Vault/storage/logs -name "*.log" -mtime +30 -delete
```

### Performance Optimization

```bash
# Clear and cache config
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize autoloader
composer dump-autoload --optimize

# Reset opcache
php artisan opcache:clear
```

---

## Backup & Recovery

### Database Backup

```bash
# Automated backup script
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/backups"
DB_NAME="2fa_vault"
DB_USER="root"
DB_PASS="your-password"

mkdir -p $BACKUP_DIR
mysqldump -u $DB_USER -p$DB_PASS $DB_NAME | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Keep last 30 days
find $BACKUP_DIR -name "db_*.sql.gz" -mtime +30 -delete
```

### Application Backup

```bash
# Backup storage and config
tar -czf /backups/app_$(date +%Y%m%d).tar.gz \
    /var/www/2FA-Vault/storage \
    /var/www/2FA-Vault/.env \
    /var/www/2FA-Vault/public/uploads
```

### User Encrypted Backups

Users can export their own encrypted `.vault` backups via the UI. These are double-encrypted:
1. Client-side with user's backup password
2. AES-256-GCM encryption

---

## Troubleshooting

### Common Issues

**502 Bad Gateway**
```bash
# Check if PHP-FPM is running
sudo systemctl status php8.4-fpm

# Check Nginx error log
sudo tail -f /var/log/nginx/error.log
```

**Database Connection Failed**
```bash
# Check MySQL is running
sudo systemctl status mysql

# Test connection
mysql -u 2fa_vault -p -h localhost 2fa_vault
```

**Permission Denied on Storage**
```bash
sudo chown -R www-data:www-data /var/www/2FA-Vault/storage
sudo chmod -R 755 /var/www/2FA-Vault/storage
```

**Queue Jobs Not Processing**
```bash
# Check worker is running
ps aux | grep "queue:work"

# Restart worker
sudo systemctl restart 2fa-vault-worker
```

### Getting Help

- Check logs: `storage/logs/laravel.log`
- Enable debug mode temporarily (`APP_DEBUG=true`)
- Review [API Documentation](../reference/api-documentation.md)
- Check [Troubleshooting Guide](./troubleshooting.md)
- Open an issue on GitHub

---

## Update Procedure

```bash
# Backup current version
cp -r /var/www/2FA-Vault /var/www/2FA-Vault.backup

# Pull latest code
cd /var/www/2FA-Vault
git pull origin main

# Install dependencies
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
```

---

## Additional Resources

- [System Architecture](../architecture/system-architecture.md)
- [Security Guidelines](../development/security-guidelines.md)
- [API Documentation](../reference/api-documentation.md)
- [Contributing Guide](./contributing.md)
