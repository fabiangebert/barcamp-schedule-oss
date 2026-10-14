<?php

namespace Tests;

use App\App;
use App\Auth;
use App\Database;
use App\Documentation;
use App\Schedule;
use DateMalformedStringException;
use Doctrine\DBAL\Exception;
use Random\RandomException;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class FeedbackVisibilityTest extends BaseTestCase
{
    protected \Slim\App $app;

    protected string $hashedId;

    /**
     * @throws RandomException
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();

        $this->hashedId = Schedule::create('Feedback Schedule', [
            'rooms' => ['Room A'],
            'timeslots' => ['10:00'],
            'sessions' => [
                ['timeslot' => '10:00', 'room' => 'Room A', 'title' => 'Feedback Session']
            ],
            'feedback_questions' => [
                ['id' => 'q1', 'question' => 'How would you rate this event?', 'type' => 'rating'],
                ['id' => 'q2', 'question' => 'Any comments?', 'type' => 'text'],
            ]
        ]);
    }

    private function createRequest(string $method, string $path, array $headers = []): Request
    {
        $uri = new Uri('', '', null, $path);
        $handle = fopen('php://temp', 'w+');
        rewind($handle);

        $headersObj = new Headers();
        foreach ($headers as $name => $value) {
            $headersObj->addHeader($name, $value);
        }
        $headersObj->addHeader('Content-Type', 'application/json');

        return new Request($method, $uri, $headersObj, [], $_SERVER, new Stream($handle));
    }

    /**
     * @throws Exception
     * @throws DateMalformedStringException
     */
    private function getUserJwt(string $email): string
    {
        Database::getConnection()->insert('users', ['email' => $email, 'role' => 'user', 'name' => 'Test User']);
        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        return $result['token'];
    }

    /**
     * @throws Exception
     */
    private function addPrivateGuestEventFeedback(string $comment): void
    {
        Documentation::add(
            $this->hashedId,
            'event',
            'event',
            json_encode(['feedback_answers' => ['q2' => $comment]]),
            [],
            'Guest',
            null,
            null,
            'guest-123',
            null,
            false
        );
    }

    /**
     * @throws Exception
     */
    public function testUserRoleSeesPrivateGuestFeedbackInAllForSchedule()
    {
        $this->addPrivateGuestEventFeedback('Private guest feedback');
        $docs = Documentation::getAllForSchedule($this->hashedId, null, 99, 'user');

        $this->assertCount(0, Documentation::getAllForSchedule($this->hashedId, null, 99, null));
        $this->assertCount(1, $docs);
        $this->assertEquals('Private guest feedback', json_decode($docs[0]['content'], true)['feedback_answers']['q2']);
    }

    /**
     * @throws Exception
     */
    public function testUserRoleSeesPrivateGuestFeedbackInSessionDocs()
    {
        Documentation::add(
            $this->hashedId,
            '10:00',
            'Room A',
            'Private session doc',
            [],
            'Guest',
            null,
            null,
            'guest-123',
            null,
            false
        );

        $this->assertCount(
            0,
            Documentation::getForSession($this->hashedId, '10:00', 'Room A', null, 99, null)
        );

        $docs = Documentation::getForSession($this->hashedId, '10:00', 'Room A', null, 99, 'user');
        $this->assertCount(1, $docs);
        $this->assertEquals('Private session doc', $docs[0]['content']);
    }

    /**
     * @throws Exception
     */
    public function testGuestStillDoesNotSeePrivateFeedbackOfOtherGuests()
    {
        $this->addPrivateGuestEventFeedback('Private guest feedback');

        $docs = Documentation::getAllForSchedule($this->hashedId, 'other-guest', 99, 'guest');
        $this->assertCount(0, $docs);
    }

    /**
     * @throws Exception
     * @throws DateMalformedStringException
     */
    public function testFeedbackCsvExportIncludesPrivateGuestFeedbackForUsers()
    {
        $this->addPrivateGuestEventFeedback('Private guest feedback');
        $jwt = $this->getUserJwt('viewer@example.com');

        $request = $this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/feedback/export/csv",
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        $this->assertStringContainsString('Any comments?', $body);
        $this->assertStringContainsString('Private guest feedback', $body);
    }

    /**
     * @throws Exception
     * @throws DateMalformedStringException
     */
    public function testLLMZipExportWithPrivateGuestFeedbackReturns200ForUser()
    {
        $this->addPrivateGuestEventFeedback('Private guest feedback');
        $jwt = $this->getUserJwt('viewer@example.com');

        $request = $this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/export",
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/zip', $response->getHeaderLine('Content-Type'));
        $this->assertNotEmpty((string)$response->getBody());
    }
}
