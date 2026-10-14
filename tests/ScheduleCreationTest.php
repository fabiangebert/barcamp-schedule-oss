<?php

namespace Tests;

use App\Database;
use App\App;
use App\Auth;
use DateMalformedStringException;
use Doctrine\DBAL\Exception;
use Random\RandomException;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;
use Slim\Psr7\Headers;

class ScheduleCreationTest extends BaseTestCase
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

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testCreateSchedule()
    {
        // Register a user
        $email = 'creator@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $name = 'New Schedule via API';
        $data = [
            'rooms' => [['name' => 'Main Hall']],
            'timeslots' => [['name' => '09:00']],
            'sessions' => [],
            'topics' => [['name' => 'Default']],
        ];

        $request = $this->createRequest('POST', '/api/schedules', ['Authorization' => "Bearer $jwt"], [
            'name' => $name,
            'data' => $data
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        $body = (string)$response->getBody();
        $result = json_decode($body, true);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals($name, $result['data']['name']);
        $this->assertArrayHasKey('hashed_id', $result['data']);
        $this->assertEquals($data, $result['data']['data']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testCreateScheduleEmptyName()
    {
        $email = 'creator2@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $request = $this->createRequest('POST', '/api/schedules', ['Authorization' => "Bearer $jwt"], [
            'name' => '',
            'data' => []
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(400, $response->getStatusCode());

        $body = (string)$response->getBody();
        $result = json_decode($body, true);
        $this->assertEquals('Schedule name cannot be empty', $result['error']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testCreateScheduleEmptyData()
    {
        $email = 'creator3@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $request = $this->createRequest('POST', '/api/schedules', ['Authorization' => "Bearer $jwt"], [
            'name' => 'Schedule with empty data',
            'data' => []
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(400, $response->getStatusCode());

        $body = (string)$response->getBody();
        $result = json_decode($body, true);
        $this->assertEquals('Schedule data cannot be empty', $result['error']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testCreateScheduleMissingData()
    {
        $email = 'creator4@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $request = $this->createRequest('POST', '/api/schedules', ['Authorization' => "Bearer $jwt"], [
            'name' => 'Schedule with missing data'
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(400, $response->getStatusCode());

        $body = (string)$response->getBody();
        $result = json_decode($body, true);
        $this->assertEquals('Schedule data cannot be empty', $result['error']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testCreateScheduleInvalidData()
    {
        $email = 'creator5@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $request = $this->createRequest('POST', '/api/schedules', ['Authorization' => "Bearer $jwt"], [
            'name' => 'Invalid Schedule',
            'data' => ['rooms' => [['size' => 'XL']], 'timeslots' => [], 'sessions' => [], 'topics' => []]
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(400, $response->getStatusCode());

        $body = (string)$response->getBody();
        $result = json_decode($body, true);
        $this->assertEquals('Invalid schedule data', $result['error']);
        $this->assertNotEmpty($result['details']);
    }

    public function testCreateScheduleUnauthorized()
    {
        $request = $this->createRequest('POST', '/api/schedules', [], [
            'name' => 'Should fail',
            'data' => []
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public function testGetNonExistentScheduleReturns404ForGuest()
    {
        $conn = Database::getConnection();
        $conn->delete('users', ['email' => 'guest@guest.com']);
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Guest User');

        $request = $this->createRequest('GET', '/api/schedules/non-existent-id', ['Authorization' => "Bearer $jwt"]);
        $response = $this->app->handle($request);

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status']);
    }
}
