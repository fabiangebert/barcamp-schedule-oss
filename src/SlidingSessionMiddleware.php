<?php

namespace App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class SlidingSessionMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);
        $user = $request->getAttribute('user');

        if (!$user || !is_array($user)) {
            return $response;
        }

        $iat = $user['iat'] ?? null;
        $exp = $user['exp'] ?? null;
        $linkExp = $user['link_exp'] ?? null;

        if (!$iat || !$exp) {
            return $response;
        }

        $now = time();
        $totalLifetime = $exp - $iat;
        $passedTime = $now - $iat;

        // If link_exp exists and is in the past, we should ideally have caught this in AuthMiddleware,
        // but let's be safe. If we are past link_exp, don't renew.
        if ($linkExp !== null && $now >= $linkExp) {
            return $response;
        }

        // Renew if more than 50% of the lifetime has passed
        if ($passedTime > ($totalLifetime / 2)) {
            $expirySeconds = (int)(getenv('JWT_EXPIRY_SECONDS') ?: 86400);

            $newExp = $now + $expirySeconds;

            // Hard cap by link_exp if it exists
            if ($linkExp !== null) {
                $newExp = min($newExp, $linkExp);
            }

            // Only issue a new token if the new expiry is actually further in the future than the current one
            if ($newExp > $exp) {
                $newToken = Auth::generateJWT(
                    $user['sub'],
                    $user['role'],
                    $user['name'] ?? null,
                    $newExp - $now,
                    $user['id'] ?? null,
                    $linkExp
                );
                return $response->withHeader('X-New-Token', $newToken)
                    ->withHeader('Access-Control-Expose-Headers', 'X-New-Token');
            }
        }

        return $response;
    }
}
