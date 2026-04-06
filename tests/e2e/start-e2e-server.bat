@echo off
cd /d "%~dp0..\..\"

if not exist "database\database_e2e.sqlite" type nul > "database\database_e2e.sqlite"

echo [E2E] Running migrations...
php artisan migrate:fresh --env=e2e --force
echo [E2E] Seeding database...
php artisan db:seed --class=E2eSeeder --env=e2e --force
echo [E2E] Starting server on port 8001...
php artisan serve --host=127.0.0.1 --port=8001 --env=e2e
