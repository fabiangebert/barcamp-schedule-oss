import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

function isMercureRunning() {
  if (process.env.MERCURE_HUB_RUNNING === 'true') return true;
  return false;
}

export default async function () {
  const dbPath = path.resolve(rootDir, '../database_test.sqlite');
  const seedScript = path.resolve(rootDir, '../tests/seed_e2e.php');

  // Remove test DB to start clean
  if (fs.existsSync(dbPath)) {
    fs.unlinkSync(dbPath);
  }

  const mercureRunning = isMercureRunning();

  const envVars = [
    `DB_PATH=${dbPath}`,
    `JWT_SECRET=put-at-least-32-characters-here-1234567890`,
  ];

  if (mercureRunning) {
    envVars.push('MERCURE_URL=http://localhost:8080/.well-known/mercure');
    envVars.push('MERCURE_JWT_SECRET=change-me-32-char-secret-for-testing!!!');
    console.log('Mercure hub detected — seeding with Mercure push enabled.');
  } else {
    console.log('No Mercure hub detected — seeding without real-time updates.');
  }

  execSync(
    envVars.join(' ') + ` php ${seedScript}`,
    { cwd: path.resolve(rootDir, '..'), timeout: 15000, stdio: 'inherit' }
  );

  console.log('Global setup: E2E database seeded.');
}
