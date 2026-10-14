<?php

namespace Tests;

use App\App;
use App\Auth;
use Doctrine\DBAL\Exception;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class JwtExpirationTest extends BaseTestCase
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

    private function createRequest(string $method, string $path, array $headers = []): Request
    {
        $uri = new Uri('', '', null, $path);
        $headersObj = new Headers();
        foreach ($headers as $name => $value) {
            $headersObj->addHeader($name, $value);
        }
        $headersObj->addHeader('Content-Type', 'application/json');

        return new Request($method, $uri, $headersObj, [], $_SERVER, new Stream(fopen('php://temp', 'r+')));
    }

    public function testExpiredJwtReturns401()
    {
        // Generate a JWT that is already expired
        // We can't easily change Auth::generateJWT to take a custom iat/exp without modifying it,
        // but we can see that it uses time() + (3600 * $expiryHours).

        $jwt = Auth::generateJWT('test@example.com', 'user', null, 0);

        // Wait 2 seconds to ensure it's expired
        sleep(2);

        $request = $this->createRequest('GET', '/api/schedules', ['Authorization' => "Bearer $jwt"]);
        $response = $this->app->handle($request);

        $this->assertEquals(401, $response->getStatusCode());
        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        $this->assertEquals('Unauthorized', $data['error']);
    }
}
