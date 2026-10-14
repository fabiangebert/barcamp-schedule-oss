<?php

namespace App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware implements MiddlewareInterface
{
    /** @var array<string> */
    private array $allowedRoles;

    /**
     * @param array<string> $allowedRoles
     */
    public function __construct(array $allowedRoles = ['user', 'admin'])
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');
        $jwt = str_replace('Bearer ', '', $authHeader);

        $user = Auth::validateJWT($jwt);

        if (!$user || !in_array($user['role'] ?? 'guest', $this->allowedRoles)) {
            $response = new SlimResponse();
            $response->getBody()->write((string)json_encode(['status' => 'failure', 'error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // Check if link_exp is present and expired
        if (isset($user['link_exp']) && time() >= $user['link_exp']) {
            $response = new SlimResponse();
            $response->getBody()->write((string)json_encode(['status' => 'failure', 'error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // Add user object to request attributes for further use in routes
        $request = $request->withAttribute('user', $user);

        return $handler->handle($request);
    }
}
