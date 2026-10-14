<?php

namespace App\Routes;

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ResponseInterface as Response;
use App\Schedule;

trait RouteHelper
{
    /**
     * @param Response $response
     * @param array<string, mixed> $data
     * @param int $status
     * @return Response
     */
    protected static function withJson(Response $response, array $data, int $status = 200): Response
    {
        $json = json_encode($data);
        $response->getBody()->write($json === false ? '' : $json);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * @param Response $response
     * @param string $id
     * @param array<string, mixed>|null $schedule
     * @return Response|null
     * @throws Exception
     */
    protected static function findScheduleOrError(Response $response, string $id, &$schedule): ?Response
    {
        $schedule = Schedule::getByHashedId($id);
        if (!$schedule) {
            return self::withJson($response, ['status' => 'failure', 'error' => 'Schedule not found'], 404);
        }
        return null;
    }
}
