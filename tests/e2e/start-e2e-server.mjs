/**
 * E2E server starter: builds frontend, sets up DB, starts php artisan serve.
 * Serves built assets via Laravel (no Vite dev server needed).
 */
import { execSync, spawn } from 'child_process';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '../..');
const DB_PATH = path.resolve(ROOT, 'database/database_e2e.sqlite');

// Remove stale Vite hot file (forces @vite() to use production build)
const hotFile = path.resolve(ROOT, 'public/hot');
if (fs.existsSync(hotFile)) {
  fs.unlinkSync(hotFile);
  console.log('[E2E Server] Removed stale Vite hot file.');
}

// Ensure database file exists
if (!fs.existsSync(DB_PATH)) {
  fs.writeFileSync(DB_PATH, '');
}

// Run migrations and seed
console.log('[E2E Server] Running migrations...');
execSync('php artisan migrate:fresh --env=e2e --force', { stdio: 'pipe', cwd: ROOT });
console.log('[E2E Server] Seeding database...');
execSync('php artisan db:seed --class=E2eSeeder --env=e2e --force', { stdio: 'pipe', cwd: ROOT });

// Build frontend if no build exists
const buildManifest = path.resolve(ROOT, 'public/build/manifest.json');
if (!fs.existsSync(buildManifest)) {
  console.log('[E2E Server] Building frontend assets...');
  execSync('npm run build', { stdio: 'pipe', cwd: ROOT, timeout: 120000 });
} else {
  console.log('[E2E Server] Using existing build assets.');
}

console.log('[E2E Server] Starting Laravel on port 8001...');

// Start the Laravel server (blocking)
const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8001', '--env=e2e'], {
  cwd: ROOT,
  stdio: 'inherit',
});

server.on('error', (err) => {
  console.error('[E2E Server] Failed to start:', err);
  process.exit(1);
});

process.on('SIGTERM', () => { server.kill('SIGTERM'); process.exit(0); });
process.on('SIGINT', () => { server.kill('SIGINT'); process.exit(0); });
