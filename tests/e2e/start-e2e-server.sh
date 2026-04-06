#!/usr/bin/env node
/**
 * E2E server starter script.
 * Sets up the database, then starts php artisan serve.
 * Playwright's webServer config uses this to ensure DB is ready before the server starts.
 */
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const DB_PATH = path.resolve(__dirname, '../../database/database_e2e.sqlite');

// Ensure database file exists
if (!fs.existsSync(DB_PATH)) {
  fs.writeFileSync(DB_PATH, '');
}

// Run migrations and seed
console.log('[E2E Server] Running migrations...');
execSync('php artisan migrate:fresh --env=e2e --force', { stdio: 'pipe' });
console.log('[E2E Server] Seeding database...');
execSync('php artisan db:seed --class=E2eSeeder --env=e2e --force', { stdio: 'pipe' });
console.log('[E2E Server] Database ready.');

// Start the server (this blocks)
const { spawn } = require('child_process');
const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8001', '--env=e2e'], {
  stdio: 'inherit',
});

server.on('error', (err) => {
  console.error('[E2E Server] Failed to start:', err);
  process.exit(1);
});

// Keep running
process.on('SIGTERM', () => server.kill('SIGTERM'));
process.on('SIGINT', () => server.kill('SIGINT'));
