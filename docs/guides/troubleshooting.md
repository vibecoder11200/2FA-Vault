# 2FA-Vault Troubleshooting Guide

Common issues and solutions for 2FA-Vault users and administrators.

---

## Table of Contents

1. [Installation Issues](#installation-issues)
2. [Encryption Problems](#encryption-problems)
3. [Synchronization Issues](#synchronization-issues)
4. [Browser Extension Issues](#browser-extension-issues)
5. [OTP Code Problems](#otp-code-problems)
6. [Backup & Restore Issues](#backup--restore-issues)
7. [Performance Issues](#performance-issues)
8. [Team & Sharing Issues](#team--sharing-issues)
9. [PWA Issues](#pwa-issues)
10. [Server-Side Issues](#server-side-issues)

---

## Installation Issues

### Docker: Container Won't Start

**Symptoms:**
- `docker-compose up` fails
- Containers exit immediately
- Port conflicts

**Solutions:**

1. **Check port conflicts:**
   ```bash
   # Check what's using the ports
   netstat -ano | findstr :8000
   # Kill conflicting processes or change ports in docker-compose.yml
   ```

2. **Rebuild containers:**
   ```bash
   docker-compose down
   docker-compose build --no-cache
   docker-compose up -d
   ```

3. **Check logs:**
   ```bash
   docker-compose logs app
   docker-compose logs vite
   ```

### Migration Fails

**Symptoms:**
- `php artisan migrate` fails
- Table already exists errors
- Foreign key constraints

**Solutions:**

1. **Fresh install:**
   ```bash
   php artisan migrate:fresh
   ```

2. **Rollback and retry:**
   ```bash
   php artisan migrate:rollback
   php artisan migrate
   ```

3. **Force migrate (production):**
   ```bash
   php artisan migrate --force
   ```

### Composer/NPM Install Fails

**Symptoms:**
- Dependency conflicts
- Out of memory errors
- Network timeouts

**Solutions:**

1. **Increase memory limit:**
   ```bash
   COMPOSER_MEMORY_LIMIT=-1 composer install
   ```

2. **Clear cache and retry:**
   ```bash
   composer clear-cache
   rm -rf vendor node_modules
   composer install
   npm install
   ```

3. **Use alternative registry:**
   ```bash
   npm install --registry=https://registry.npmjs.org/
   ```

---

## Encryption Problems

### Forgot Master Password

**Symptoms:**
- Cannot unlock vault
- "Verification failed" message

**Important:** There is **no recovery** for a forgotten master password. This is by design for zero-knowledge security.

**Solutions:**

1. **Disable E2EE:**
   - Go to Settings → Security → Encryption
   - Click "Disable Encryption"
   - Enter your account password
   - **This decrypts all accounts and removes encryption**

2. **Re-enable E2EE with new password:**
   - Enable encryption again
   - Create a new master password
   - Write down your password hint this time!

### Encryption Setup Fails

**Symptoms:**
- "Setup failed" error
- Browser console errors

**Solutions:**

1. **Check browser compatibility:**
   - Must support Web Crypto API
   - Update to latest browser version
   - Try a different browser

2. **Clear browser data:**
   - Clear cache and cookies
   - Try again in incognito/private mode

3. **Check error logs:**
   ```bash
   # Check server logs
   tail -f storage/logs/laravel.log
   ```

### Vault Won't Unlock

**Symptoms:**
- Correct password shows as incorrect
- "Verification failed" on unlock

**Solutions:**

1. **Verify password carefully:**
   - Check for typos
   - Caps lock may be on
   - Try password hint if you set one

2. **Clear browser cache:**
   - Old session data may cause issues
   - Log out and log back in

3. **Check server time:**
   - Server and client time mismatch can cause issues
   - Ensure both are synchronized

---

## Synchronization Issues

### Changes Not Syncing Across Devices

**Symptoms:**
- Changes on one device don't appear on others
- Delayed updates

**Solutions:**

1. **Check connection:**
   - Ensure you're online
   - Check if server is reachable

2. **Manual refresh:**
   - Refresh the page (F5)
   - Or click the sync button if available

3. **Clear browser cache:**
   - Stale data may be cached
   - Clear cache and reload

4. **Check for conflicts:**
   - Multiple simultaneous edits can cause conflicts
   - Last edit wins

### Web Push Notifications Not Working

**Symptoms:**
- No notifications received
- Permission denied

**Solutions:**

1. **Check notification permissions:**
   - Browser settings → Site Settings → Notifications
   - Ensure notifications are allowed for your domain

2. **Re-subscribe:**
   - Go to Settings → Notifications
   - Click "Reset Subscription"
   - Re-enable notifications

3. **Check VAPID keys:**
   - Server must have valid VAPID keys configured
   - Check `.env` for `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`

---

## Browser Extension Issues

### Extension Not Connecting

**Symptoms:**
- "Cannot connect to server" error
- Spinning loader

**Solutions:**

1. **Check server URL:**
   - Click extension icon → Settings
   - Verify server URL is correct
   - Include `https://` and port if needed

2. **Check CORS:**
   - Server must allow extension origin
   - Check server logs for CORS errors

3. **Re-authenticate:**
   - Log out of extension
   - Log back in with your credentials

### Codes Not Auto-Filling

**Symptoms:**
- Extension icon doesn't highlight
- Manual copy required

**Solutions:**

1. **Check page permissions:**
   - Refresh the login page
   - Grant all permissions when prompted

2. **Reinstall extension:**
   - Uninstall and reinstall the extension
   - Reconfigure server URL

3. **Check account matching:**
   - Extension matches on domain
   - Ensure account service matches the website

---

## OTP Code Problems

### Codes Are Incorrect

**Symptoms:**
- Website rejects OTP code
- Codes don't work

**Solutions:**

1. **Check device time:**
   - TOTP codes depend on accurate time
   - Ensure your device clock is synchronized
   - Enable automatic time sync

2. **Verify account settings:**
   - Check secret key is correct
   - Verify algorithm (SHA1, SHA256, etc.)
   - Check digits (usually 6) and period (usually 30)

3. **Regenerate secret:**
   - Some services allow regenerating the QR code
   - Remove old account and add new one

### HOTP Counter Out of Sync

**Symptoms:**
- HOTP codes never work
- "Counter mismatch" errors

**Solutions:**

1. **Manually increment counter:**
   - Edit the account
   - Increment the counter value
   - Save and try again

2. **Resync with service:**
   - Some services allow counter resynchronization
   - Check service documentation

---

## Backup & Restore Issues

### Backup File Won't Open

**Symptoms:**
- "Invalid backup file" error
- "Wrong password" on correct password

**Solutions:**

1. **Verify file integrity:**
   - Check file size (should be > 1KB)
   - Re-download if from cloud storage

2. **Check password:**
   - Backup password is case-sensitive
   - Try password hint if you set one
   - **No recovery for lost backup password**

3. **Try older backup:**
   - File may be corrupted
   - Use a previous backup version

### Import Fails Partway

**Symptoms:**
- Some accounts import, others fail
- Error messages during import

**Solutions:**

1. **Check error details:**
   - Review error messages for specific issues
   - May be invalid secrets or missing fields

2. **Import in batches:**
   - Split backup into smaller files
   - Import accounts individually

3. **Use merge mode:**
   - Choose "Merge" instead of "Replace"
   - Adds valid accounts, skips invalid ones

---

## Performance Issues

### Slow Loading

**Symptoms:**
- Page takes > 3 seconds to load
- UI is sluggish

**Solutions:**

1. **Clear cache:**
   ```bash
   php artisan cache:clear
   php artisan view:clear
   php artisan config:clear
   ```

2. **Optimize database:**
   ```bash
   php artisan migrate:optimize
   ```

3. **Check server resources:**
   - CPU, memory, disk usage
   - Upgrade if necessary

4. **Enable Redis:**
   - Switch from file cache to Redis
   - Improves response times

### High Memory Usage

**Symptoms:**
- Container crashes with OOM
- Slow performance

**Solutions:**

1. **Increase memory limit:**
   ```bash
   # In .env or php.ini
   MEMORY_LIMIT=256M
   ```

2. **Optimize composer:**
   ```bash
   composer dump-autoload --optimize
   ```

3. **Check for memory leaks:**
   - Monitor with `docker stats`
   - Identify problematic services

---

## Team & Sharing Issues

### Can't Invite Members

**Symptoms:**
- Invite not sent
- "Max members reached" error

**Solutions:**

1. **Check team limits:**
   - Free tier: 10 teams, 50 members per team
   - Admin can increase limits in config

2. **Verify email:**
   - Must be valid email address
   - Check email service is configured

3. **Check permissions:**
   - Must be Owner or Admin to invite
   - Members cannot invite others

### Invitation Email Not Received

**Symptoms:**
- Invitee doesn't get email
- Email goes to spam

**Solutions:**

1. **Check spam folder:**
   - May be filtered as spam

2. **Verify mail settings:**
   - Check SMTP configuration in `.env`
   - Test with `php artisan tinker` → `Mail::raw('Test', fn($msg) => $msg->to('test@example.com'))->send()`

3. **Re-send invitation:**
   - Delete and recreate the invitation
   - Or share invite code directly

---

## PWA Issues

### Can't Install PWA

**Symptoms:**
- No install prompt appears
- "Add to Home Screen" missing

**Solutions:**

1. **Check browser support:**
   - Must use Chrome, Edge, or Safari
   - Update browser to latest version

2. **Serve over HTTPS:**
   - PWA requires HTTPS (or localhost)
   - Set up SSL certificate

3. **Check manifest:**
   - Verify `manifest.json` is accessible
   - Check for errors in browser console

### Offline Mode Not Working

**Symptoms:**
- Offline shows "No Internet"
- Cached pages not available

**Solutions:**

1. **Check service worker:**
   - Open DevTools → Application → Service Workers
   - Ensure service worker is active

2. **Clear cache and re-register:**
   - Unregister service worker
   - Refresh page to re-register

3. **Check storage quota:**
   - Browser may have reached storage limit
   - Clear site data and try again

---

## Server-Side Issues

### 500 Internal Server Error

**Symptoms:**
- Generic error page
- No specific error message

**Solutions:**

1. **Check Laravel logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. **Enable debug mode temporarily:**
   ```bash
   # In .env
   APP_DEBUG=true
   ```
   - **Remember to turn off in production!**

3. **Check permissions:**
   ```bash
   chmod -R 755 storage bootstrap/cache
   chown -R www-data:www-data storage bootstrap/cache
   ```

### Database Connection Failed

**Symptoms:**
- "SQLSTATE[HY000] [2002] Connection refused"
- Can't connect to database

**Solutions:**

1. **Check database is running:**
   ```bash
   systemctl status mysql
   # or
   docker ps | grep mysql
   ```

2. **Verify credentials:**
   - Check `.env` DB settings
   - Ensure username, password, and host are correct

3. **Test connection:**
   ```bash
   mysql -u username -p -h localhost database_name
   ```

### Queue Jobs Not Processing

**Symptoms:**
- Jobs pile up in queue
- Background tasks don't complete

**Solutions:**

1. **Start queue worker:**
   ```bash
   php artisan queue:work
   ```

2. **Check for failed jobs:**
   ```bash
   php artisan queue:failed
   php artisan queue:retry all
   ```

3. **Monitor queue:**
   ```bash
   php artisan queue:listen
   ```

---

## Getting Additional Help

If you can't resolve your issue:

1. **Check the logs:**
   - `storage/logs/laravel.log`
   - Browser console (F12)
   - Docker logs: `docker-compose logs -f`

2. **Search existing issues:**
   - [GitHub Issues](https://github.com/your-org/2FA-Vault/issues)

3. **Create a new issue:**
   - Include your 2FA-Vault version
   - Describe the problem in detail
   - Include error messages and logs
   - Mention your environment (OS, browser, etc.)

4. **Community support:**
   - [GitHub Discussions](https://github.com/your-org/2FA-Vault/discussions)

---

**Version:** 1.0.0
**Last Updated:** April 2026
