<?php

namespace Tests;

use App\Database;
use App\App;
use Doctrine\DBAL\Exception;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class IntegrationFixTest extends BaseTestCase
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

        return new Request($method, $uri, $headersObj, [], $_SERVER, new Stream($handle));
    }

    /**
     * Test successful login flow which was part of the 500 error report.
     * @throws \Exception
     * @throws Exception
     */
    public function testLoginFlow()
    {
        $email = 'admin@example.com'; // Default admin from BaseTestCase

        // 1. Request magic link
        $request = $this->createRequest(
            'POST',
            '/api/auth/magic-link',
            ['Content-Type' => 'application/json'],
            ['email' => $email]
        );
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        // In the updated version, token is NOT in the response but in the database
        $conn = Database::getConnection();
        $loginToken = $conn->fetchAssociative('SELECT token FROM login_tokens WHERE email = ?', [$email]);
        $token = $loginToken['token'];
        $this->assertNotEmpty($token);

        // 2. Login with token
        $request = $this->createRequest('GET', '/api/auth/login');
        $request = $request->withQueryParams(['token' => $token]);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        $this->assertArrayHasKey('token', $data);
        $this->assertEquals($email, $data['user']['sub']);
    }
}
