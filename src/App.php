<?php

namespace App;

use App\Routes\AuthRoutes;
use App\Routes\DocumentationRoutes;
use App\Routes\ScheduleRoutes;
use App\Routes\ShareLinkRoutes;
use App\Routes\DownloadRoutes;
use App\Routes\UserRoutes;
use Psr\Container\ContainerInterface;
use Slim\Factory\AppFactory;
use Slim\App as SlimApp;

class App
{
    /**
     * @return SlimApp<ContainerInterface|null>
     * @throws \Doctrine\DBAL\Exception
     */
    public static function create(): SlimApp
    {

        // Check for required environment variables
        $driver = getenv('DB_DRIVER') ?: 'pdo_sqlite';
        match ($driver) {
            'pdo_sqlite' => self::requireEnv('DB_PATH', 'DB_PATH is required for SQLite driver.'),
            'pdo_mysql', 'pdo_pgsql' => self::requireEnv('DB_NAME', 'DB_NAME is required for MySQL/PostgreSQL driver.'),
            default => self::fail("Unsupported DB_DRIVER '$driver'. Supported: pdo_sqlite, pdo_mysql, pdo_pgsql."),
        };

        $adminEmail = getenv('ADMIN_EMAIL');
        if (!$adminEmail) {
            http_response_code(500);
            echo json_encode(['error' => 'Server configuration error: ADMIN_EMAIL environment variable is not set.']);
            exit;
        }

        $jwtSecret = getenv('JWT_SECRET');
        if ($jwtSecret === false || strlen($jwtSecret) < 32) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Server configuration error: JWT_SECRET is missing or shorter than 32 characters.',
            ]);
            exit;
        }

        $app = AppFactory::create();
        $app->setBasePath('/api');

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(new DatabaseMiddleware());

        $displayErrorDetails = (getenv('APP_DEBUG') === 'true') || (getenv('APP_ENV') === 'development');
        $logErrorDetails = !$displayErrorDetails; // avoid leaking stacktraces in production logs
        $app->addErrorMiddleware($displayErrorDetails, true, $logErrorDetails);

        // Protected Routes
        $app->group('', function (\Slim\Routing\RouteCollectorProxy $protectedGroup) {
            ScheduleRoutes::register($protectedGroup);
            UserRoutes::register($protectedGroup);
            DocumentationRoutes::register($protectedGroup);
            ShareLinkRoutes::register($protectedGroup);
        })->add(new SlidingSessionMiddleware())->add(new AuthMiddleware(['user', 'admin', 'guest']));

        // Healthcheck
        $app->get('/health', function (
            \Psr\Http\Message\ServerRequestInterface $request,
            \Psr\Http\Message\ResponseInterface $response
        ) {
            $response->getBody()->write(json_encode([
                'status' => 'ok',
                'timestamp' => date('c'),
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        });

        // Auth Routes - Public
        AuthRoutes::register($app);
        DownloadRoutes::register($app);
        ScheduleRoutes::registerPublic($app);

        return $app;
    }

    private static function requireEnv(string $name, string $message): void
    {
        if (!getenv($name)) {
            self::fail("Server configuration error: $message");
        }
    }

    private static function fail(string $message): never
    {
        http_response_code(500);
        echo json_encode(['error' => $message]);
        exit;
    }
}
