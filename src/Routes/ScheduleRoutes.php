<?php

namespace App\Routes;

use App\AuthMiddleware;
use App\NoCacheMiddleware;
use App\Documentation;
use App\Services\S3Service;
use App\Services\ScheduleValidationService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Schedule;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Firebase\JWT\JWT;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

class ScheduleRoutes
{
    use RouteHelper;

    /**
     * @param RouteCollectorProxy $group
     * @return void
     */
    public static function registerPublic(RouteCollectorProxy $group): void
    {
        $group->group('/schedules', function (RouteCollectorProxy $group) {
            // CORS preflight options route for embeds
            $group->options('/{id}', function (Request $request, Response $response) {
                return $response
                    ->withHeader('Access-Control-Allow-Origin', '*')
                    ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
                    ->withHeader(
                        'Access-Control-Allow-Headers',
                        'Content-Type, Authorization, X-Requested-With, Accept, Origin'
                    )
                    ->withStatus(200);
            });

            // Schedule get route is a Public route to make it accessible even in embeds
            $group->get('/{id}', function (Request $request, Response $response, array $args) {
                $id = (string)($args['id'] ?? '');

                if ($errorResponse = self::findScheduleOrError($response, $id, $schedule)) {
                    return $errorResponse;
                }

                $documentation_counts = Documentation::getDocumentationCounts($id);
                return self::withJson($response, [
                    'status' => 'success',
                    'data' => $schedule,
                    'documentation_counts' => $documentation_counts
                ])->withHeader('Access-Control-Allow-Origin', '*');
            });
        })->add(new NoCacheMiddleware());
    }

    /**
     * @param RouteCollectorProxy $group
     * @return void
     */
    public static function register(RouteCollectorProxy $group): void
    {
        $group->group('/schedules', function (RouteCollectorProxy $group) {
            // Schedule Routes - Guest, User, Admin
            $group->group('', function ($guestGroup) {
                $guestGroup->get('/subscription', function (Request $request, Response $response) {
                    $params = $request->getQueryParams();
                    $scheduleId = (string)($params['schedule_id'] ?? '');

                    if (empty($scheduleId)) {
                        return self::withJson($response, ['error' => 'Missing schedule_id'], 400);
                    }

                    $mercurePublicUrl = (string)getenv('MERCURE_PUBLIC_URL');
                    $mercureSecret = (string) (getenv('MERCURE_JWT_SECRET') ?: '');
                    $mercureJwtTimeout = (int) (getenv('MERCURE_JWT_TIMEOUT') ?: 600);

                    if (!$mercurePublicUrl || !$mercureSecret) {
                        return self::withJson($response, ['error' => 'Mercure not configured'], 500);
                    }

                    // For every schedule ID there is a topic.
                    $topic = "schedule/$scheduleId";

                    // Generate subscription JWT
                    $user = $request->getAttribute('user');
                    $linkExp = $user['link_exp'] ?? null;

                    $exp = time() + $mercureJwtTimeout;
                    if ($linkExp !== null) {
                        $exp = min($exp, $linkExp);
                    }

                    $payload = [
                        'mercure' => [
                            'subscribe' => [$topic],
                        ],
                        'exp' => $exp,
                    ];
                    $token = JWT::encode($payload, $mercureSecret, 'HS256');

                    $data = [
                        'url' => $mercurePublicUrl . '?topic=' . urlencode($topic),
                        'token' => $token,
                    ];

                    return self::withJson($response, ['status' => 'success', 'data' => $data]);
                });
            })->add(new AuthMiddleware(['guest', 'user', 'admin']));

            // Schedule Routes - User, Admin
            $group->group('', function ($userGroup) {
                $userGroup->get('', function (Request $request, Response $response) {
                    $schedules = Schedule::all();
                    return self::withJson($response, ['status' => 'success', 'data' => $schedules]);
                });

                $userGroup->post('', function (Request $request, Response $response) {
                    $data = (array)$request->getParsedBody();
                    $name = trim((string)($data['name'] ?? ''));
                    $scheduleData = (array)($data['data'] ?? []);
                    $date = isset($data['date']) ? (string)$data['date'] : null;

                    if (empty($name)) {
                        return self::withJson($response, ['error' => 'Schedule name cannot be empty'], 400);
                    }

                    if (empty($scheduleData)) {
                        return self::withJson($response, ['error' => 'Schedule data cannot be empty'], 400);
                    }

                    $validation = ScheduleValidationService::validate($scheduleData);
                    if (!$validation['passed']) {
                        return self::withJson($response, [
                            'error' => 'Invalid schedule data',
                            'details' => $validation['errors']
                        ], 400);
                    }

                    if (Schedule::nameExists($name)) {
                        return self::withJson($response, ['error' => 'Schedule name already exists'], 400);
                    }

                    try {
                        $hashedId = Schedule::create($name, $scheduleData, $date);
                    } catch (UniqueConstraintViolationException $e) {
                        return self::withJson($response, ['error' => 'Schedule name already exists'], 400);
                    }

                    if (!$hashedId) {
                        return self::withJson($response, ['error' => 'Could not create schedule'], 500);
                    }

                    $newSchedule = Schedule::getByHashedId($hashedId);
                    self::publishToS3($hashedId);
                    return self::withJson($response, ['status' => 'success', 'data' => $newSchedule]);
                });

                $userGroup->patch('/{id}', function (Request $request, Response $response, array $args) {
                    $id = (string)($args['id'] ?? '');
                    $data = (array)$request->getParsedBody();
                    $scheduleData = (array)($data['data'] ?? []);
                    $version = (int) ($data['version'] ?? 0);
                    $date = isset($data['date']) ? (string)$data['date'] : null;
                    $name = isset($data['name']) ? (string)$data['name'] : null;

                    if (empty($scheduleData)) {
                        return self::withJson($response, ['error' => 'Schedule data cannot be empty'], 400);
                    }

                    $validation = ScheduleValidationService::validate($scheduleData);
                    if (!$validation['passed']) {
                        return self::withJson($response, [
                            'error' => 'Invalid schedule data',
                            'details' => $validation['errors']
                        ], 400);
                    }

                    $result = Schedule::saveByHashedId($id, $scheduleData, $version, $date, $name);

                    if ($result === 'not_found') {
                        return self::withJson($response, ['error' => 'Schedule not found'], 404);
                    }

                    $latestSchedule = Schedule::getByHashedId($id);

                    if ($result === 'version_mismatch') {
                        return self::withJson($response, [
                            'error' => 'Version mismatch (concurrent editing)',
                            'current_schedule' => $latestSchedule
                        ], 409);
                    }

                    if ($result === 'name_exists') {
                        return self::withJson($response, [
                            'error' => 'Schedule name already exists',
                            'current_schedule' => $latestSchedule
                        ], 400);
                    }

                    self::publishToS3($id);
                    $documentation_counts = Documentation::getDocumentationCounts($id);
                    return self::withJson($response, [
                        'status' => 'success',
                        'data' => $latestSchedule,
                        'documentation_counts' => $documentation_counts
                    ]);
                });

                $userGroup->post('/{id}/rename', function (Request $request, Response $response, array $args) {
                    $id = (string)($args['id'] ?? '');
                    $data = (array)$request->getParsedBody();
                    $newName = trim((string)($data['newName'] ?? ''));

                    if (empty($newName)) {
                        return self::withJson($response, ['error' => 'New name cannot be empty'], 400);
                    }

                    $success = Schedule::renameByHashedId($id, $newName);
                    if (!$success) {
                        return self::withJson($response, [
                            'error' => 'Could not rename schedule (maybe name exists or id not found)'
                        ], 400);
                    }

                    self::publishToS3($id);
                    return self::withJson($response, ['status' => 'success']);
                });
            })->add(new AuthMiddleware(['user', 'admin']));

            // Schedule Routes - Admin only
            $group->group('', function ($adminGroup) {
                $adminGroup->delete('/{id}', function (Request $request, Response $response, array $args) {
                    $id = (string)($args['id'] ?? '');
                    $success = Schedule::deleteByHashedId($id);

                    if (!$success) {
                        return self::withJson($response, ['status' => 'failure', 'error' => 'Schedule not found'], 404);
                    }

                    self::deleteFromS3($id);
                    return self::withJson($response, ['status' => 'success']);
                });
            })->add(new AuthMiddleware(['admin']));
        })->add(new NoCacheMiddleware());
    }

    private static function publishToS3(string $hashedId): void
    {
        if (!S3Service::isConfigured()) {
            return;
        }

        $schedule = Schedule::getByHashedId($hashedId);
        if (!$schedule) {
            return;
        }

        $documentation_counts = Documentation::getDocumentationCounts($hashedId);
        $payload = json_encode([
            'status' => 'success',
            'data' => $schedule,
            'documentation_counts' => $documentation_counts,
        ]);

        if (is_string($payload)) {
            S3Service::put('schedules/' . $hashedId . '.json', $payload, 'application/json');
        }
    }

    private static function deleteFromS3(string $hashedId): void
    {
        if (!S3Service::isConfigured()) {
            return;
        }

        S3Service::delete('schedules/' . $hashedId . '.json');
    }
}
