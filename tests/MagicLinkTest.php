<?php

namespace Tests;

use App\App;
use App\Auth;
use App\Database;
use Doctrine\DBAL\Exception;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class MagicLinkTest extends BaseTestCase
{
    protected \Slim\App $app;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
    }

    private function createRequest(array $headers = [], ?array $body = null): Request
    {
        $uri = new Uri('', '', null, '/api/auth/magic-link');
        $handle = fopen('php://temp', 'w+');
        if ($body !== null) {
            fwrite($handle, json_encode($body));
        }
        rewind($handle);

        $headersObj = new Headers();
        foreach ($headers as $name => $value) {
            $headersObj->addHeader($name, $value);
        }

        return new Request('POST', $uri, $headersObj, [], $_SERVER, new Stream($handle));
    }

    public function testMagicLinkAlwaysReturns200AndIdenticalBody()
    {
        // 1. Existing user
        $email = 'admin@example.com';
        $request = $this->createRequest(['Content-Type' => 'application/json'], ['email' => $email]);
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
        $data1 = json_decode((string)$response->getBody(), true);
        $this->assertEquals(['status' => 'success', 'message' => 'Magic link sent'], $data1);

        // 2. Non-existing user
        $email = 'nonexistent@example.com';
        $request = $this->createRequest(['Content-Type' => 'application/json'], ['email' => $email]);
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
        $data2 = json_decode((string)$response->getBody(), true);
        $this->assertEquals(['status' => 'success', 'message' => 'Magic link sent'], $data2);

        // 3. Invalid email
        $email = 'not-an-email';
        $request = $this->createRequest(['Content-Type' => 'application/json'], ['email' => $email]);
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
        $data3 = json_decode((string)$response->getBody(), true);
        $this->assertEquals(['status' => 'success', 'message' => 'Magic link sent'], $data3);

        $this->assertEquals($data1, $data2);
        $this->assertEquals($data1, $data3);
    }

    /**
     * A magic token must be consumable exactly once. A second call with the
     * same token must return null, preventing the TOCTOU race-condition
     * replay where two concurrent requests both generate valid JWTs.
     */
    public function testMagicTokenCannotBeConsumedTwice()
    {
        $email = 'replay@example.com';
        Database::getConnection()->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $this->assertNotNull($token);

        // First consume succeeds
        $first = Auth::verifyMagicToken($token);
        $this->assertIsArray($first);
        $this->assertNotEmpty($first['token']);

        // Second consume with the same token must fail
        $second = Auth::verifyMagicToken($token);
        $this->assertNull($second);
    }
}
