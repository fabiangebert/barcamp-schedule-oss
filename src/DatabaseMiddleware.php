<?php

namespace App;

use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class DatabaseMiddleware implements MiddlewareInterface
{
    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        try {
            Database::ping();
        } catch (Exception $e) {
            error_log('Database ping failed: ' . $e->getMessage());
        }

        return $handler->handle($request);
    }
}
