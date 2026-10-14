<?php

namespace Tests;

use App\App;
use App\Auth;
use App\Database;
use App\ShareLink;
use App\Schedule;
use Exception;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;
use Slim\Psr7\Headers;

class SessionAutomationTest extends BaseTestCase
{
    protected \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
    }

    private function createRequest(string $method, string $path, array $headers = [], ?array $body = null): Request
    {
        $parts = explode('?', $path);
        $uriPath = $parts[0];
        $queryString = $parts[1] ?? '';
        $uri = new Uri('', 'localhost', null, $uriPath, $queryString);
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
     * Automates Manual Testing Plan 1: Sliding Sessions (Auto-Renewal)
     * @throws Exception
     */
    public function testSlidingSessionAutoRenewal()
    {
        // 1. Setup: Short JWT expiry for testing
        putenv('JWT_EXPIRY_SECONDS=10');
        $_ENV['JWT_EXPIRY_SECONDS'] = '10';

        // 2. Log in as a user
        $email = 'user@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        // Manual login simulation
        $jwt = Auth::generateJWT($email, 'user', 'Test User', 10);
        $this->assertNotNull($jwt);

        // 3. Perform an action immediately (should not renew yet as < 50% passed)
        // Note: time() is used in middleware, so we might need to sleep or mock time if possible.
        // For this POC, we'll use sleep for simplicity, though mocking is better for CI.

        $request = $this->createRequest('GET', "/api/schedules", ['Authorization' => "Bearer $jwt"]);
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string)$response->getBody());
        $this->assertFalse($response->hasHeader('X-New-Token'), 'Should not renew yet');

        // 4. Wait for > 50% of lifespan (6 seconds for 10s expiry)
        sleep(6);

        // 5. Perform another action
        $request2 = $this->createRequest('GET', "/api/schedules", ['Authorization' => "Bearer $jwt"]);
        $response2 = $this->app->handle($request2);

        // 6. Verification
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertTrue($response2->hasHeader('X-New-Token'), 'Should have received a new token');

        $newToken = $response2->getHeaderLine('X-New-Token');
        $this->assertNotEquals($jwt, $newToken);

        $decoded = Auth::validateJWT($newToken);
        $this->assertNotNull($decoded);
        $this->assertEquals('user@example.com', $decoded['sub']);
        // The new token should have a fresh expiry
        $this->assertGreaterThan(time(), $decoded['exp']);
    }

    /**
     * Automates Manual Testing Plan 2: Guest Session Hard Cap (Link Expiry)
     * @throws Exception
     */
    public function testGuestSessionHardCap()
    {
        // 1. Setup: Create a schedule and a share link with short expiry (e.g., 5 seconds)
        $conn = Database::getConnection();
        $scheduleId = 'test-schedule-123';
        $conn->insert('schedules', [
            'hashed_id' => $scheduleId,
            'name' => 'Test Schedule',
            'data' => json_encode(['rooms' => [], 'timeslots' => [], 'sessions' => []]),
        ]);

        $expiryTime = time() + 5;
        $shareToken = ShareLink::create($scheduleId, 'admin@example.com', date('Y-m-d H:i:s', $expiryTime));

        // 2. Login as guest using the share link
        $uri = "/api/auth/login?share_token=$shareToken";
        $request = $this->createRequest('GET', $uri);
        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), "Body: " . (string)$response->getBody());
        $body = json_decode((string)$response->getBody(), true);
        $jwt = $body['token'];

        // Verify link_exp is set in JWT
        $decoded = Auth::validateJWT($jwt);
        $this->assertEquals($expiryTime, $decoded['link_exp']);

        // 3. Perform an action while link is still valid
        $request2 = $this->createRequest(
            'GET',
            "/api/schedules/subscription?schedule_id=$scheduleId",
            ['Authorization' => "Bearer $jwt"]
        );
        $response2 = $this->app->handle($request2);
        // It might return 500 because Mercure is not configured, but that's fine for testing the middleware/auth
        $this->assertNotEquals(401, $response2->getStatusCode(), (string)$response2->getBody());

        // 4. Wait until the link expires
        sleep(6);

        // 5. Perform an action after expiry
        $request3 = $this->createRequest(
            'GET',
            "/api/schedules/subscription?schedule_id=$scheduleId",
            ['Authorization' => "Bearer $jwt"]
        );
        $response3 = $this->app->handle($request3);

        // 6. Verification: Should fail with 401 Unauthorized
        $this->assertEquals(401, $response3->getStatusCode());
    }
}
