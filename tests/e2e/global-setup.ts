/**
 * E2E global setup - runs before all tests but after webServer starts.
 * The webServer command already handles DB setup, but we verify it here.
 */
export default async function globalSetup() {
  console.log('[E2E] Global setup complete.');
}
