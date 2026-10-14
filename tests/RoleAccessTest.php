<?php

namespace Tests;

use App\Database;
use App\App;
use App\Auth;
use App\Schedule;
use App\Documentation;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Random\RandomException;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;

class RoleAccessTest extends BaseTestCase
{
    protected \Slim\App $app;

    protected ?string $hashedId;

    /**
     * @throws RandomException
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();

        // Setup initial data
        $this->hashedId = Schedule::create('test-schedule', ['sessions' => []]);
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
        $headersObj->addHeader('Content-Type', 'application/json');

        return new Request($method, $uri, $headersObj, [], $_SERVER, new Stream($handle));
    }

    private function getGuestToken(): string
    {
        return Auth::generateJWT('guest@guest.com', 'guest', 'Guest User');
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    private function getUserToken(): string
    {
        $conn = Database::getConnection();
        $conn->delete('users', ['email' => 'user@example.com']);
        $conn->insert('users', ['email' => 'user@example.com', 'role' => 'user']);
        return Auth::generateJWT('user@example.com', 'user', 'Regular User');
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    private function getAdminToken(): string
    {
        $conn = Database::getConnection();
        $conn->delete('users', ['email' => 'admin@example.com']);
        $conn->insert('users', ['email' => 'admin@example.com', 'role' => 'admin']);
        return Auth::generateJWT('admin@example.com', 'admin', 'Admin User');
    }

    #[RunInSeparateProcess]
    public function testGuestAccess()
    {
        putenv('APP_ENV=test');
        $token = $this->getGuestToken();
        $authHeader = ['Authorization' => "Bearer $token"];

        // Allowed: ScheduleViewer.vue (GET /schedules/{id})
        $response = $this->app->handle(
            $this->createRequest('GET', "/api/schedules/$this->hashedId", $authHeader)
        );
        $this->assertEquals(200, $response->getStatusCode(), "Guest should access schedule viewer");

        // Allowed: SessionDocumentation.vue (GET /.../documentation, POST /.../documentation)
        $docPath = "/api/schedules/$this->hashedId/sessions/10:00/Room%201/documentation";
        $response = $this->app->handle(
            $this->createRequest('GET', $docPath, $authHeader)
        );
        $this->assertEquals(200, $response->getStatusCode(), "Guest should view session docs");

        $response = $this->app->handle(
            $this->createRequest('POST', $docPath, $authHeader, ['content' => 'Guest comment'])
        );
        $this->assertEquals(200, $response->getStatusCode(), "Guest should add session docs");

        // NOT Allowed: ScheduleList.vue (GET /schedules)
        $response = $this->app->handle(
            $this->createRequest('GET', '/api/schedules', $authHeader)
        );
        $this->assertEquals(401, $response->getStatusCode(), "Guest should NOT access schedule list");

        // Allowed: Documentation Overview (GET /schedules/{id}/documentation)
        // (Changed to allowed for contribution flow)
        $response = $this->app->handle(
            $this->createRequest('GET', "/api/schedules/$this->hashedId/documentation", $authHeader)
        );
        $this->assertEquals(200, $response->getStatusCode(), "Guest should access docs overview");

        // NOT Allowed: Delete Documentation
        $response = $this->app->handle($this->createRequest('DELETE', '/api/documentation/1', $authHeader));
        $this->assertEquals(401, $response->getStatusCode(), "Guest should NOT delete documentation");
    }

    /**
     * @throws Exception
     */
    public function testUserAccess()
    {
        $token = $this->getUserToken();
        $authHeader = ['Authorization' => "Bearer $token"];

        // Allowed: ScheduleList.vue
        $response = $this->app->handle(
            $this->createRequest('GET', '/api/schedules', $authHeader)
        );
        $this->assertEquals(200, $response->getStatusCode(), "User should access schedule list");

        // Allowed: DocumentationOverview.vue
        $response = $this->app->handle(
            $this->createRequest('GET', "/api/schedules/$this->hashedId/documentation", $authHeader)
        );
        $this->assertEquals(200, $response->getStatusCode(), "User should access docs overview");

        // Allowed: Delete Documentation
        // First add a doc
        Documentation::add($this->hashedId, '10:00', 'Room 1', 'user@example.com', 'Test content');
        $response = $this->app->handle($this->createRequest('DELETE', '/api/documentation/1', $authHeader));
        $this->assertEquals(200, $response->getStatusCode(), "User should delete documentation");

        // Allowed: Export Documentation
        // Need to add documentation first so it doesn't 404
        Documentation::add($this->hashedId, '10:00', 'Room 1', 'user@example.com', 'Test content');
        $response = $this->app->handle(
            $this->createRequest('GET', "/api/schedules/$this->hashedId/export", $authHeader)
        );
        $this->assertEquals(200, $response->getStatusCode(), "User should export documentation");

        // NOT Allowed: Delete Schedule
        $response = $this->app->handle($this->createRequest('DELETE', "/api/schedules/$this->hashedId", $authHeader));
        $this->assertEquals(401, $response->getStatusCode(), "User should NOT delete schedule");
    }

    /**
     * @throws Exception
     */
    public function testAdminAccess()
    {
        $token = $this->getAdminToken();
        $authHeader = ['Authorization' => "Bearer $token"];

        // Allowed: Export Documentation
        Documentation::add($this->hashedId, '10:00', 'Room 1', 'admin@example.com', 'Admin doc');
        $response = $this->app->handle(
            $this->createRequest('GET', "/api/schedules/$this->hashedId/export", $authHeader)
        );
        $this->assertEquals(200, $response->getStatusCode(), "Admin should export documentation");

        // Allowed: Delete Schedule
        $response = $this->app->handle($this->createRequest('DELETE', "/api/schedules/$this->hashedId", $authHeader));
        $this->assertEquals(200, $response->getStatusCode(), "Admin should delete schedule");
    }
}
