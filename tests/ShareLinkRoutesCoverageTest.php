<?php

namespace Tests;

use App\Database;
use App\App;
use App\Auth;
use App\Schedule;
use DateMalformedStringException;
use Doctrine\DBAL\Exception;
use Random\RandomException;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;
use App\ShareLink;

class ShareLinkRoutesCoverageTest extends BaseTestCase
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

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    private function getJwt(string $email): string
    {
        $conn = Database::getConnection();
        $existing = $conn->fetchAssociative('SELECT id FROM users WHERE email = ?', [$email]);
        if (!$existing) {
            $conn->insert('users', ['email' => $email, 'role' => 'user']);
        }
        $result = Auth::verifyMagicToken(Auth::generateMagicToken($email));
        return $result['token'] ?? '';
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testGetShareLinksBySchedule()
    {
        $email = 'user@example.com';
        $jwt = $this->getJwt($email);
        $scheduleId = bin2hex(random_bytes(16));
        // Create the schedule first so it can be found
        Database::getConnection()->insert('schedules', [
            'name' => 'Test Schedule',
            'hashed_id' => $scheduleId,
            'data' => json_encode([]),
            'version' => 1
        ]);

        ShareLink::create($scheduleId, $email);

        $uri = new Uri('', '', null, '/api/share-links', "schedule_id=$scheduleId");
        $handle = fopen('php://temp', 'w+');
        rewind($handle);
        $headersObj = new Headers();
        $headersObj->addHeader('Content-Type', 'application/json');
        $headersObj->addHeader('Authorization', "Bearer $jwt");
        $request = new Request('GET', $uri, $headersObj, [], $_SERVER, new Stream($handle));

        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('success', $data['status']);
        $links = $data['data'];
        $this->assertCount(1, $links);
        $this->assertEquals($scheduleId, $links[0]['schedule_id']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testCreateShareLinkMissingScheduleId()
    {
        $jwt = $this->getJwt('user@example.com');
        $request = $this->createRequest('POST', '/share-links', $jwt, []);
        $response = $this->app->handle($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status']);
        $this->assertEquals('Schedule ID is required', $data['error']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testDeleteShareLinkFailure()
    {
        $jwt = $this->getJwt('user1@example.com');
        // Deleting a non-existent share link must return 404.
        $request = $this->createRequest('DELETE', "/share-links/99999", $jwt);
        $response = $this->app->handle($request);

        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testGetNonExistentShareLinksReturns404()
    {
        $jwt = $this->getJwt('user@example.com');
        $request = $this->createRequest('GET', '/share-links', $jwt);
        $uri = $request->getUri()->withQuery('schedule_id=non-existent');
        $request = $request->withUri($uri);
        $response = $this->app->handle($request);

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status'] ?? 'null');
    }
}
