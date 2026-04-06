import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DB_PATH = path.resolve(__dirname, '../../../database/database_e2e.sqlite');

/**
 * Run an artisan command targeting the .env.e2e configuration.
 */
export function artisan(command: string): void {
  execSync(`php artisan ${command} --env=e2e`, {
    cwd: path.resolve(__dirname, '../../..'),
    stdio: 'pipe',
    timeout: 60000,
  });
}

export function setupDatabase(): void {
  if (fs.existsSync(DB_PATH)) {
    fs.unlinkSync(DB_PATH);
  }
  // SQLite requires the file to exist before migrations
  fs.writeFileSync(DB_PATH, '');
  artisan('migrate:fresh --force');
  artisan('db:seed --class=E2eSeeder --force');
}

export function teardownDatabase(): void {
  if (fs.existsSync(DB_PATH)) {
    fs.unlinkSync(DB_PATH);
  }
}
