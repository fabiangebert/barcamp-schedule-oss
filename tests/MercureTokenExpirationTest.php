<?php

namespace Tests;

use App\Schedule;
use App\App;
use App\Auth;
use Firebase\JWT\Key;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Firebase\JWT\JWT;

class MercureTokenExpirationTest extends BaseTestCase
{
    protected \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();

        // Setup env vars for testing
        putenv('MERCURE_PUBLIC_URL=http://mercure/.well-known/mercure');
        putenv('MERCURE_JWT_SECRET=this_is_a_very_long_secret_key_at_least_32_chars');
        putenv('MERCURE_JWT_TIMEOUT=3600'); // 1 hour
    }

    protected function tearDown(): void
    {
        putenv('MERCURE_PUBLIC_URL');
        putenv('MERCURE_JWT_SECRET');
        putenv('MERCURE_JWT_TIMEOUT');
        parent::tearDown();
    }

    private function createRequest(array $queryParams = [], array $headers = []): Request
    {
        $queryString = http_build_query($queryParams);
        $uri = new Uri('', '', null, '/api/schedules/subscription', $queryString);

        $headersObj = new Headers();
        foreach ($headers as $name => $value) {
            $headersObj->addHeader($name, $value);
        }

        return new Request('GET', $uri, $headersObj, [], $_SERVER, new Stream(fopen('php://temp', 'r+')));
    }

    public function testMercureTokenExpCappedByLinkExp()
    {
        $hashedId = Schedule::create('Test Schedule', ['sessions' => []]);

        // Link expires in 5 minutes
        $linkExp = time() + 300;
        $token = Auth::generateJWT('guest@guest.com', 'guest', 'Guest User', 3600, null, $linkExp);

        $request = $this->createRequest(['schedule_id' => $hashedId], [
            'Authorization' => 'Bearer ' . $token
        ]);

        $response = $this->app->handle($request);
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $this->assertEquals(200, $response->getStatusCode());

        // Verify Mercure JWT
        $key = new Key('this_is_a_very_long_secret_key_at_least_32_chars', 'HS256');
        $decoded = (array) JWT::decode($data['data']['token'], $key);

        // Mercure JWT timeout is 3600, but it should be capped by linkExp (300)
        // We allow 2 seconds difference for processing time
        $this->assertLessThanOrEqual($linkExp, $decoded['exp']);
        $this->assertGreaterThanOrEqual($linkExp - 2, $decoded['exp']);
    }

    public function testMercureTokenExpNotCappedIfLinkExpIsFurtherInFuture()
    {
        $hashedId = Schedule::create('Test Schedule', ['sessions' => []]);

        // Link expires in 2 hours
        $linkExp = time() + 7200;
        $token = Auth::generateJWT('guest@guest.com', 'guest', 'Guest User', 3600, null, $linkExp);

        $request = $this->createRequest(['schedule_id' => $hashedId], [
            'Authorization' => 'Bearer ' . $token
        ]);

        $response = $this->app->handle($request);
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $this->assertEquals(200, $response->getStatusCode());

        // Verify Mercure JWT
        $key = new Key('this_is_a_very_long_secret_key_at_least_32_chars', 'HS256');
        $decoded = (array) JWT::decode($data['data']['token'], $key);

        // Mercure JWT timeout is 3600, which is less than linkExp (7200), so it should NOT be capped
        $expectedExp = time() + 3600;
        $this->assertLessThanOrEqual($expectedExp, $decoded['exp']);
        $this->assertGreaterThanOrEqual($expectedExp - 2, $decoded['exp']);
    }
}
