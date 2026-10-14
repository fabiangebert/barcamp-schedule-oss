<?php

namespace App\Routes;

use App\AuthMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Database;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

class UserRoutes
{
    use RouteHelper;

    /**
     * @param RouteCollectorProxy $group
     * @return void
     */
    public static function register(RouteCollectorProxy $group): void
    {
        // User Management Routes (Admin only)
        $group->group('', function ($adminGroup) {
            $adminGroup->get('/users', function (Request $request, Response $response) {
                $conn = Database::getConnection();
                $users = $conn->fetchAllAssociative('SELECT id, email, role, name FROM users');

                return self::withJson($response, ['status' => 'success', 'data' => $users]);
            });

            $adminGroup->post('/users', function (Request $request, Response $response) {
                try {
                    $data = (array)$request->getParsedBody();
                    $email = (string)($data['email'] ?? '');
                    $role = (string)($data['role'] ?? 'user');
                    $name = isset($data['name']) ? (string)$data['name'] : null;

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        return self::withJson($response, ['status' => 'failure', 'error' => 'Invalid email'], 400);
                    }

                    $conn = Database::getConnection();
                    $existing = $conn->fetchAssociative('SELECT id FROM users WHERE email = ?', [$email]);

                    if ($existing !== false) {
                        $conn->update('users', ['role' => $role, 'name' => $name], ['email' => $email]);
                    } else {
                        $conn->insert('users', [
                            'email' => $email,
                            'role' => $role,
                            'name' => $name,
                            'created_at' => (new \DateTime())->format('Y-m-d H:i:s')
                        ]);
                    }

                    return self::withJson($response, ['status' => 'success']);
                } catch (\Exception $e) {
                    return self::withJson($response, ['status' => 'failure', 'error' => $e->getMessage()], 500);
                }
            });

            $adminGroup->delete('/users/{id}', function (Request $request, Response $response, array $args) {
                $id = (string)($args['id'] ?? '');
                $conn = Database::getConnection();
                $deleted = $conn->delete('users', ['id' => $id]);

                if ($deleted === 0) {
                    return self::withJson($response, ['status' => 'failure', 'error' => 'User not found'], 404);
                }

                return self::withJson($response, ['status' => 'success']);
            });
        })->add(new AuthMiddleware(['admin']));

        // User profile routes
        $group->group('/user', function ($userGroup) {
            $userGroup->get('/me', function (Request $request, Response $response) {
                $user = (array)$request->getAttribute('user');
                return self::withJson($response, ['status' => 'success', 'data' => $user]);
            });
        })->add(new AuthMiddleware(['user', 'admin', 'guest']));
    }
}
