<?php

namespace App;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Exception;

class Database
{
    private static ?Connection $connection = null;

    public static function setConnection(?Connection $connection): void
    {
        self::$connection = $connection;
    }

    /**
     * @throws Exception
     */
    public static function getConnection(): Connection
    {
        if (self::$connection === null) {
            $driver = getenv('DB_DRIVER') ?: 'pdo_sqlite';

            $connectionParams = match ($driver) {
                'pdo_sqlite' => self::sqliteParams(),
                'pdo_mysql' => [
                    'driver' => 'pdo_mysql',
                    'host' => getenv('DB_HOST') ?: 'localhost',
                    'port' => (string) (getenv('DB_PORT') ?: 3306),
                    'dbname' => (string) getenv('DB_NAME'),
                    'user' => getenv('DB_USER') ?: 'root',
                    'password' => getenv('DB_PASSWORD') ?: '',
                    'charset' => 'utf8mb4',
                ],
                'pdo_pgsql' => [
                    'driver' => 'pdo_pgsql',
                    'host' => getenv('DB_HOST') ?: 'localhost',
                    'port' => (string) (getenv('DB_PORT') ?: 5432),
                    'dbname' => (string) getenv('DB_NAME'),
                    'user' => (string) getenv('DB_USER'),
                    'password' => (string) getenv('DB_PASSWORD'),
                    'sslmode' => getenv('DB_SSLMODE') ?: 'require',
                    'driverOptions' => [
                        \PDO::ATTR_EMULATE_PREPARES => true,
                    ],
                ],
                default => throw new \Exception(
                    "Unsupported DB_DRIVER '$driver'. Supported: pdo_sqlite, pdo_mysql, pdo_pgsql."
                ),
            };

            /** @phpstan-ignore argument.type (Doctrine accepts array<string, string>) */
            self::$connection = DriverManager::getConnection($connectionParams);

            // SQLite does not enable foreign key enforcement by default.
            // Without this pragma, ON DELETE CASCADE constraints defined in
            // migrations are stored in the schema but never executed, which
            // would leave orphaned rows when a parent is deleted.
            if ($driver === 'pdo_sqlite') {
                self::$connection->executeStatement('PRAGMA foreign_keys = ON');
            }
        }
        return self::$connection;
    }

    /**
     * @return array<string, string>
     */
    private static function sqliteParams(): array
    {
        $dbPath = getenv('DB_PATH');
        if (!$dbPath) {
            if (getenv('APP_ENV') !== 'test') {
                error_log('DB_PATH environment variable is not set.');
            }
            throw new Exception('DB_PATH environment variable is not set. Please configure the database path.');
        }

        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        return [
            'driver' => 'pdo_sqlite',
            'path' => $dbPath,
        ];
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     * @throws Exception
     */
    public static function ping(): void
    {
        $conn = self::getConnection();
        try {
            $conn->executeQuery($conn->getDatabasePlatform()->getDummySelectSQL());
        } catch (Exception $e) {
            // Reconnect if the connection is dead
            self::$connection = null;
            self::getConnection();
        }
    }
}
