<?php

namespace App\Routes;

use App\AuthMiddleware;
use App\Schedule;
use App\ShareLink;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Auth;
use Slim\Routing\RouteCollectorProxy;

class AuthRoutes
{
    use RouteHelper;

    /**
     * @param RouteCollectorProxy $group
     * @return void
     */
    public static function register(RouteCollectorProxy $group): void
    {
        // Name update for guest, user, admin
        $group->post('/user/name', function (Request $request, Response $response) {
            $user = (array)$request->getAttribute('user');
            $data = (array)$request->getParsedBody();
            $newName = (string)($data['name'] ?? '');

            if (empty($newName)) {
                return self::withJson($response, ['status' => 'failure', 'error' => 'Name cannot be empty'], 400);
            }

            $sub = isset($user['sub']) && is_scalar($user['sub']) ? (string)$user['sub'] : '';
            $role = isset($user['role']) && is_scalar($user['role']) ? (string)$user['role'] : 'guest';
            $id = isset($user['id']) ? (int)$user['id'] : null;

            if ($role !== 'guest' && $id) {
                $conn = \App\Database::getConnection();
                $conn->update('users', ['name' => $newName], ['id' => $id]);
            }

            $jwt = Auth::generateJWT($sub, $role, $newName, null, $id, null);
            $decoded = Auth::validateJWT($jwt);

            return self::withJson($response, [
                'status' => 'success',
                'token' => $jwt,
                'user' => $decoded
            ]);
        })->add(new AuthMiddleware(['admin', 'user', 'guest']));

        // Auth Routes
        $group->post('/auth/magic-link', function (Request $request, Response $response) {
            $data = (array)$request->getParsedBody();
            $email = (string)($data['email'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return self::withJson($response, ['status' => 'success', 'message' => 'Magic link sent']);
            }

            try {
                $token = Auth::generateMagicToken($email);

                if (!$token) {
                    // Even if the user is not found, we respond 200 to avoid leaking information
                    // and simply skip sending the mail.
                    return self::withJson($response, ['status' => 'success', 'message' => 'Magic link sent']);
                }

                Auth::sendMagicLink($email, $token);

                return self::withJson($response, ['status' => 'success', 'message' => 'Magic link sent']);
            } catch (\Exception $e) {
                if ($e->getMessage() === 'Rate limit exceeded') {
                    return self::withJson($response, ['status' => 'failure', 'error' => 'Too many requests'], 429);
                }
                throw $e;
            }
        });

        $group->get('/auth/login', function (Request $request, Response $response) {
            $params = $request->getQueryParams();
            $token = (string)($params['token'] ?? '');
            $shareToken = (string)($params['share_token'] ?? '');

            if ($shareToken) {
                $link = ShareLink::validate($shareToken);
                if (!$link) {
                    return self::withJson($response, [
                        'status' => 'failure',
                        'error' => 'Invalid or expired share link'
                    ], 401);
                }

                $guestName = ShareLink::generateGuestName();
                $guestId = 'guest-' . bin2hex(random_bytes(8));
                $expiresAt = isset($link['expires_at']) ? strtotime($link['expires_at']) : null;
                $jwt = Auth::generateJWT($guestId, 'guest', $guestName, null, null, $expiresAt);
                $decoded = Auth::validateJWT($jwt);

                $id = isset($link['schedule_id']) && is_string($link['schedule_id']) ? $link['schedule_id'] : '';
                $schedule = Schedule::getByHashedId($id);
                $redirectSchedule = (is_array($schedule)
                    && isset($schedule['hashed_id'])
                    && is_string($schedule['hashed_id'])) ? $schedule['hashed_id'] : '';

                return self::withJson($response, [
                    'status' => 'success',
                    'token' => $jwt,
                    'user' => $decoded,
                    'redirect_schedule' => $redirectSchedule
                ]);
            }

            $result = Auth::verifyMagicToken($token);

            if (!$result) {
                return self::withJson($response, ['status' => 'failure', 'error' => 'Invalid or expired token'], 401);
            }

            $jwt = $result['token'];
            $decoded = Auth::validateJWT($jwt);

            return self::withJson($response, ['status' => 'success', 'token' => $jwt, 'user' => $decoded]);
        });
    }
}
