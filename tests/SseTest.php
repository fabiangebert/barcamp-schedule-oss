<?php

namespace Tests;

use App\Schedule;
use App\App;
use App\Auth;
use Doctrine\DBAL\Exception;
use Firebase\JWT\Key;
use Random\RandomException;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Firebase\JWT\JWT;

class SseTest extends BaseTestCase
{
    protected \Slim\App $app;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();

        // Setup env vars for testing
        putenv('MERCURE_PUBLIC_URL=http://mercure/.well-known/mercure');
        putenv('MERCURE_JWT_SECRET=this_is_a_very_long_secret_key_at_least_32_chars');
        putenv('MERCURE_JWT_TIMEOUT=600');
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

    private function getGuestToken(): string
    {
        return Auth::generateJWT('guest@guest.com', 'guest', 'Guest User');
    }

    /**
     * @throws RandomException
     * @throws Exception
     */
    public function testSubscriptionEndpoint()
    {
        $hashedId = Schedule::create('Test Schedule', ['sessions' => []]);

        $request = $this->createRequest(['schedule_id' => $hashedId], [
            'Authorization' => 'Bearer ' . $this->getGuestToken()
        ]);

        $response = $this->app->handle($request);
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertArrayHasKey('url', $data['data']);
        $this->assertArrayHasKey('token', $data['data']);

        $expectedTopic = "schedule/$hashedId";
        $this->assertStringContainsString(urlencode($expectedTopic), $data['data']['url']);

        // Verify JWT
        $key = new Key('this_is_a_very_long_secret_key_at_least_32_chars', 'HS256');
        $decoded = (array) JWT::decode($data['data']['token'], $key);
        $this->assertArrayHasKey('mercure', $decoded);
        $this->assertArrayHasKey('exp', $decoded);

        // Expiration should be 10 minutes in the future (default)
        $this->assertGreaterThan(time() + 500, $decoded['exp']);
        $this->assertLessThanOrEqual(time() + 600, $decoded['exp']);

        $mercure = (array) $decoded['mercure'];
        $this->assertArrayHasKey('subscribe', $mercure);
        $this->assertContains($expectedTopic, (array)$mercure['subscribe']);
    }

    public function testSubscriptionEndpointWithCustomTimeout()
    {
        putenv('MERCURE_JWT_TIMEOUT=3600');
        $hashedId = Schedule::create('Test Schedule 2', ['sessions' => []]);

        $request = $this->createRequest(['schedule_id' => $hashedId], [
            'Authorization' => 'Bearer ' . $this->getGuestToken()
        ]);

        $response = $this->app->handle($request);
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $this->assertEquals(200, $response->getStatusCode());

        // Verify JWT
        $key = new Key('this_is_a_very_long_secret_key_at_least_32_chars', 'HS256');
        $decoded = (array) JWT::decode($data['data']['token'], $key);

        // Expiration should be 1 hour in the future
        $this->assertGreaterThan(time() + 3500, $decoded['exp']);
        $this->assertLessThanOrEqual(time() + 3600, $decoded['exp']);

        putenv('MERCURE_JWT_TIMEOUT');
    }

    public function testSubscriptionEndpointMissingId()
    {
        $request = $this->createRequest([], [
            'Authorization' => 'Bearer ' . $this->getGuestToken()
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(400, $response->getStatusCode());
    }
}
