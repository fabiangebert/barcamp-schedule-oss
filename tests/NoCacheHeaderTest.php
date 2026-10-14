<?php

namespace Tests;

use App\App;
use App\Auth;
use App\Schedule;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class NoCacheHeaderTest extends BaseTestCase
{
    protected \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
    }

    private function createRequest(string $method, string $path, string $jwt = '', ?array $body = null): Request
    {
        $uri = new Uri('', '', null, "/api$path");
        $handle = fopen('php://temp', 'w+');
        if ($body !== null) {
            fwrite($handle, json_encode($body));
        }
        rewind($handle);

        $headersObj = new Headers();
        $headersObj->addHeader('Content-Type', 'application/json');
        if ($jwt) {
            $headersObj->addHeader('Authorization', "Bearer $jwt");
        }

        return new Request($method, $uri, $headersObj, [], $_SERVER, new Stream($handle));
    }

    private function getAdminJwt(): string
    {
        $result = Auth::verifyMagicToken(Auth::generateMagicToken('admin@example.com'));
        return $result['token'] ?? '';
    }

    public function testSchedulesListHasNoCacheHeaders()
    {
        $jwt = $this->getAdminJwt();
        $request = $this->createRequest('GET', '/schedules', $jwt);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->getHeaderLine('Cache-Control')
        );
        $this->assertEquals('no-cache', $response->getHeaderLine('Pragma'));
        $this->assertEquals('0', $response->getHeaderLine('Expires'));
    }

    public function testScheduleGetHasNoCacheHeaders()
    {
        $hashedId = Schedule::create('test', []);

        $request = $this->createRequest('GET', "/schedules/$hashedId");
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->getHeaderLine('Cache-Control')
        );
        $this->assertEquals('no-cache', $response->getHeaderLine('Pragma'));
        $this->assertEquals('0', $response->getHeaderLine('Expires'));
    }

    public function testScheduleDocumentationHasNoCacheHeaders()
    {
        $hashedId = Schedule::create('test-doc', []);
        $jwt = $this->getAdminJwt();
        $request = $this->createRequest('GET', "/schedules/$hashedId/documentation", $jwt);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->getHeaderLine('Cache-Control')
        );
    }

    public function testAuthRoutesDoNotHaveNoCacheHeadersByDefault()
    {
        // This test ensures we didn't accidentally apply it globally if that wasn't intended,
        // although applying it globally might not be bad, the task specified /schedules.
        $request = $this->createRequest('POST', '/auth/magic-link', '', ['email' => 'admin@example.com']);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEmpty($response->getHeaderLine('Cache-Control'));
    }
}
