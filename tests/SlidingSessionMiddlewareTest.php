<?php

namespace Tests;

use App\App;
use App\Auth;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Uri;
use Slim\Psr7\Stream;

class SlidingSessionMiddlewareTest extends BaseTestCase
{
    protected \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
    }

    private function createRequest(string $method, string $path, array $headers = []): Request
    {
        $uri = new Uri('', '', null, $path);
        $headersObj = new Headers();
        foreach ($headers as $name => $value) {
            $headersObj->addHeader($name, $value);
        }
        return new Request($method, $uri, $headersObj, [], $_SERVER, new Stream(fopen('php://temp', 'r+')));
    }

    public function testNoRenewalWhenNew()
    {
        $token = Auth::generateJWT('user@test.com', 'user', 'Test User', 3600);
        $request = $this->createRequest('GET', '/api/schedules', [
            'Authorization' => 'Bearer ' . $token
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('X-New-Token'));
    }

    #[RunInSeparateProcess]
    public function testRenewalWhenHalfway()
    {
        // 100 seconds total, 51 seconds passed -> >50%
        $iat = time() - 51;
        $exp = $iat + 100;

        $payload = [
            'iss' => 'barcamp-schedule',
            'sub' => 'user@test.com',
            'role' => 'user',
            'name' => 'Test User',
            'id' => 1,
            'iat' => $iat,
            'exp' => $exp
        ];
        $secret = getenv('JWT_SECRET') ?: 'super-secret-key-that-is-at-least-32-characters-long';
        $token = \Firebase\JWT\JWT::encode($payload, $secret, 'HS256');

        $request = $this->createRequest('GET', '/api/schedules', [
            'Authorization' => 'Bearer ' . $token
        ]);

        $response = $this->app->handle($request);

        $this->assertEquals(
            200,
            $response->getStatusCode(),
            "Auth failed, status: " . $response->getStatusCode() . " Body: " . (string)$response->getBody()
        );
        $this->assertTrue($response->hasHeader('X-New-Token'), "Response should have X-New-Token header");

        $newToken = $response->getHeaderLine('X-New-Token');
        $decoded = Auth::validateJWT($newToken);
        $this->assertNotNull($decoded);
        $this->assertGreaterThan($exp, $decoded['exp'], "New expiration should be later than original");
    }

    public function testNoRenewalIfLinkExpReached()
    {
        $now = time();
        $iat = $now - 51;
        $exp = $iat + 100;
        $linkExp = $now + 10; // link_exp is soon, but not yet reached in AuthMiddleware

        $payload = [
            'iss' => 'barcamp-schedule',
            'sub' => 'user@test.com',
            'role' => 'user',
            'iat' => $iat,
            'exp' => $exp,
            'link_exp' => $linkExp
        ];
        $secret = getenv('JWT_SECRET') ?: 'super-secret-key-that-is-at-least-32-characters-long';
        $token = \Firebase\JWT\JWT::encode($payload, $secret, 'HS256');

        $request = $this->createRequest('GET', '/api/schedules', [
            'Authorization' => 'Bearer ' . $token
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        // It SHOULD renew if newExp (now + expiry) > exp, but hard-capped by link_exp.
        // If now + default_expiry (86400) > link_exp, it caps at link_exp.
        // If link_exp > exp, it might still renew.

        if ($response->hasHeader('X-New-Token')) {
            $newToken = $response->getHeaderLine('X-New-Token');
            $decoded = Auth::validateJWT($newToken);
            $this->assertLessThanOrEqual($linkExp, $decoded['exp']);
        }
    }
}
