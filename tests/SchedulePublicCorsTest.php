<?php

namespace Tests;

use App\App;
use App\Schedule;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class SchedulePublicCorsTest extends BaseTestCase
{
    protected \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
    }

    private function createRequest(string $method, string $path, ?array $body = null): Request
    {
        $uri = new Uri('', '', null, "/api$path");
        $handle = fopen('php://temp', 'w+');
        if ($body !== null) {
            fwrite($handle, json_encode($body));
        }
        rewind($handle);

        $headersObj = new Headers();
        $headersObj->addHeader('Content-Type', 'application/json');

        return new Request($method, $uri, $headersObj, [], $_SERVER, new Stream($handle));
    }

    public function testScheduleGetHasCorsHeader()
    {
        $hashedId = Schedule::create('CORS Test Schedule', [
            'rooms' => ['Room 1'],
            'timeslots' => ['10:00'],
            'sessions' => []
        ]);

        $request = $this->createRequest('GET', "/schedules/$hashedId");
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testScheduleOptionsHasCorsPreflightHeaders()
    {
        $hashedId = Schedule::create('CORS Test Schedule', [
            'rooms' => ['Room 1'],
            'timeslots' => ['10:00'],
            'sessions' => []
        ]);

        $request = $this->createRequest('OPTIONS', "/schedules/$hashedId");
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEquals('GET, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('Content-Type', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }
}
