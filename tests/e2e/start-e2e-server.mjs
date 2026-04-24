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
const IS_CI = process.env.CI === 'true' || process.env.CI === '1';
const E2E_ORIGIN = 'http://127.0.0.1:8001';
const E2E_ENV = {
  ...process.env,
  APP_ENV: 'e2e',
  APP_URL: E2E_ORIGIN,
  ASSET_URL: E2E_ORIGIN,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: DB_PATH,
  // Pin session + CSP defaults explicitly. `php artisan serve` spawns a
  // fresh PHP worker for every request; relying solely on .env.e2e being
  // picked up has proven flaky in CI, so we also surface critical values
  // through the process environment.
  SESSION_DRIVER: 'file',
  SESSION_SECURE_COOKIE: 'false',
  SESSION_SAME_SITE: 'lax',
  SESSION_DOMAIN: '',
  CACHE_DRIVER: 'array',
  CONTENT_SECURITY_POLICY: 'false',
};

function runCommand(command, label, options = {}) {
  try {
    return execSync(command, {
      cwd: ROOT,
      env: E2E_ENV,
      stdio: options.stdio ?? 'pipe',
      timeout: options.timeout,
    });
  } catch (error) {
    const stdout = error.stdout?.toString?.() ?? '';
    const stderr = error.stderr?.toString?.() ?? '';
    console.error(`[E2E Server] ${label} failed.`);
    if (stdout) console.error(`[E2E Server] ${label} stdout:\n${stdout}`);
    if (stderr) console.error(`[E2E Server] ${label} stderr:\n${stderr}`);
    throw error;
  }
}

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
runCommand('php artisan config:clear', 'config:clear');
runCommand('php artisan route:clear', 'route:clear');
runCommand('php artisan migrate:fresh --env=e2e --force', 'migrate:fresh');
console.log('[E2E Server] Seeding database...');
runCommand('php artisan db:seed --class=E2eSeeder --env=e2e --force', 'db:seed');

// Build frontend deterministically in CI, otherwise reuse existing build if present.
const buildManifest = path.resolve(ROOT, 'public/build/manifest.json');
if (IS_CI || !fs.existsSync(buildManifest)) {
  if (IS_CI) {
    console.log('[E2E Server] CI detected - rebuilding frontend assets for fresh E2E runtime...');
  } else {
    console.log('[E2E Server] Building frontend assets...');
  }
  runCommand('npm run build', 'npm run build', { timeout: 120000 });
} else {
  console.log('[E2E Server] Using existing build assets.');
}

const manifestJson = JSON.parse(fs.readFileSync(buildManifest, 'utf8'));
const appEntry = manifestJson['resources/js/app.js'];

if (!appEntry?.file) {
  throw new Error('[E2E Server] Missing Vite manifest entry for resources/js/app.js');
}

const appEntryPath = path.resolve(ROOT, 'public/build', appEntry.file);
if (!fs.existsSync(appEntryPath)) {
  throw new Error(`[E2E Server] Missing built app entry: ${appEntryPath}`);
}

console.log(`[E2E Server] Verified built app entry: ${appEntry.file}`);

console.log('[E2E Server] Starting Laravel on port 8001...');

// Start the Laravel server (blocking)
const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8001', '--env=e2e'], {
  cwd: ROOT,
  env: E2E_ENV,
  stdio: 'inherit',
});

server.on('error', (err) => {
  console.error('[E2E Server] Failed to start:', err);
  process.exit(1);
});

process.on('SIGTERM', () => { server.kill('SIGTERM'); process.exit(0); });
process.on('SIGINT', () => { server.kill('SIGINT'); process.exit(0); });
