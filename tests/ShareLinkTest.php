<?php

namespace Tests;

use App\Database;
use App\App;
use App\Auth;
use App\ShareLink;
use DateMalformedStringException;
use DateTime;
use Doctrine\DBAL\Exception;
use Random\RandomException;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class ShareLinkTest extends BaseTestCase
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

    private function createRequest(string $method, string $path, array $headers = [], ?array $body = null): Request
    {
        $pathname = $path;
        $query = '';
        if (str_contains($path, '?')) {
            [$pathname, $query] = explode('?', $path, 2);
        }
        $uri = new Uri('', '', null, $pathname);
        if ($query !== '') {
            $uri = $uri->withQuery($query);
        }
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

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     * @throws \Exception
     */
    public function testCreateShareLink()
    {
        $email = 'user@example.com';
        Database::getConnection()->insert('users', ['email' => $email, 'role' => 'user']);
        $result = Auth::verifyMagicToken(Auth::generateMagicToken($email));
        $token = $result['token'] ?? '';

        $scheduleId = bin2hex(random_bytes(16));
        Database::getConnection()->insert('schedules', [
            'name' => 'test-schedule',
            'hashed_id' => $scheduleId,
            'data' => json_encode([]),
            'version' => 1
        ]);

        $request = $this->createRequest(
            'POST',
            '/api/share-links',
            ['Authorization' => "Bearer $token"],
            ['schedule_id' => $scheduleId]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertArrayHasKey('token', $data);

        $links = ShareLink::getAllByUser($email);
        $this->assertCount(1, $links);
        $this->assertEquals($scheduleId, $links[0]['schedule_id']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testGuestLoginWithShareToken()
    {
        $email = 'user@example.com';
        $scheduleName = 'shared-camp';
        $scheduleId = bin2hex(random_bytes(16));
        Database::getConnection()->insert('schedules', [
            'name' => $scheduleName,
            'hashed_id' => $scheduleId,
            'data' => json_encode([]),
            'version' => 1
        ]);
        $shareToken = ShareLink::create($scheduleId, $email);

        $request = $this->createRequest('GET', "/api/auth/login?share_token=$shareToken");
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('user', $data);
        $this->assertEquals('guest', $data['user']['role']);
        $this->assertNotEmpty($data['user']['name']);
        $this->assertEquals($scheduleId, $data['redirect_schedule']);
        $this->assertArrayHasKey('link_exp', $data['user']);
        $this->assertNotEmpty($data['user']['link_exp']);

        // Verify guest name is one of the expected formats
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $data['user']['name']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testExpiredShareLink()
    {
        $email = 'user@example.com';
        $scheduleName = 'shared-camp';
        $scheduleId = bin2hex(random_bytes(16));
        $conn = Database::getConnection();
        $shareToken = bin2hex(random_bytes(16));
        $expiresAt = (new DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');

        // FK enforcement requires the parent schedule row to exist.
        $conn->insert('schedules', [
            'name' => $scheduleName,
            'hashed_id' => $scheduleId,
            'data' => json_encode([]),
            'version' => 1
        ]);

        $conn->insert('share_links', [
            'schedule_id' => $scheduleId,
            'token' => $shareToken,
            'created_by' => $email,
            'expires_at' => $expiresAt
        ]);

        $request = $this->createRequest('GET', "/api/auth/login?share_token=$shareToken");
        $response = $this->app->handle($request);

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status']);
        $this->assertEquals('Invalid or expired share link', $data['error']);
    }

    /**
     * @throws RandomException
     * @throws DateMalformedStringException
     * @throws Exception
     * @throws \Exception
     */
    public function testDeleteShareLink()
    {
        $email = 'user@example.com';
        Database::getConnection()->insert('users', ['email' => $email, 'role' => 'user']);
        $result = Auth::verifyMagicToken(Auth::generateMagicToken($email));
        $token = $result['token'] ?? '';

        $scheduleId = bin2hex(random_bytes(16));
        Database::getConnection()->insert('schedules', [
            'name' => 'delete-link-schedule',
            'hashed_id' => $scheduleId,
            'data' => json_encode([]),
            'version' => 1
        ]);
        $shareToken = ShareLink::create($scheduleId, $email);
        $links = ShareLink::getAllByUser($email);
        $id = $links[0]['id'];

        $request = $this->createRequest('DELETE', "/api/share-links/$id", ['Authorization' => "Bearer $token"]);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertCount(0, ShareLink::getAllByUser($email));
    }

    /**
     * @throws RandomException
     * @throws DateMalformedStringException
     * @throws Exception
     * @throws \Exception
     * @throws \Exception
     */
    public function testCreateShareLinkWithExpiry()
    {
        $email = 'user@expiry-test.com';
        Database::getConnection()->insert('users', ['email' => $email, 'role' => 'user']);
        $result = Auth::verifyMagicToken(Auth::generateMagicToken($email));
        $token = $result['token'] ?? '';

        $scheduleId = bin2hex(random_bytes(16));
        // Create schedule so it exists
        Database::getConnection()->insert('schedules', [
            'name' => 'expiry-schedule',
            'hashed_id' => $scheduleId,
            'data' => json_encode([]),
            'version' => 1
        ]);
        $expiry = (new DateTime())->modify('+2 weeks')->format('Y-m-d H:i:s');

        $request = $this->createRequest(
            'POST',
            '/api/share-links',
            ['Authorization' => "Bearer $token"],
            ['schedule_id' => $scheduleId, 'expires_at' => $expiry]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('success', $data['status']);
        $links = ShareLink::getAllByUser($email);
        $this->assertCount(1, $links);
        $this->assertEquals($expiry, $links[0]['expires_at']);
    }

    /**
     * @throws RandomException
     * @throws DateMalformedStringException
     * @throws Exception
     * @throws \Exception
     */
    public function testUpdateShareLinkExpiry()
    {
        $email = 'user@update-expiry.com';
        Database::getConnection()->insert('users', ['email' => $email, 'role' => 'user']);
        $result = Auth::verifyMagicToken(Auth::generateMagicToken($email));
        $token = $result['token'] ?? '';

        $scheduleId = bin2hex(random_bytes(16));
        Database::getConnection()->insert('schedules', [
            'name' => 'update-expiry-schedule',
            'hashed_id' => $scheduleId,
            'data' => json_encode([]),
            'version' => 1
        ]);
        ShareLink::create($scheduleId, $email);
        $links = ShareLink::getAllByUser($email);
        $id = $links[0]['id'];

        $newExpiry = (new DateTime())->modify('+1 month')->format('Y-m-d H:i:s');
        $request = $this->createRequest(
            'PATCH',
            "/api/share-links/$id",
            ['Authorization' => "Bearer $token"],
            ['expires_at' => $newExpiry]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $updatedLinks = ShareLink::getAllByUser($email);
        $this->assertEquals($newExpiry, $updatedLinks[0]['expires_at']);
    }

    /**
     * @throws RandomException
     * @throws DateMalformedStringException
     * @throws Exception
     * @throws \Exception
     */
    public function testAdminCanManageOtherUsersShareLinks()
    {
        $userEmail = 'user-admin-test@example.com';
        $adminEmail = 'admin@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $userEmail, 'role' => 'user']);
        // admin@example.com is already inserted by BaseTestCase

        $adminLogin = Auth::verifyMagicToken(Auth::generateMagicToken($adminEmail));
        $adminToken = $adminLogin['token'] ?? '';

        $scheduleId = bin2hex(random_bytes(16));
        $conn->insert('schedules', [
            'name' => 'admin-test-schedule',
            'hashed_id' => $scheduleId,
            'data' => json_encode([]),
            'version' => 1
        ]);

        // User creates a share link
        ShareLink::create($scheduleId, $userEmail);
        $userLinks = ShareLink::getBySchedule($scheduleId, $userEmail);
        $this->assertCount(1, $userLinks);
        $linkId = $userLinks[0]['id'];

        // Admin should see it
        $request = $this->createRequest(
            'GET',
            "/api/share-links?schedule_id=$scheduleId",
            ['Authorization' => "Bearer $adminToken"]
        );
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertCount(1, $data['data']);
        $this->assertEquals($userEmail, $data['data'][0]['created_by']);

        // Admin should be able to update it
        $newExpiry = (new DateTime())->modify('+1 month')->format('Y-m-d H:i:s');
        $request = $this->createRequest(
            'PATCH',
            "/api/share-links/$linkId",
            ['Authorization' => "Bearer $adminToken"],
            ['expires_at' => $newExpiry]
        );
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        // Verify update
        $updatedLinks = ShareLink::getBySchedule($scheduleId, $userEmail, true);
        $this->assertEquals($newExpiry, $updatedLinks[0]['expires_at']);

        // Admin should be able to delete it
        $request = $this->createRequest(
            'DELETE',
            "/api/share-links/$linkId",
            ['Authorization' => "Bearer $adminToken"]
        );
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        // Verify deletion
        $this->assertCount(0, ShareLink::getBySchedule($scheduleId, $userEmail, true));
    }

    /**
     * Standard users must NOT be able to view, modify, or delete share links
     * created by other users. Only the link owner or an admin may do so.
     *
     * @throws RandomException
     * @throws DateMalformedStringException
     * @throws Exception
     * @throws \Exception
     */
    public function testUserCannotManageOtherUsersShareLinks()
    {
        $user1Email = 'user1@example.com';
        $user2Email = 'user2@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $user1Email, 'role' => 'user']);
        $conn->insert('users', ['email' => $user2Email, 'role' => 'user']);

        $user1Login = Auth::verifyMagicToken(Auth::generateMagicToken($user1Email));
        $user1Token = $user1Login['token'] ?? '';

        $scheduleId = bin2hex(random_bytes(16));
        $conn->insert('schedules', [
            'name' => 'user-test-schedule',
            'hashed_id' => $scheduleId,
            'data' => json_encode([]),
            'version' => 1
        ]);

        // User 2 creates a share link
        ShareLink::create($scheduleId, $user2Email);
        $user2Links = ShareLink::getBySchedule($scheduleId, $user2Email);
        $this->assertCount(1, $user2Links);
        $linkId = $user2Links[0]['id'];

        // User 1 must NOT see user 2's link (only own links)
        $request = $this->createRequest(
            'GET',
            "/api/share-links?schedule_id=$scheduleId",
            ['Authorization' => "Bearer $user1Token"]
        );
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertCount(0, $data['data']);

        // User 1 must NOT be able to update user 2's link
        $newExpiry = (new DateTime())->modify('+1 month')->format('Y-m-d H:i:s');
        $request = $this->createRequest(
            'PATCH',
            "/api/share-links/$linkId",
            ['Authorization' => "Bearer $user1Token"],
            ['expires_at' => $newExpiry]
        );
        $response = $this->app->handle($request);
        $this->assertEquals(404, $response->getStatusCode());

        // User 2's link expiry must be unchanged
        $user2LinksAfter = ShareLink::getBySchedule($scheduleId, $user2Email);
        $this->assertNotEquals($newExpiry, $user2LinksAfter[0]['expires_at']);

        // User 1 must NOT be able to delete user 2's link
        $request = $this->createRequest(
            'DELETE',
            "/api/share-links/$linkId",
            ['Authorization' => "Bearer $user1Token"]
        );
        $response = $this->app->handle($request);
        $this->assertEquals(404, $response->getStatusCode());

        // Verify the link still exists
        $this->assertCount(1, ShareLink::getBySchedule($scheduleId, $user2Email));
    }
}
