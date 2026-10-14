import { spawn, execSync } from 'child_process';
import crypto from 'crypto';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

const dbPath = path.join(rootDir, 'database_test.sqlite');
const dbBakPath = path.join(rootDir, 'database_test.sqlite.bak');

const MERCURE_IMAGE = 'dunglas/mercure:v0.24.2';
const MERCURE_PORT = '8080';
const MERCURE_JWT_SECRET = 'change-me-32-char-secret-for-testing!!!';

let mercureContainerId = null;
let mercureAvailable = false;

// 1. Back up database_test.sqlite if it exists
let hasBackup = false;
if (fs.existsSync(dbPath)) {
  fs.copyFileSync(dbPath, dbBakPath);
  hasBackup = true;
  console.log('Backed up database_test.sqlite to database_test.sqlite.bak');
} else {
  console.log('No existing database_test.sqlite found to backup.');
}

function mercurePublisherToken() {
  const b64 = (value) => Buffer.from(JSON.stringify(value)).toString('base64url');
  const head = b64({ alg: 'HS256', typ: 'JWT' });
  const payload = b64({
    mercure: { publish: ['*'] },
    exp: Math.floor(Date.now() / 1000) + 60
  });
  const signature = crypto
    .createHmac('sha256', MERCURE_JWT_SECRET)
    .update(`${head}.${payload}`)
    .digest('base64url');
  return `${head}.${payload}.${signature}`;
}

// A hub is only usable if it is reachable *and* accepts our publisher token.
// Checking liveness alone would happily reuse a hub configured with a
// different JWT secret and then fail every realtime assertion, so probe both.
function probeMercure() {
  try {
    execSync(`curl -s -o /dev/null --max-time 3 http://localhost:${MERCURE_PORT}/healthz`, {
      timeout: 5000
    });
  } catch {
    return false;
  }

  try {
    const status = execSync(
      `curl -s -o /dev/null -w "%{http_code}" --max-time 3 -X POST ` +
        `-H "Authorization: Bearer ${mercurePublisherToken()}" ` +
        `-d "topic=e2e-probe&data=1" ` +
        `http://localhost:${MERCURE_PORT}/.well-known/mercure`,
      { timeout: 5000, encoding: 'utf8' }
    ).toString().trim();
    return status.startsWith('2');
  } catch {
    return false;
  }
}

function startMercure() {
  try {
    const cmd = `docker run -d --rm \
      -p ${MERCURE_PORT}:80 \
      -e MERCURE_PUBLISHER_JWT_KEY=${MERCURE_JWT_SECRET} \
      -e MERCURE_SUBSCRIBER_JWT_KEY=${MERCURE_JWT_SECRET} \
      -e SERVER_NAME=:80 \
      -e 'CADDY_SERVER_EXTRA_DIRECTIVES=header /.well-known/mercure Access-Control-Allow-Origin "*"
header /.well-known/mercure Access-Control-Allow-Headers "Authorization, Content-Type"' \
      ${MERCURE_IMAGE}`;
    const out = execSync(cmd, { timeout: 30000 }).toString().trim();
    mercureContainerId = out;
    console.log(`Mercure Hub started (container: ${mercureContainerId})`);
  } catch (e) {
    console.warn('Could not start Mercure Hub container:', e.message);
    console.warn('Continuing without Mercure — tests relying on real-time updates may fail.');
  }
}

function waitForMercure(retries = 15, delay = 2000) {
  for (let i = 0; i < retries; i++) {
    if (probeMercure()) {
      console.log('Mercure Hub is healthy.');
      return true;
    }
    console.log(`Waiting for Mercure Hub (attempt ${i + 1}/${retries})...`);
    execSync(`sleep ${Math.ceil(delay / 1000)}`);
  }
  console.warn('Mercure Hub did not become healthy in time.');
  return false;
}

function stopMercure() {
  if (mercureContainerId) {
    try {
      execSync(`docker stop ${mercureContainerId}`, { timeout: 10000 });
      console.log('Mercure Hub stopped');
    } catch (e) {
      console.warn('Failed to stop Mercure container:', e.message);
    }
    mercureContainerId = null;
  }
}

function cleanup() {
  stopMercure();
  if (hasBackup && fs.existsSync(dbBakPath)) {
    fs.copyFileSync(dbBakPath, dbPath);
    fs.unlinkSync(dbBakPath);
    console.log('Restored database_test.sqlite from backup');
  } else if (!hasBackup && fs.existsSync(dbPath)) {
    fs.unlinkSync(dbPath);
    console.log('Cleaned up temporary database_test.sqlite');
  }
}

// Start Mercure Hub, reusing one that is already running. A developer usually
// has the docker-compose stack up, and that hub is configured with the same
// JWT secret, so skipping the realtime tests just because we did not start it
// ourselves throws away coverage for no reason.
if (probeMercure()) {
  mercureAvailable = true;
  console.log(`Reusing the Mercure Hub already listening on port ${MERCURE_PORT}.`);
} else {
  startMercure();
  if (mercureContainerId) {
    mercureAvailable = waitForMercure();
  }
}

// Register cleanup events
let cleanedUp = false;
const handleExit = () => {
  if (!cleanedUp) {
    cleanup();
    cleanedUp = true;
  }
};

process.on('exit', handleExit);
process.on('SIGINT', () => process.exit());
process.on('SIGTERM', () => process.exit());
process.on('uncaughtException', (err) => {
  console.error('Uncaught exception in E2E runner:', err);
  process.exit(1);
});

// Run Playwright tests
console.log('Launching Playwright tests...');
const playwright = spawn('npx', ['playwright', 'test', ...process.argv.slice(2)], {
  cwd: __dirname,
  stdio: 'inherit',
  env: {
    ...process.env,
    MERCURE_HUB_RUNNING: mercureAvailable ? 'true' : ''
  }
});

playwright.on('close', (pwCode) => {
  try {
    const out = execSync('sqlite3 ' + dbPath + " 'SELECT data FROM schedules;'").toString();
    console.log('DEBUG: Final DB schedule data:', out);
  } catch (e) {
    console.error('Failed to query final DB state:', e.message);
  }
  process.exit(pwCode);
});
