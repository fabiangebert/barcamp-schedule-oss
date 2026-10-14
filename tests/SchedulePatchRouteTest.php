<?php

namespace Tests;

use App\Database;
use App\App;
use App\Auth;
use App\Schedule;
use DateMalformedStringException;
use Doctrine\DBAL\Exception;
use Random\RandomException;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;
use Slim\Psr7\Headers;

class SchedulePatchRouteTest extends BaseTestCase
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

    private function createRequest(string $path, array $headers = [], ?array $body = null): Request
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

        return new Request('PATCH', $uri, $headersObj, [], $_SERVER, new Stream($handle));
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testPatchVersionMismatchReturns409()
    {
        $email = 'patcher@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $validData = [
            'rooms' => [['name' => 'Main']],
            'timeslots' => [['name' => '10:00']],
            'sessions' => [],
            'topics' => [['name' => 'Default']],
        ];
        $hashedId = Schedule::create('Patch Test', $validData);

        // initial version is 1. We try to patch with version 0.
        $request = $this->createRequest("/api/schedules/$hashedId", ['Authorization' => "Bearer $jwt"], [
            'data' => $validData,
            'version' => 0
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(409, $response->getStatusCode());

        $body = (string)$response->getBody();
        $result = json_decode($body, true);
        $this->assertEquals('Version mismatch (concurrent editing)', $result['error']);
        $this->assertArrayHasKey('current_schedule', $result);
        $this->assertEquals('Patch Test', $result['current_schedule']['name']);
        $this->assertEquals($validData, $result['current_schedule']['data']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testPatchNotFoundReturns404()
    {
        $email = 'patcher2@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $validData = [
            'rooms' => [['name' => 'Main']],
            'timeslots' => [['name' => '10:00']],
            'sessions' => [],
            'topics' => [['name' => 'Default']],
        ];
        $request = $this->createRequest("/api/schedules/non-existent-id", ['Authorization' => "Bearer $jwt"], [
            'data' => $validData,
            'version' => 1
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(404, $response->getStatusCode());

        $body = (string)$response->getBody();
        $result = json_decode($body, true);
        $this->assertEquals('Schedule not found', $result['error']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testPatchSuccess()
    {
        $email = 'patcher3@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $validData = [
            'rooms' => [['name' => 'Main']],
            'timeslots' => [['name' => '10:00']],
            'sessions' => [],
            'topics' => [['name' => 'Default']],
        ];
        $updatedData = [
            'rooms' => [['name' => 'Main']],
            'timeslots' => [['name' => '10:00']],
            'sessions' => [[
                'timeslot' => '10:00',
                'room' => 'Main',
                'title' => 'Session 1',
                'speakers' => 'Speaker',
                'format' => 'talk',
                'topic' => 'Default',
            ]],
            'topics' => [['name' => 'Default']],
        ];
        $hashedId = Schedule::create('Patch Test Success', $validData);

        $request = $this->createRequest("/api/schedules/$hashedId", ['Authorization' => "Bearer $jwt"], [
            'data' => $updatedData,
            'version' => 1,
            'name' => 'Renamed Schedule',
            'date' => '2023-10-27'
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        $body = (string)$response->getBody();
        $result = json_decode($body, true);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(2, $result['data']['version']);
        $this->assertEquals($updatedData, $result['data']['data']);
        $this->assertEquals('Renamed Schedule', $result['data']['name']);
        $this->assertEquals('2023-10-27', $result['data']['date']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testPatchDuplicateNameReturns400()
    {
        $email = 'patcher4@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $duplicateData = [
            'rooms' => [['name' => 'R1']],
            'timeslots' => [['name' => '10:00']],
            'sessions' => [],
            'topics' => [['name' => 'T1']],
        ];
        Schedule::create('Existing Name', $duplicateData);
        $hashedId = Schedule::create('Other Name', $duplicateData);

        $request = $this->createRequest("/api/schedules/$hashedId", ['Authorization' => "Bearer $jwt"], [
            'data' => $duplicateData,
            'version' => 1,
            'name' => 'Existing Name'
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(400, $response->getStatusCode());

        $body = (string)$response->getBody();
        $result = json_decode($body, true);
        $this->assertEquals('Schedule name already exists', $result['error']);
    }
    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testPatchEmptyDataReturns400()
    {
        $email = 'patcher5@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $emptyTestData = [
            'rooms' => [['name' => 'R1']],
            'timeslots' => [['name' => '10:00']],
            'sessions' => [],
            'topics' => [['name' => 'T1']],
        ];
        $hashedId = Schedule::create('Patch Empty Test', $emptyTestData);

        $request = $this->createRequest("/api/schedules/$hashedId", ['Authorization' => "Bearer $jwt"], [
            'data' => [],
            'version' => 1
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
    public function testPatchInvalidDataReturns400()
    {
        $email = 'patcher6@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $hashedId = Schedule::create('Patch Invalid Test', ['data' => 'initial']);

        $invalidData = [
            'rooms' => [['size' => 'XL']],
            'timeslots' => [],
            'sessions' => [],
            'topics' => [],
        ];
        $request = $this->createRequest("/api/schedules/$hashedId", ['Authorization' => "Bearer $jwt"], [
            'data' => $invalidData,
            'version' => 1
        ]);

        $response = $this->app->handle($request);
        $this->assertEquals(400, $response->getStatusCode());

        $body = (string)$response->getBody();
        $result = json_decode($body, true);
        $this->assertEquals('Invalid schedule data', $result['error']);
        $this->assertNotEmpty($result['details']);
    }

    /**
     * Two sequential saves that share the same base version must not both
     * succeed: the atomic Compare-And-Swap UPDATE scopes by version, so the
     * second save must report a version mismatch. This guards against the
     * Lost Update bug (formerly the code only checked the version in PHP,
     * which allowed both concurrent requests to pass and overwrite each other).
     */
    public function testConcurrentPatchesDoNotBothSucceed()
    {
        $validData = [
            'rooms' => [['name' => 'Main']],
            'timeslots' => [['name' => '10:00']],
            'sessions' => [],
            'topics' => [['name' => 'Default']],
        ];
        $hashedId = Schedule::create('Concurrent CAS Test', $validData);

        // First save with version 1 must succeed and bump to version 2.
        $first = Schedule::saveByHashedId($hashedId, $validData, 1);
        $this->assertTrue($first);

        // Second save with the SAME version 1 must fail atomically.
        $second = Schedule::saveByHashedId($hashedId, $validData, 1);
        $this->assertSame('version_mismatch', $second);

        // The schedule must now be at version 2, not 3.
        $schedule = Schedule::getByHashedId($hashedId);
        $this->assertEquals(2, $schedule['version']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testRenameSuccess()
    {
        $email = 'renamer@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $hashedId = Schedule::create('Old Name', []);

        $uri = new Uri('', '', null, "/api/schedules/$hashedId/rename");
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, json_encode(['newName' => 'New Name From Route']));
        rewind($handle);

        $headersObj = new Headers();
        $headersObj->addHeader('Authorization', "Bearer $jwt");
        $headersObj->addHeader('Content-Type', 'application/json');

        $request = new Request('POST', $uri, $headersObj, [], $_SERVER, new Stream($handle));

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        $body = (string)$response->getBody();
        $result = json_decode($body, true);
        $this->assertEquals('success', $result['status']);

        $schedule = Schedule::getByHashedId($hashedId);
        $this->assertEquals('New Name From Route', $schedule['name']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testRenameEmptyNameReturns400()
    {
        $email = 'renamer2@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'] ?? '';

        $hashedId = Schedule::create('Some Name', []);

        $uri = new Uri('', '', null, "/api/schedules/$hashedId/rename");
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, json_encode(['newName' => '']));
        rewind($handle);

        $headersObj = new Headers();
        $headersObj->addHeader('Authorization', "Bearer $jwt");
        $headersObj->addHeader('Content-Type', 'application/json');

        $request = new Request('POST', $uri, $headersObj, [], $_SERVER, new Stream($handle));

        $response = $this->app->handle($request);
        $this->assertEquals(400, $response->getStatusCode());

        $body = (string)$response->getBody();
        $result = json_decode($body, true);
        $this->assertEquals('New name cannot be empty', $result['error']);
    }
}
