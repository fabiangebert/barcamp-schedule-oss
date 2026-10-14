<?php

namespace Tests;

use App\App;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class HealthCheckTest extends BaseTestCase
{
    protected \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
    }

    private function createRequest(string $method, string $path, ?array $body = null): Request
    {
        $uri = new Uri('', '', null, $path);
        $handle = fopen('php://temp', 'w+');
        if ($body !== null) {
            fwrite($handle, json_encode($body));
        }
        rewind($handle);

        $headersObj = new Headers();
        $headersObj->addHeader('Content-Type', 'application/json');

        return new Request($method, $uri, $headersObj, [], $_SERVER, new Stream($handle));
    }

    public function testHealthCheckReturnsOk()
    {
        $request = $this->createRequest('GET', '/api/health');
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string)$response->getBody(), true);
        $this->assertIsArray($data);
        $this->assertEquals('ok', $data['status']);
        $this->assertArrayHasKey('timestamp', $data);
    }
}
