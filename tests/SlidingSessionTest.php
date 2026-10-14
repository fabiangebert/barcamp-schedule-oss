<?php

namespace Tests;

use App\App;
use App\Auth;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use App\Database;
use App\ShareLink;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class SlidingSessionTest extends BaseTestCase
{
    protected \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
    }

    private function createRequest(string $method, string $path, array $headers = [], ?array $body = null): Request
    {
        $parts = explode('?', $path);
        $uriPath = $parts[0];
        $queryString = $parts[1] ?? '';
        $uri = new Uri('', 'localhost', null, $uriPath, $queryString);
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

        $server = $_SERVER;
        $server['REQUEST_METHOD'] = $method;
        $server['REQUEST_URI'] = $path;

        return new Request($method, $uri, $headersObj, [], $server, new Stream($handle));
    }

    #[RunInSeparateProcess]
    public function testStandardSessionRenewal()
    {
        $email = 'user@example.com';

        $expirySeconds = 100;
        $iat = time() - 60; // 60 seconds ago
        $exp = $iat + $expirySeconds; // total 100s, so 60% passed.

        $payload = [
            'iss' => 'barcamp-schedule',
            'sub' => $email,
            'role' => 'user',
            'name' => 'Test User',
            'iat' => $iat,
            'exp' => $exp,
            'link_exp' => null
        ];

        $jwt = \Firebase\JWT\JWT::encode(
            $payload,
            getenv('JWT_SECRET') ?: 'super-secret-key-that-is-at-least-32-characters-long',
            'HS256'
        );

        $request = $this->createRequest('GET', '/api/schedules', ['Authorization' => "Bearer $jwt"]);

        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode(), "Response body: " . (string)$response->getBody());
        $this->assertTrue(
            $response->hasHeader('X-New-Token'),
            "Response headers: " . json_encode($response->getHeaders())
        );

        $newToken = $response->getHeaderLine('X-New-Token');
        $decoded = Auth::validateJWT($newToken);
        $this->assertNotNull($decoded);
        $this->assertGreaterThan(time(), $decoded['exp']);
    }

    #[RunInSeparateProcess]
    public function testGuestSessionRenewalCappedByLinkExp()
    {
        $linkExp = time() + 500; // Hard cap in 500 seconds
        $iat = time() - 200;
        $exp = $iat + 300; // Expired in 100s from now. 200/300 = 66% passed.

        $payload = [
            'iss' => 'barcamp-schedule',
            'sub' => 'guest-random-id',
            'role' => 'guest',
            'name' => 'Guest User',
            'iat' => $iat,
            'exp' => $exp,
            'link_exp' => $linkExp
        ];

        $jwt = \Firebase\JWT\JWT::encode(
            $payload,
            getenv('JWT_SECRET') ?: 'super-secret-key-that-is-at-least-32-characters-long',
            'HS256'
        );

        // Note: /api/schedules/subscription is available for guests
        $request = $this->createRequest(
            'GET',
            '/api/schedules/subscription?schedule_id=test',
            ['Authorization' => "Bearer $jwt"]
        );

        $response = $this->app->handle($request);

        // It might return 500 because Mercure is not configured, but that's fine for testing the middleware
        $this->assertNotEquals(404, $response->getStatusCode(), "Route not found");
        $this->assertTrue(
            $response->hasHeader('X-New-Token'),
            "Missing X-New-Token header. Status: " . $response->getStatusCode()
        );

        $newToken = $response->getHeaderLine('X-New-Token');
        $decoded = Auth::validateJWT($newToken);

        $this->assertEquals($linkExp, $decoded['exp']);
    }

    public function testGuestSessionRejectedAfterLinkExp()
    {
        $linkExp = time() - 10; // link already expired
        $iat = time() - 100;
        $exp = time() + 100; // JWT itself still valid

        $payload = [
            'iss' => 'barcamp-schedule',
            'sub' => 'guest-random-id',
            'role' => 'guest',
            'name' => 'Guest User',
            'iat' => $iat,
            'exp' => $exp,
            'link_exp' => $linkExp
        ];

        $jwt = \Firebase\JWT\JWT::encode(
            $payload,
            getenv('JWT_SECRET') ?: 'super-secret-key-that-is-at-least-32-characters-long',
            'HS256'
        );

        $request = $this->createRequest(
            'GET',
            '/api/schedules/subscription?schedule_id=test',
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('Unauthorized', $data['error']);
    }
}
