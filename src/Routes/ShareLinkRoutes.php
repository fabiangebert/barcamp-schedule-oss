<?php

namespace App\Routes;

use App\AuthMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\ShareLink;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

class ShareLinkRoutes
{
    use RouteHelper;

    /**
     * @param RouteCollectorProxy $group
     * @return void
     */
    public static function register(RouteCollectorProxy $group): void
    {
        $group->group('/share-links', function ($userGroup) {
            $userGroup->get('', function (Request $request, Response $response) {
                $user = (array)$request->getAttribute('user');
                $sub = isset($user['sub']) && is_scalar($user['sub']) ? (string)$user['sub'] : '';
                $isElevated = ($user['role'] ?? '') === 'admin';
                $params = $request->getQueryParams();
                $scheduleId = isset($params['schedule_id']) && is_scalar($params['schedule_id'])
                    ? (string)$params['schedule_id']
                    : '';

                if ($scheduleId !== '') {
                    if (
                        $errorResponse = self::findScheduleOrError(
                            $response,
                            $scheduleId,
                            $schedule
                        )
                    ) {
                        return $errorResponse;
                    }
                    $links = ShareLink::getBySchedule(
                        $scheduleId,
                        $sub,
                        $isElevated
                    );
                } else {
                    $links = ShareLink::getAllByUser($sub, $isElevated);
                }

                return self::withJson($response, ['status' => 'success', 'data' => $links]);
            });

            $userGroup->post('', function (Request $request, Response $response) {
                $user = (array)$request->getAttribute('user');
                $sub = isset($user['sub']) && is_scalar($user['sub']) ? (string)$user['sub'] : '';
                $data = (array)$request->getParsedBody();
                $scheduleId = isset($data['schedule_id']) && is_scalar($data['schedule_id'])
                    ? (string)$data['schedule_id']
                    : '';
                $expiresAt = isset($data['expires_at']) && is_string($data['expires_at'])
                    ? $data['expires_at']
                    : null;

                if ($scheduleId === '') {
                    return self::withJson(
                        $response,
                        ['status' => 'failure', 'error' => 'Schedule ID is required'],
                        400
                    );
                }

                if ($errorResponse = self::findScheduleOrError($response, $scheduleId, $schedule)) {
                    return $errorResponse;
                }

                $token = ShareLink::create($scheduleId, $sub, $expiresAt);

                return self::withJson($response, ['status' => 'success', 'token' => $token]);
            });

            $userGroup->patch('/{id}', function (Request $request, Response $response, array $args) {
                $user = (array)$request->getAttribute('user');
                $sub = isset($user['sub']) && is_scalar($user['sub']) ? (string)$user['sub'] : '';
                $isElevated = ($user['role'] ?? '') === 'admin';
                $id = (int)($args['id'] ?? 0);
                $data = (array)$request->getParsedBody();
                $expiresAt = isset($data['expires_at']) && is_string($data['expires_at'])
                    ? $data['expires_at']
                    : '';

                if ($expiresAt === '') {
                    return self::withJson(
                        $response,
                        ['status' => 'failure', 'error' => 'Expiry date is required'],
                        400
                    );
                }

                $success = ShareLink::updateExpiry($id, $expiresAt, $sub, $isElevated);

                if (!$success) {
                    return self::withJson(
                        $response,
                        [
                            'status' => 'failure',
                            'error' => 'Could not update share link (link not found or access denied)'
                        ],
                        404
                    );
                }

                return self::withJson($response, ['status' => 'success']);
            });

            $userGroup->delete('/{id}', function (Request $request, Response $response, array $args) {
                $user = (array)$request->getAttribute('user');
                $sub = isset($user['sub']) && is_scalar($user['sub']) ? (string)$user['sub'] : '';
                $isElevated = ($user['role'] ?? '') === 'admin';
                $id = (int)($args['id'] ?? 0);

                $success = ShareLink::delete($id, $sub, $isElevated);

                if (!$success) {
                    return self::withJson($response, [
                        'status' => 'failure',
                        'error' => 'Could not delete share link (link not found or access denied)'
                    ], 404);
                }

                return self::withJson($response, ['status' => 'success']);
            });
        })->add(new AuthMiddleware(['user', 'admin']));
    }
}
