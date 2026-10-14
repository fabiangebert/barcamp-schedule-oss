<?php /** @noinspection PhpUnhandledExceptionInspection */

use App\Database;

require __DIR__ . '/vendor/autoload.php';

// Map $_ENV to environment variables for getenv()
foreach ($_ENV as $key => $value) {
    if (is_string($value)) {
        putenv("$key=$value");
    }
}

return Database::getConnection();
