<?php

namespace Tests;

use App\Database;
use App\App;
use App\Auth;
use App\Schedule;
use App\Documentation;
use Doctrine\DBAL\Exception;
use Random\RandomException;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class DocumentationDeleteTest extends BaseTestCase
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

    private function createRequest(string $path, array $headers = []): Request
    {
        $uri = new Uri('', '', null, $path);
        $handle = fopen('php://temp', 'w+');
        rewind($handle);

        $headersObj = new Headers();
        foreach ($headers as $name => $value) {
            $headersObj->addHeader($name, $value);
        }

        return new Request('DELETE', $uri, $headersObj, [], $_SERVER, new Stream($handle));
    }

    /**
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testDocumentationDeleteUpdatesChangeTracker()
    {
        // 1. Create a schedule
        $hashedId = Schedule::create('Test Schedule', ['sessions' => []]);

        // 2. Add documentation
        Documentation::add($hashedId, '10:00', 'Room A', 'user@example.com', 'Initial content');

        $conn = Database::getConnection();
        $docId = $conn->lastInsertId();

        // 4. Authenticate as admin
        $jwt = Auth::generateJWT('admin@example.com', 'admin');

        // 5. Delete documentation via API
        $request = $this->createRequest('/api/documentation/' . $docId, [
            'Authorization' => 'Bearer ' . $jwt,
            'Content-Type' => 'application/json'
        ]);

        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * A user must be able to delete their own documentation entry.
     *
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testUserCanDeleteOwnDocumentation()
    {
        $conn = Database::getConnection();
        $hashedId = Schedule::create('Owner Delete Test', ['sessions' => []]);

        // Insert a user and capture its auto-increment id
        $conn->insert('users', ['email' => 'owner@example.com', 'role' => 'user']);
        $ownerId = (int)$conn->lastInsertId();

        // Add documentation as this user (user_id is set)
        Documentation::add(
            $hashedId,
            '11:00',
            'Room B',
            'My notes',
            [],
            null,
            null,
            null,
            null,
            $ownerId
        );
        $docId = (int)$conn->lastInsertId();

        $jwt = Auth::generateJWT('owner@example.com', 'user', null, null, $ownerId);

        $request = $this->createRequest('/api/documentation/' . $docId, [
            'Authorization' => 'Bearer ' . $jwt,
            'Content-Type' => 'application/json'
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        // Verify it is actually gone
        $remaining = $conn->fetchAssociative('SELECT id FROM documentation WHERE id = ?', [$docId]);
        $this->assertFalse($remaining);
    }

    /**
     * A user must NOT be able to delete a documentation entry that belongs
     * to a different user (object-level authorization / IDOR fix).
     *
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testUserCannotDeleteOthersDocumentation()
    {
        $conn = Database::getConnection();
        $hashedId = Schedule::create('IDOR Test', ['sessions' => []]);

        // Owner creates a doc
        $conn->insert('users', ['email' => 'owner@example.com', 'role' => 'user']);
        $ownerId = (int)$conn->lastInsertId();
        Documentation::add(
            $hashedId,
            '11:00',
            'Room B',
            'Owner notes',
            [],
            null,
            null,
            null,
            null,
            $ownerId
        );
        $docId = (int)$conn->lastInsertId();

        // A different user tries to delete it
        $conn->insert('users', ['email' => 'attacker@example.com', 'role' => 'user']);
        $attackerId = (int)$conn->lastInsertId();
        $jwt = Auth::generateJWT('attacker@example.com', 'user', null, null, $attackerId);

        $request = $this->createRequest('/api/documentation/' . $docId, [
            'Authorization' => 'Bearer ' . $jwt,
            'Content-Type' => 'application/json'
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(403, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        $this->assertEquals('Forbidden: ownership required', $body['error']);

        // Verify the doc still exists
        $remaining = $conn->fetchAssociative('SELECT id FROM documentation WHERE id = ?', [$docId]);
        $this->assertNotFalse($remaining);
    }

    /**
     * An admin may delete any user's documentation entry.
     *
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testAdminCanDeleteAnyDocumentation()
    {
        $conn = Database::getConnection();
        $hashedId = Schedule::create('Admin Delete Test', ['sessions' => []]);

        $conn->insert('users', ['email' => 'owner@example.com', 'role' => 'user']);
        $ownerId = (int)$conn->lastInsertId();
        Documentation::add(
            $hashedId,
            '12:00',
            'Room C',
            'Owner notes',
            [],
            null,
            null,
            null,
            null,
            $ownerId
        );
        $docId = (int)$conn->lastInsertId();

        // admin@example.com is seeded by BaseTestCase
        $admin = $conn->fetchAssociative("SELECT id FROM users WHERE email = 'admin@example.com'");
        $adminId = (int)$admin['id'];
        $jwt = Auth::generateJWT('admin@example.com', 'admin', null, null, $adminId);

        $request = $this->createRequest('/api/documentation/' . $docId, [
            'Authorization' => 'Bearer ' . $jwt,
            'Content-Type' => 'application/json'
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        $remaining = $conn->fetchAssociative('SELECT id FROM documentation WHERE id = ?', [$docId]);
        $this->assertFalse($remaining);
    }

    /**
     * Per project policy, guest-created documentation entries (user_id IS NULL)
     * may be deleted by any authenticated user.
     *
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testAnyUserCanDeleteGuestDocumentation()
    {
        $conn = Database::getConnection();
        $hashedId = Schedule::create('Guest Doc Test', ['sessions' => []]);

        // Guest-created doc: userId = null
        Documentation::add(
            $hashedId,
            '13:00',
            'Room D',
            'Guest notes',
            [],
            'Guest Name',
            null,
            null,
            'guest-xyz',
            null
        );
        $docId = (int)$conn->lastInsertId();

        // Confirm it really is a guest doc
        $row = $conn->fetchAssociative('SELECT user_id FROM documentation WHERE id = ?', [$docId]);
        $this->assertNull($row['user_id']);

        $conn->insert('users', ['email' => 'randomuser@example.com', 'role' => 'user']);
        $randomUserId = (int)$conn->lastInsertId();
        $jwt = Auth::generateJWT('randomuser@example.com', 'user', null, null, $randomUserId);

        $request = $this->createRequest('/api/documentation/' . $docId, [
            'Authorization' => 'Bearer ' . $jwt,
            'Content-Type' => 'application/json'
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        $remaining = $conn->fetchAssociative('SELECT id FROM documentation WHERE id = ?', [$docId]);
        $this->assertFalse($remaining);
    }
}
