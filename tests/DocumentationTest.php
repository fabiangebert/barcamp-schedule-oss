<?php

namespace Tests;

use App\Database;
use App\App;
use App\Auth;
use App\Documentation;
use App\Schedule;
use DateMalformedStringException;
use Doctrine\DBAL\Exception;
use Random\RandomException;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class DocumentationTest extends BaseTestCase
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

        // Setup a test schedule
        $this->hashedId = Schedule::create('test-camp', [
            'rooms' => ['Room 1'],
            'timeslots' => ['09:00'],
            'sessions' => [
                ['timeslot' => '09:00', 'room' => 'Room 1', 'title' => 'Test Session']
            ]
        ]);
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

    public function testAddDocumentationGuest()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Test Guest');
        $request = $this->createRequest(
            'POST',
            "/api/schedules/$this->hashedId/sessions/09:00/Room 1/documentation",
            ['Authorization' => "Bearer $jwt"],
            ['content' => 'Hello from Guest', 'session_title' => 'Test Session Title']
        );
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAddDocumentationGuestWithName()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Test Guest');
        $request = $this->createRequest(
            'POST',
            "/api/schedules/$this->hashedId/sessions/09:00/Room 1/documentation",
            ['Authorization' => "Bearer $jwt"],
            [
                'content' => 'Hello from Guest',
                'guest_name' => 'John Doe'
            ]
        );
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testGetDocumentationGuest()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Test Guest');
        $request = $this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/documentation",
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testAddDocumentationSuccess()
    {
        $email = 'user_success@example.com';
        Database::getConnection()->insert('users', ['email' => $email, 'role' => 'user', 'name' => 'Test User']);
        $user = Database::getConnection()->fetchAssociative('SELECT id FROM users WHERE email = ?', [$email]);
        $userId = (int)$user['id'];

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'];

        $request = $this->createRequest(
            'POST',
            "/api/schedules/$this->hashedId/sessions/09:00/Room 1/documentation",
            ['Authorization' => "Bearer $jwt"],
            ['content' => 'Test comment']
        );
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('success', $data['status']);

        $docs = Documentation::getForSession($this->hashedId, '09:00', 'Room 1');
        $this->assertCount(1, $docs);
        $this->assertEquals('Test comment', $docs[0]['content']);
        $this->assertEquals($userId, $docs[0]['user_id']);
        $this->assertEquals('Test User', $docs[0]['display_name']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testAddDocumentationLoggedInWithGuestNameFails()
    {
        $email = 'user_fail@example.com';
        Database::getConnection()->insert('users', ['email' => $email, 'role' => 'user', 'name' => 'Test User']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'];

        $request = $this->createRequest(
            'POST',
            "/api/schedules/$this->hashedId/sessions/09:00/Room 1/documentation",
            ['Authorization' => "Bearer $jwt"],
            [
                'content' => 'Test comment',
                'guest_name' => 'Should not be here'
            ]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status']);
        $this->assertEquals('Logged-in users must not specify a guest_name', $data['error']);
    }

    /**
     * @throws Exception
     */
    public function testAddDocumentationWithMultipleImages()
    {
        $hashedId = $this->hashedId;
        $timeslot = '09:00';
        $room = 'Room 1';
        $content = 'Test with images';
        $imagePaths = ['/api/uploads/0123456789abcdef.jpg', '/api/uploads/fedcba9876543210.jpg'];
        $sessionTitle = 'Image Session';

        Documentation::add($hashedId, $timeslot, $room, $content, $imagePaths, 'Guest', $sessionTitle);

        $docs = Documentation::getForSession($hashedId, $timeslot, $room);
        $lastDoc = end($docs);

        $this->assertEquals($content, $lastDoc['content']);
        $this->assertEquals($sessionTitle, $lastDoc['session_title']);
        $this->assertCount(2, $lastDoc['images']);
        $this->assertContains('/api/uploads/0123456789abcdef.jpg', $lastDoc['images']);
        $this->assertContains('/api/uploads/fedcba9876543210.jpg', $lastDoc['images']);
    }

    public function testExportDocumentationAdminOnly()
    {
        $email = 'guest@guest.com';
        $jwt = Auth::generateJWT($email, 'guest', 'Guest User');

        $request = $this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/export",
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);
        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testExportDocumentationAdminSuccess()
    {
        // Admin
        $adminEmail = 'admin_export@example.com';
        Database::getConnection()->insert('users', ['email' => $adminEmail, 'role' => 'admin', 'name' => 'Admin']);
        $token = Auth::generateMagicToken($adminEmail);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'];

        // Add some documentation first
        Documentation::add($this->hashedId, '09:00', 'Room 1', 'Comment for ZIP', [], 'Guest');

        $request = $this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/export",
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/zip', $response->getHeaderLine('Content-Type'));

        $body = (string)$response->getBody();
        $this->assertNotEmpty($body);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testDeleteDocumentation()
    {
        // Add doc
        Documentation::add($this->hashedId, '09:00', 'Room 1', 'To be deleted', [], 'Guest');
        $docs = Documentation::getForSession($this->hashedId, '09:00', 'Room 1');
        $this->assertCount(1, $docs);
        $id = $docs[0]['id'];

        // Try to delete as Guest (no token)
        $request = $this->createRequest('DELETE', "/api/documentation/$id");
        $response = $this->app->handle($request);
        $this->assertEquals(401, $response->getStatusCode());

        // Delete as Admin
        $adminEmail = 'admin_del@example.com';
        Database::getConnection()->insert('users', ['email' => $adminEmail, 'role' => 'admin', 'name' => 'Admin']);
        $token = Auth::generateMagicToken($adminEmail);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'];

        $request = $this->createRequest('DELETE', "/api/documentation/$id", ['Authorization' => "Bearer $jwt"]);
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        $docs = Documentation::getForSession($this->hashedId, '09:00', 'Room 1');
        $this->assertCount(0, $docs);
    }

    public function testGetNonExistentDocumentationReturns404()
    {
        $email = 'guest@guest.com';
        $jwt = Auth::generateJWT($email, 'guest', 'Guest User');
        $request = $this->createRequest(
            'GET',
            '/api/schedules/non-existent/sessions/10:00/Room1/documentation',
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testDeleteNonExistentDocumentationReturns404()
    {
        $adminEmail = 'admin_404@example.com';
        Database::getConnection()->insert('users', ['email' => $adminEmail, 'role' => 'admin', 'name' => 'Admin']);
        $token = Auth::generateMagicToken($adminEmail);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'];
        $request = $this->createRequest('DELETE', '/api/documentation/999999', ['Authorization' => "Bearer $jwt"]);
        $response = $this->app->handle($request);

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status']);
    }

    public function testGetDocumentationLatestReturnsNullWhenNoDocs()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Guest');
        $request = $this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/documentation/latest",
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertNull($data['latest_timestamp']);
    }

    /**
     * @throws Exception
     */
    public function testGetDocumentationLatestReturnsTimestampAfterAddingDoc()
    {
        Documentation::add($this->hashedId, '09:00', 'Room 1', 'Test content', [], 'Guest');

        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Guest');
        $request = $this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/documentation/latest",
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertNotNull($data['latest_timestamp']);
        $this->assertIsString($data['latest_timestamp']);
    }

    public function testGetDocumentationLatestReturns404ForNonExistentSchedule()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Guest');
        $request = $this->createRequest(
            'GET',
            '/api/schedules/non-existent-id/documentation/latest',
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testGetDocumentationLatestWithoutAuthReturns401()
    {
        $request = $this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/documentation/latest"
        );
        $response = $this->app->handle($request);

        $this->assertEquals(401, $response->getStatusCode());
    }
}
