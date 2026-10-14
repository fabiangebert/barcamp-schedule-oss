<?php

namespace Tests;

use App\App;
use App\Auth;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Uri;
use Slim\Psr7\Stream;

class AuthMiddlewareTest extends BaseTestCase
{
    protected \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
    }

    private function createRequest(string $method, string $path, array $queryParams = [], array $headers = []): Request
    {
        $queryString = http_build_query($queryParams);
        $uri = new Uri('', '', null, $path, $queryString);

        $headersObj = new Headers();
        foreach ($headers as $name => $value) {
            $headersObj->addHeader($name, $value);
        }

        return new Request($method, $uri, $headersObj, [], $_SERVER, new Stream(fopen('php://temp', 'r+')));
    }

    public function testAuthWithHeader()
    {
        $token = Auth::generateJWT('user@test.com', 'user', 'Test User');
        $request = $this->createRequest('GET', '/api/schedules', [], [
            'Authorization' => 'Bearer ' . $token
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAuthWithQueryParam()
    {
        $token = Auth::generateJWT('user@test.com', 'user', 'Test User');
        // This should now FAIL (401) because the fallback was removed
        $request = $this->createRequest('GET', '/api/schedules', ['token' => $token]);

        $response = $this->app->handle($request);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testAuthMissing()
    {
        $request = $this->createRequest('GET', '/api/schedules');

        $response = $this->app->handle($request);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testAuthWithLinkExp()
    {
        // Expired link_exp
        $linkExp = time() - 10;
        $token = Auth::generateJWT('user@test.com', 'user', 'Test User', 3600, null, $linkExp);
        $request = $this->createRequest('GET', '/api/schedules', [], [
            'Authorization' => 'Bearer ' . $token
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(401, $response->getStatusCode());

        // Valid link_exp
        $linkExp = time() + 3600;
        $token = Auth::generateJWT('user@test.com', 'user', 'Test User', 3600, null, $linkExp);
        $request = $this->createRequest('GET', '/api/schedules', [], [
            'Authorization' => 'Bearer ' . $token
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRoleAccess()
    {
        // Route /api/users is admin only
        $token = Auth::generateJWT('user@test.com', 'user', 'Test User');
        $request = $this->createRequest('GET', '/api/users', [], [
            'Authorization' => 'Bearer ' . $token
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(401, $response->getStatusCode());

        $adminToken = Auth::generateJWT('admin@test.com', 'admin', 'Admin User');
        $request = $this->createRequest('GET', '/api/users', [], [
            'Authorization' => 'Bearer ' . $adminToken
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
    }
}
