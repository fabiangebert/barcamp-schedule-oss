<?php

/** @noinspection PhpInternalEntityUsedInspection */

namespace Tests;

use App\Database;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{
    protected string $tempDbPath;
    protected string $tempUploadsDir;

    protected function setUp(): void
    {
        $this->tempDbPath = sys_get_temp_dir() . '/test_db_' . microtime(true) . '_' . uniqid() . '.sqlite';
        $this->tempUploadsDir = sys_get_temp_dir() . '/test_uploads_' . microtime(true) . '_' . uniqid();

        if (!is_dir($this->tempUploadsDir)) {
            mkdir($this->tempUploadsDir, 0777, true);
        }

        putenv("DB_PATH=$this->tempDbPath");
        $_ENV['DB_PATH'] = $this->tempDbPath;
        putenv("UPLOADS_DIR=$this->tempUploadsDir");
        $_ENV['UPLOADS_DIR'] = $this->tempUploadsDir;
        putenv("ADMIN_EMAIL=admin@example.com");
        $_ENV['ADMIN_EMAIL'] = 'admin@example.com';
        putenv("APP_ENV=test");
        $_ENV['APP_ENV'] = 'test';
        putenv("SMTP_DSN=null://null");
        $_ENV['SMTP_DSN'] = 'null://null';
        putenv("MAIL_FROM=noreply@example.com");
        $_ENV['MAIL_FROM'] = 'noreply@example.com';
        putenv("SSE_TIMEOUT=1");
        $_ENV['SSE_TIMEOUT'] = '1';
        putenv("SSE_SLEEP_TIME=100000"); // 100ms in tests to be faster
        $_ENV['SSE_SLEEP_TIME'] = '100000';
        putenv("MERCURE_URL=http://mercure/.well-known/mercure");
        $_ENV['MERCURE_URL'] = 'http://mercure/.well-known/mercure';
        putenv("MERCURE_JWT_SECRET=this_is_a_very_long_secret_for_testing_purposes_only");
        $_ENV['MERCURE_JWT_SECRET'] = 'this_is_a_very_long_secret_for_testing_purposes_only';
        putenv("JWT_SECRET=super-secret-key-that-is-at-least-32-characters-long");
        $_ENV['JWT_SECRET'] = 'super-secret-key-that-is-at-least-32-characters-long';
        putenv("JWT_EXPIRY_SECONDS=86400");
        $_ENV['JWT_EXPIRY_SECONDS'] = '86400';
        Database::setConnection(null);

        // Run migrations
        $connection = require __DIR__ . '/../migrations-db.php';
        $configArray = require __DIR__ . '/../migrations.php';

        $config = new ConfigurationArray($configArray);
        $dependencyFactory = DependencyFactory::fromConnection(
            $config,
            new ExistingConnection($connection)
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

        // Seed default admin
        $adminEmail = getenv('ADMIN_EMAIL');
        $admin = $connection->fetchAssociative('SELECT id FROM users WHERE email = ?', [$adminEmail]);
        if (!$admin) {
            $connection->insert('users', [
                'email' => $adminEmail,
                'role' => 'admin'
            ]);
        }
    }

    protected function tearDown(): void
    {
        Database::setConnection(null);
        if (file_exists($this->tempDbPath)) {
            unlink($this->tempDbPath);
        }
        if (is_dir($this->tempUploadsDir)) {
            $this->recursiveRmdir($this->tempUploadsDir);
        }
    }

    protected function recursiveRmdir(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->recursiveRmdir("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
}
