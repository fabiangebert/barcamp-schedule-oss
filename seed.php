#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Unified database seed script — works with SQLite, MySQL, and PostgreSQL.
 *
 * Usage:
 *   php seed.php [--e2e]
 *
 * Reads DB config from environment variables (DB_DRIVER, DB_HOST, DB_NAME, etc.).
 * Runs all pending migrations, then seeds demo data.
 *
 * With --e2e, also writes frontend/e2e-config.json with JWT tokens for E2E tests.
 */

require __DIR__ . '/vendor/autoload.php';

use App\Auth;
use App\Database;
use App\Schedule;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;

// ─── Parse arguments ─────────────────────────────────────────────────────────

$isE2e = in_array('--e2e', $argv, true);

// ─── Ensure defaults for common env vars ────────────────────────────────────

if (!getenv('JWT_SECRET')) {
    putenv('JWT_SECRET=super-secret-key-that-is-at-least-32-characters-long');
    $_ENV['JWT_SECRET'] = 'super-secret-key-that-is-at-least-32-characters-long';
}

if (!getenv('ADMIN_EMAIL')) {
    putenv('ADMIN_EMAIL=admin@example.com');
    $_ENV['ADMIN_EMAIL'] = 'admin@example.com';
}

// ─── Get connection ─────────────────────────────────────────────────────────

Database::setConnection(null);
$conn = Database::getConnection();
echo "Connected using driver: " . (getenv('DB_DRIVER') ?: 'pdo_sqlite') . "\n";

// ─── Run migrations ─────────────────────────────────────────────────────────

echo "Running database migrations...\n";
$configArray = require __DIR__ . '/migrations.php';
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

// ─── Seed Admin User ────────────────────────────────────────────────────────

$adminEmail = getenv('ADMIN_EMAIL');
$admin = $conn->fetchAssociative('SELECT id, role FROM users WHERE email = ?', [$adminEmail]);
if (!$admin) {
    $conn->insert('users', [
        'email' => $adminEmail,
        'role' => 'admin',
        'name' => 'Admin',
    ]);
    $adminId = (int) $conn->lastInsertId();
    echo "Created admin user: $adminEmail\n";
} else {
    $adminId = (int) $admin['id'];
    echo "Admin user already exists: $adminEmail\n";
}

// ─── Seed Standard User ─────────────────────────────────────────────────────

$userEmail = 'user@example.com';
$user = $conn->fetchAssociative('SELECT id FROM users WHERE email = ?', [$userEmail]);
if (!$user) {
    $conn->insert('users', [
        'email' => $userEmail,
        'role' => 'user',
        'name' => 'Standard User',
    ]);
    $userId = (int) $conn->lastInsertId();
    echo "Created user: $userEmail\n";
} else {
    $userId = (int) $user['id'];
    echo "User already exists: $userEmail\n";
}

// ─── Seed Demo Schedule ─────────────────────────────────────────────────────

$scheduleData = [
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
            'title' => 'Workshop: Getting Started',
            'speakers' => 'Facilitator',
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
    ],
    'ask_for_feedback' => true,
];

$existing = $conn->fetchAssociative('SELECT id, hashed_id FROM schedules WHERE name = ?', ['Demo Schedule']);
if (!$existing) {
    $hashedId = Schedule::create('Demo Schedule', $scheduleData, date('Y-m-d'));
    echo "Created demo schedule (ID: $hashedId)\n";
} else {
    $hashedId = $existing['hashed_id'];
    echo "Demo schedule already exists (ID: $hashedId)\n";
}

// ─── E2E: Write token config ────────────────────────────────────────────────

if ($isE2e) {
    $adminToken = Auth::generateJWT($adminEmail, 'admin', 'Admin', 86400, $adminId);
    $userToken = Auth::generateJWT($userEmail, 'user', 'Standard User', 86400, $userId);
    $expiredToken = Auth::generateJWT($adminEmail, 'admin', 'Admin', -3600, $adminId);
    $guestToken = Auth::generateJWT('guest@example.com', 'guest', 'Guest', 86400, 999);

    $config = [
        'scheduleId' => $hashedId,
        'adminToken' => $adminToken,
        'userToken' => $userToken,
        'expiredToken' => $expiredToken,
        'guestToken' => $guestToken,
    ];

    file_put_contents(
        __DIR__ . '/frontend/e2e-config.json',
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    echo "E2E config written to frontend/e2e-config.json\n";
}

echo "Seeding complete.\n";
