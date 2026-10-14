<?php

namespace App\Routes;

use App\AuthMiddleware;
use App\NoCacheMiddleware;
use App\Database;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Documentation;
use App\ChangeTracker;
use App\Services\FeedbackExportService;
use App\Services\UploadService;
use Psr\Http\Message\UploadedFileInterface;
use Slim\App;
use Slim\Psr7\Stream;
use Slim\Routing\RouteCollectorProxy;

class DocumentationRoutes
{
    use RouteHelper;

    /**
     * @param RouteCollectorProxy $group
     * @return void
     */
    public static function register(RouteCollectorProxy $group): void
    {
        $group->group('/schedules', function (RouteCollectorProxy $group) {
            // Documentation Routes - Guest, User, Admin
            $group->group('', function ($guestGroup) {
                $guestGroup->get(
                    '/{id}/documentation/latest',
                    function (Request $request, Response $response, array $args) {
                        $id = (string)($args['id'] ?? '');
                        if ($errorResponse = self::findScheduleOrError($response, $id, $schedule)) {
                            return $errorResponse;
                        }
                        $timestamp = Documentation::getLatestTimestamp($id);
                        return self::withJson($response, ['status' => 'success', 'latest_timestamp' => $timestamp]);
                    }
                );

                $guestGroup->get(
                    '/{id}/sessions/{timeslot}/{room}/documentation',
                    function (Request $request, Response $response, array $args) {
                        $id = (string)($args['id'] ?? '');
                        if ($errorResponse = self::findScheduleOrError($response, $id, $schedule)) {
                            return $errorResponse;
                        }
                        $timeslot = urldecode($args['timeslot']);
                        $room = urldecode($args['room']);

                        $user = (array)$request->getAttribute('user');
                        $isGuest = isset($user['role']) && $user['role'] === 'guest';
                        $guestId = $request->getHeaderLine('X-Guest-Id') ?: null;
                        $userId = isset($user['id']) ? (int)$user['id'] : null;
                        $role = isset($user['role']) ? (string)$user['role'] : null;

                        $docs = Documentation::getForSession($id, $timeslot, $room, $guestId, $userId, $role);
                        return self::withJson($response, ['status' => 'success', 'data' => $docs]);
                    }
                );

                $guestGroup->post(
                    '/{id}/upload',
                    function (Request $request, Response $response, array $args) {
                        $id = (string)($args['id'] ?? '');
                        if ($errorResponse = self::findScheduleOrError($response, $id, $schedule)) {
                            return $errorResponse;
                        }

                        $uploadedFiles = $request->getUploadedFiles();
                        $uploadedFile = $uploadedFiles['file'] ?? null;

                        if (!$uploadedFile instanceof UploadedFileInterface) {
                            return self::withJson(
                                $response,
                                ['status' => 'failure', 'error' => 'No file uploaded'],
                                400
                            );
                        }

                        try {
                            $uploadService = new UploadService();
                            $path = $uploadService->handleUpload($uploadedFile);
                            return self::withJson($response, ['status' => 'success', 'path' => $path]);
                        } catch (\Exception $e) {
                            return self::withJson(
                                $response,
                                ['status' => 'failure', 'error' => $e->getMessage()],
                                400
                            );
                        }
                    }
                );

                $guestGroup->post(
                    '/{id}/sessions/{timeslot}/{room}/documentation',
                    function (Request $request, Response $response, array $args) {
                        $id = (string)($args['id'] ?? '');
                        if ($errorResponse = self::findScheduleOrError($response, $id, $schedule)) {
                            return $errorResponse;
                        }
                        $timeslot = urldecode($args['timeslot']);
                        $room = urldecode($args['room']);

                        $data = (array)$request->getParsedBody();
                        if ($request->getHeaderLine('Content-Type') === 'application/json' && empty($data)) {
                            $decodedBody = json_decode((string)$request->getBody(), true);
                            $data = is_array($decodedBody) ? $decodedBody : [];
                        }

                        $user = (array)$request->getAttribute('user');
                        $userId = isset($user['id']) ? (int)$user['id'] : null;

                        $imagePaths = isset($data['images']) && is_array($data['images']) ? $data['images'] : [];
                        $allowedExtensionsPattern = 'jpe?g|png|webp|pdf';
                        foreach ($imagePaths as $imagePath) {
                            $pattern = '#^/api/uploads/([a-f0-9]{16})\.(' . $allowedExtensionsPattern . ')$#i';
                            if (!is_string($imagePath) || !preg_match($pattern, $imagePath, $matches)) {
                                return self::withJson($response, [
                                    'status' => 'failure',
                                    'error' => 'Invalid or unsupported image path: ' . (is_scalar($imagePath)
                                            ? (string)$imagePath : gettype($imagePath))
                                ], 400);
                            }
                        }

                        $content = isset($data['content']) && is_scalar($data['content'])
                            ? (string)$data['content'] : null;
                        $sessionTitle = isset($data['session_title']) && is_scalar($data['session_title'])
                            ? (string)$data['session_title'] : null;
                        $rating = isset($data['rating']) ? (int)$data['rating'] : null;
                        $guestId = isset($data['guest_id']) && is_scalar($data['guest_id'])
                            ? (string)$data['guest_id'] : null;
                        $guestName = isset($data['guest_name']) && is_scalar($data['guest_name'])
                            ? (string)$data['guest_name'] : null;
                        $isPublic = isset($data['is_public']) ? (bool)$data['is_public'] : null;

                        if ($userId !== null && $guestName !== null && $guestName !== '') {
                            return self::withJson($response, [
                                'status' => 'failure',
                                'error' => 'Logged-in users must not specify a guest_name'
                            ], 400);
                        }

                        /** @var array<non-falsy-string> $imagePaths */
                        Documentation::add(
                            $id,
                            $timeslot,
                            $room,
                            $content,
                            $imagePaths,
                            $guestName,
                            $sessionTitle,
                            $rating,
                            $guestId,
                            $userId,
                            $isPublic
                        );

                        ChangeTracker::update($id);

                        return self::withJson($response, ['status' => 'success']);
                    }
                );

                $guestGroup->get('/{id}/documentation', function (
                    Request $request,
                    Response $response,
                    array $args
                ) {
                    $id = (string)($args['id'] ?? '');
                    if ($errorResponse = self::findScheduleOrError($response, $id, $schedule)) {
                        return $errorResponse;
                    }

                    $user = (array)$request->getAttribute('user');
                    $isGuest = isset($user['role']) && $user['role'] === 'guest';
                    $guestId = $request->getHeaderLine('X-Guest-Id') ?: null;
                    $userId = isset($user['id']) ? (int)$user['id'] : null;
                    $role = isset($user['role']) ? (string)$user['role'] : null;

                    $docs = Documentation::getAllForSchedule($id, $guestId, $userId, $role);
                    return self::withJson($response, [
                        'status' => 'success',
                        'data' => $docs
                    ]);
                });
            })->add(new AuthMiddleware(['guest', 'user', 'admin']));

            // Documentation Routes - User, Admin
            $group->group('', function ($userGroup) {
                $userGroup->get('/{id}/export', function (Request $request, Response $response, array $args) {
                    $id = (string)($args['id'] ?? '');

                    $user = (array)$request->getAttribute('user');
                    $userId = isset($user['id']) && is_numeric($user['id']) ? (int)$user['id'] : null;
                    $role = isset($user['role']) && is_scalar($user['role']) ? (string)$user['role'] : null;

                    $zipFile = Documentation::exportToZip($id, $userId, $role);
                    if (!$zipFile) {
                        return self::withJson($response, [
                            'error' => 'No documentation found or could not create ZIP'
                        ], 404);
                    }

                    $resource = fopen($zipFile, 'r');
                    if ($resource === false) {
                        return self::withJson($response, [
                            'error' => 'Could not open ZIP file for reading'
                        ], 500);
                    }
                    $stream = new Stream($resource);
                    return $response->withHeader('Content-Type', 'application/zip')
                        ->withHeader(
                            'Content-Disposition',
                            'attachment; filename="documentation_' . $id . '.zip"'
                        )
                        ->withBody($stream);
                });

                $userGroup->get('/{id}/feedback/export/csv', function (
                    Request $request,
                    Response $response,
                    array $args
                ) {
                    $id = (string)($args['id'] ?? '');

                    $user = (array)$request->getAttribute('user');
                    $userId = isset($user['id']) && is_numeric($user['id']) ? (int)$user['id'] : null;
                    $role = isset($user['role']) && is_scalar($user['role']) ? (string)$user['role'] : null;

                    $csvContent = FeedbackExportService::toCsv($id, $userId, $role);
                    if ($csvContent === null) {
                        return self::withJson($response, [
                            'error' => 'Schedule not found'
                        ], 404);
                    }

                    $response->getBody()->write($csvContent);
                    return $response
                        ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                        ->withHeader(
                            'Content-Disposition',
                            'attachment; filename="feedback_' . $id . '.csv"'
                        );
                });
            })->add(new AuthMiddleware(['user', 'admin']));
        })->add(new NoCacheMiddleware());

        // Documentation routes not starting with /schedules
        $group->group('', function ($userGroup) {
            $userGroup->delete('/documentation/{id}', function (Request $request, Response $response, array $args) {
                $id = (int)$args['id'];

                $doc = Database::getConnection()->fetchAssociative(
                    'SELECT schedule_id, user_id FROM documentation WHERE id = ?',
                    [$id]
                );
                if (!$doc) {
                    return self::withJson(
                        $response,
                        ['status' => 'failure', 'error' => 'Documentation entry not found'],
                        404
                    );
                }

                $user = (array)$request->getAttribute('user');
                $role = isset($user['role']) && is_scalar($user['role']) ? (string)$user['role'] : '';
                $userId = isset($user['id']) && is_numeric($user['id']) ? (int)$user['id'] : null;
                $docUserId = isset($doc['user_id']) && is_numeric($doc['user_id']) ? (int)$doc['user_id'] : null;

                // Authorization:
                //  - admins may delete anything
                //  - any authenticated user may delete guest-created docs (user_id IS NULL)
                //  - otherwise only the owning user may delete their own doc
                $isAdmin = $role === 'admin';
                $isGuestDoc = $docUserId === null;
                $isOwner = $userId !== null && $docUserId !== null && $userId === $docUserId;
                if (!$isAdmin && !$isGuestDoc && !$isOwner) {
                    return self::withJson(
                        $response,
                        ['status' => 'failure', 'error' => 'Forbidden: ownership required'],
                        403
                    );
                }

                $success = Documentation::delete($id);
                if ($success) {
                    $scheduleId = isset($doc['schedule_id']) && is_string($doc['schedule_id'])
                        ? $doc['schedule_id']
                        : '';
                    ChangeTracker::update($scheduleId);
                }

                return self::withJson($response, ['status' => 'success']);
            });
        })->add(new AuthMiddleware(['user', 'admin']));
    }
}
