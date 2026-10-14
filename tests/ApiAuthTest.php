<?php

namespace Tests;

use App\Database;
use App\App;
use App\Auth;
use DateMalformedStringException;
use Doctrine\DBAL\Exception;
use Random\RandomException;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class ApiAuthTest extends BaseTestCase
{
    protected \Slim\App $app;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Load the app
        $this->app = App::create();
    }

    private function createRequest(string $method, string $path, array $headers = [], ?array $body = null): Request
    {
        $uri = new Uri('', '', null, $path);
        $handle = fopen('php://temp', 'w+');
        if ($body !== null) {
            fwrite($handle, json_encode($body));
        }
        rewind($handle);

        $headersObj = new Headers();
        foreach ($headers as $name => $value) {
            $headersObj->addHeader($name, $value);
        }
        $headersObj->addHeader('Content-Type', 'application/json');

        return new Request($method, $uri, $headersObj, [], $_SERVER, new Stream($handle));
    }

    public function testPublicRoutes()
    {
        $publicRoutes = [
            ['POST', '/api/auth/magic-link', ['email' => 'user@example.com']],
            ['GET', '/api/auth/login', ['token' => 'invalid-token']],
        ];

        foreach ($publicRoutes as [$method, $path, $body]) {
            $request = $this->createRequest($method, $path, [], $body);
            $response = $this->app->handle($request);

            // They should not return 401 Unauthorized because of missing authentication header
            // (Wait, /api/auth/login returns 401 if token is invalid, which is expected
            // and DIFFERENT from route protection)
            if ($path === '/api/auth/login') {
                $this->assertEquals(401, $response->getStatusCode());
                $body = (string)$response->getBody();
                $data = json_decode($body, true);
                $this->assertEquals('Invalid or expired token', $data['error']);
            } else {
                $this->assertNotEquals(
                    401,
                    $response->getStatusCode(),
                    "Public route $method $path should NOT be protected"
                );
            }
        }
    }

    public function testProtectedRoutes()
    {
        $protectedRoutes = [
            ['GET', '/api/schedules'],
            ['POST', '/api/schedules', ['name' => 'test']],
            ['GET', '/api/schedules/subscription', null],
            ['GET', '/api/users'],
            ['POST', '/api/users'],
            ['DELETE', '/api/users/1'],
        ];

        foreach ($protectedRoutes as $route) {
            $method = $route[0];
            $path = $route[1];
            $body = $route[2] ?? null;
            $request = $this->createRequest($method, $path, [], $body);
            $response = $this->app->handle($request);

            $this->assertEquals(401, $response->getStatusCode(), "Route $method $path should be protected");

            $bodyRes = (string)$response->getBody();
            $data = json_decode($bodyRes, true);
            $this->assertEquals('Unauthorized', $data['error']);
        }
    }

    /**
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testProtectedRoutesAllowRegularUser()
    {
        // Register a regular user
        $email = 'user-regular@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $protectedRoutes = [
            ['GET', '/api/schedules'],
            ['POST', '/api/schedules', ['name' => 'test']],
        ];

        foreach ($protectedRoutes as $route) {
            $method = $route[0];
            $path = $route[1];
            $body = $route[2] ?? null;
            $request = $this->createRequest($method, $path, ['Authorization' => "Bearer $jwt"], $body);
            $response = $this->app->handle($request);

            // Should not be 401
            $this->assertNotEquals(
                401,
                $response->getStatusCode(),
                "Protected route $method $path should allow regular user"
            );
        }

        // No specific restricted routes to test here for regular user vs admin for schedules
        // as the previous PATCH/DELETE routes are gone.
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testAdminRoutesRejectRegularUser()
    {
        // Register a regular user
        $email = 'user@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $adminRoutes = [
            ['GET', '/api/users'],
            ['POST', '/api/users'],
            ['DELETE', '/api/users/1'],
        ];

        foreach ($adminRoutes as $route) {
            $method = $route[0];
            $path = $route[1];
            $request = $this->createRequest($method, $path, ['Authorization' => "Bearer $jwt"]);
            $response = $this->app->handle($request);

            $this->assertEquals(401, $response->getStatusCode(), "Route $method $path should reject regular user");

            $body = (string)$response->getBody();
            $data = json_decode($body, true);
            $this->assertEquals('Unauthorized', $data['error']);
        }
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testAdminRoutesAllowAdmin()
    {
        // Register an admin user
        $email = 'admin-new@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'admin']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $adminRoutes = [
            ['GET', '/api/users', [], null],
            ['POST', '/api/users', [], ['email' => 'new-user@example.com', 'role' => 'user']],
        ];

        foreach ($adminRoutes as $route) {
            $method = $route[0];
            $path = $route[1];
            $headers = $route[2] ?? [];
            $body = $route[3] ?? null;
            $request = $this->createRequest($method, $path, ['Authorization' => "Bearer $jwt"], $body);
            $response = $this->app->handle($request);

            $this->assertEquals(200, $response->getStatusCode(), "Route $method $path should allow admin");
        }
    }

    /**
     * @throws Exception
     */
    public function testApiRateLimiting()
    {
        $email = 'api-ratelimit@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email]);

        // 1st request
        $request = $this->createRequest('POST', '/api/auth/magic-link', [], ['email' => $email]);
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        // 2nd request
        $request = $this->createRequest('POST', '/api/auth/magic-link', [], ['email' => $email]);
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        // 3rd request
        $request = $this->createRequest('POST', '/api/auth/magic-link', [], ['email' => $email]);
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        // 4th request should return 429 Too Many Requests
        $request = $this->createRequest('POST', '/api/auth/magic-link', [], ['email' => $email]);
        $response = $this->app->handle($request);
        $this->assertEquals(429, $response->getStatusCode());

        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        $this->assertEquals('Too many requests', $data['error']);
    }
}
