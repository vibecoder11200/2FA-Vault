import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DB_PATH = path.resolve(__dirname, '../../../database/database_e2e.sqlite');

/**
 * E2E global teardown - cleans up the test database.
 */
export default async function globalTeardown() {
  if (fs.existsSync(DB_PATH)) {
    fs.unlinkSync(DB_PATH);
  }
  console.log('[E2E] Teardown complete.');
}
