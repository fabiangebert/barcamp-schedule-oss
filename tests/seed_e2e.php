<?php

/**
 * E2E test seed script — driver-agnostic migration + data seeding.
 *
 * Usage:
 *   php tests/seed_e2e.php
 *
 * Reads DB config from environment variables (DB_DRIVER, DB_HOST, DB_NAME, etc.).
 * Falls back to SQLite with database_test.sqlite when no driver is configured.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Auth;
use App\Database;
use App\Schedule;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;

// ─── Set defaults ───────────────────────────────────────────────────────────

if (!getenv('JWT_SECRET')) {
    putenv('JWT_SECRET=super-secret-key-that-is-at-least-32-characters-long');
    $_ENV['JWT_SECRET'] = 'super-secret-key-that-is-at-least-32-characters-long';
}

// Default to SQLite if no driver is configured
if (!getenv('DB_DRIVER') && !getenv('DB_PATH')) {
    $dbPath = dirname(__DIR__) . '/database_test.sqlite';
    putenv("DB_PATH=$dbPath");
    $_ENV['DB_PATH'] = $dbPath;
}

if (!getenv('ADMIN_EMAIL')) {
    putenv('ADMIN_EMAIL=admin@example.com');
    $_ENV['ADMIN_EMAIL'] = 'admin@example.com';
}

// ─── Connect & migrate ──────────────────────────────────────────────────────

Database::setConnection(null);
$conn = Database::getConnection();
echo "Connected using driver: " . (getenv('DB_DRIVER') ?: 'pdo_sqlite') . "\n";

$configArray = require __DIR__ . '/../migrations.php';
$config = new ConfigurationArray($configArray);
$dependencyFactory = DependencyFactory::fromConnection(
    $config,
    new ExistingConnection($conn)
);
$dependencyFactory->getMetadataStorage()->ensureInitialized();
$migrator = $dependencyFactory->getMigrator();
$planCalculator = $dependencyFactory->getMigrationPlanCalculator();
$statusCalculator = $dependencyFactory->getMigrationStatusCalculator();
$newMigrations = $statusCalculator->getNewMigrations();
if (count($newMigrations) > 0) {
    $versions = array_map(fn($m) => $m->getVersion(), $newMigrations->getItems());
    $plan = $planCalculator->getPlanForVersions($versions, 'up');
    $migrator->migrate($plan, new MigratorConfiguration());
}
echo "Migrations complete.\n";

// ─── Seed users ─────────────────────────────────────────────────────────────

$adminEmail = 'admin@example.com';
$admin = $conn->fetchAssociative('SELECT id FROM users WHERE email = ?', [$adminEmail]);
if (!$admin) {
    $conn->insert('users', [
        'email' => $adminEmail,
        'role' => 'admin',
        'name' => 'E2E Admin',
    ]);
    $adminId = (int) $conn->lastInsertId();
} else {
    $adminId = (int) $admin['id'];
}

$userEmail = 'user@example.com';
$user = $conn->fetchAssociative('SELECT id FROM users WHERE email = ?', [$userEmail]);
if (!$user) {
    $conn->insert('users', [
        'email' => $userEmail,
        'role' => 'user',
        'name' => 'E2E User',
    ]);
    $userId = (int) $conn->lastInsertId();
} else {
    $userId = (int) $user['id'];
}

// ─── Seed schedule ─────────────────────────────────────────────────────────

$conn->executeStatement('DELETE FROM schedules');

$data = [
    'rooms' => [
        ['name' => 'Plenary', 'size' => 'L'],
        ['name' => 'Room A', 'size' => 'M'],
    ],
    'timeslots' => [
        ['name' => '10:00'],
        ['name' => '11:00'],
    ],
    'sessions' => [
        [
            'timeslot' => '10:00',
            'room' => 'Plenary',
            'title' => 'Opening Session',
            'speakers' => 'Organizers',
            'format' => 'Talk',
            'topic' => 'Tech',
        ],
        [
            'timeslot' => '11:00',
            'room' => 'Room A',
            'title' => 'Another Session',
            'speakers' => 'Speakers',
            'format' => 'Workshop',
            'topic' => 'Design',
        ],
    ],
    'topics' => [
        ['name' => 'Tech', 'colorIndex' => 1],
        ['name' => 'Design', 'colorIndex' => 2],
    ],
    'feedback_questions' => [
        [
            'id' => 'q1',
            'question' => 'How likely are you to recommend this event?',
            'type' => 'rating',
        ],
        [
            'id' => 'q2',
            'question' => 'General Feedback',
            'type' => 'text',
        ],
        [
            'id' => 'q3',
            'question' => 'Which session was your favorite?',
            'type' => 'multiple_choice',
            'options' => ['Opening Keynote', 'Workshop', 'Panel Discussion'],
        ],
    ],
    'ask_for_feedback' => true,
];

$hashedId = Schedule::create('E2E Test Schedule', $data, '2026-07-04');

// ─── Generate JWT tokens ───────────────────────────────────────────────────

$adminToken = Auth::generateJWT($adminEmail, 'admin', 'E2E Admin', 86400, $adminId);
$userToken = Auth::generateJWT($userEmail, 'user', 'E2E User', 86400, $userId);
$expiredToken = Auth::generateJWT($adminEmail, 'admin', 'E2E Admin', -3600, $adminId);
$guestToken = Auth::generateJWT('guest@example.com', 'guest', 'E2E Guest', 86400, 999);

$config = [
    'scheduleId' => $hashedId,
    'adminToken' => $adminToken,
    'userToken' => $userToken,
    'expiredToken' => $expiredToken,
    'guestToken' => $guestToken,
];

file_put_contents(
    __DIR__ . '/../frontend/e2e-config.json',
    json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo "Seeding completed successfully! e2e-config.json written.\n";
