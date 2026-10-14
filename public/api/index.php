<?php

declare(strict_types=1);

use App\App;

require __DIR__ . '/../../vendor/autoload.php';

// Skip when running PHPUnit tests or CLI maintenance scripts
if (
    php_sapi_name() !== 'cli'
    && getenv('APP_ENV') !== 'test'
    && !defined('PHPUNIT_COMPOSER_INSTALL')
) {
    /** @noinspection PhpUnhandledExceptionInspection */
    $app = App::create();

    if (function_exists('frankenphp_handle_request')) {
        $handler = static function () use ($app) {
            $app->run();
        };

        $maxRequests = (int)(getenv('MAX_REQUESTS') ?: 500);
        for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
            $keepRunning = \frankenphp_handle_request($handler);
            if (!$keepRunning) {
                break;
            }
            gc_collect_cycles();
        }
    } else {
        $app->run();
    }
}
