<?php

namespace Tests;

use App\Database;
use Exception;

class DatabaseCoverageTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $driver = getenv('DB_DRIVER') ?: 'pdo_sqlite';
        if ($driver !== 'pdo_sqlite') {
            $this->markTestSkipped('DatabaseCoverageTest requires pdo_sqlite driver.');
        }
    }

    public function testGetConnectionThrowsExceptionWhenDbPathNotSet()
    {
        // Unset DB_PATH
        putenv('DB_PATH');
        unset($_ENV['DB_PATH']);

        Database::setConnection(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('DB_PATH environment variable is not set.');

        Database::getConnection();
    }

    /**
     * @throws Exception
     */
    public function testGetConnectionCreatesDirectory()
    {
        $nonExistentDir = sys_get_temp_dir() . '/non_existent_dir_' . uniqid();
        $dbPath = $nonExistentDir . '/test.sqlite';
        putenv("DB_PATH=$dbPath");
        $_ENV['DB_PATH'] = $dbPath;
        Database::setConnection(null);

        $this->assertDirectoryDoesNotExist($nonExistentDir);

        Database::getConnection();

        $this->assertDirectoryExists($nonExistentDir);

        // Cleanup
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
        if (is_dir($nonExistentDir)) {
            rmdir($nonExistentDir);
        }
    }
}
