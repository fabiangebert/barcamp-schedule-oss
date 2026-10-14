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

class FeedbackExportCsvTest extends BaseTestCase
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

        $this->hashedId = Schedule::create('Feedback Schedule', [
            'rooms' => ['Room A'],
            'timeslots' => ['10:00'],
            'sessions' => [
                ['timeslot' => '10:00', 'room' => 'Room A', 'title' => 'Feedback Session']
            ],
            'feedback_questions' => [
                ['id' => 'q1', 'question' => 'How would you rate this session?', 'type' => 'rating'],
                ['id' => 'q2', 'question' => 'Any comments?', 'type' => 'text'],
            ]
        ]);
    }

    /**
     * @param string $body
     * @return array<int, array<int, string|null>>
     */
    private function csvRows(string $body): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $body);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, string> $headers
     * @param ?array<string, mixed> $body
     * @return Request
     */
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
     */
    private function getUserJwt(): string
    {
        $email = 'csv_user@example.com';
        Database::getConnection()->insert('users', ['email' => $email, 'role' => 'user', 'name' => 'CSV User']);
        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        return $result['token'];
    }

    /**
     * @throws Exception
     */
    public function testExportFeedbackCsvReturnsCsvWhenFeedbackExists()
    {
        $jwt = $this->getUserJwt();

        $feedbackContent = json_encode([
            'feedback_answers' => [
                'q1' => 8,
                'q2' => 'Really enjoyed it!',
            ]
        ]);

        Documentation::add(
            $this->hashedId,
            '10:00',
            'Room A',
            $feedbackContent,
            [],
            'CSV User'
        );

        $request = $this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/feedback/export/csv",
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString(
            'attachment; filename="feedback_' . $this->hashedId . '.csv"',
            $response->getHeaderLine('Content-Disposition')
        );

        $body = (string)$response->getBody();
        $this->assertNotEmpty($body);
        $this->assertStringContainsString('How would you rate this session?', $body);
        $this->assertStringContainsString('Really enjoyed it!', $body);
        $this->assertStringContainsString('8/10', $body);
    }

    public function testExportFeedbackCsvHasNoUtf8Bom()
    {
        $jwt = $this->getUserJwt();

        Documentation::add(
            $this->hashedId,
            'event',
            'event',
            json_encode(['feedback_answers' => ['q1' => 8]]),
            [],
            'CSV User'
        );

        $request = $this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/feedback/export/csv",
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $body = (string)$response->getBody();
        $this->assertStringStartsWith('Question', $body);
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $body);
    }

    /**
     * A schedule with no feedback still yields a valid download, so the export
     * must not report a missing-data error for an empty result.
     *
     * @throws Exception
     */
    public function testExportFeedbackCsvReturnsHeadersOnlyCsvWhenNoFeedbackData()
    {
        $jwt = $this->getUserJwt();

        $request = $this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/feedback/export/csv",
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $body = (string)$response->getBody();
        $this->assertStringStartsWith('Question', $body);
        $this->assertCount(1, array_filter(explode("\n", $body)));
    }

    public function testExportFeedbackCsvIgnoresNonFeedbackDocumentation()
    {
        $jwt = $this->getUserJwt();

        $bareId = Schedule::create('Bare Feedback Schedule', [
            'rooms' => ['Room A'],
            'timeslots' => ['10:00'],
            'sessions' => [],
        ]);

        // Documentation that is not event feedback must not be exported as feedback.
        Documentation::add($bareId, '10:00', 'Room A', 'just a comment', [], 'CSV User');

        $request = $this->createRequest(
            'GET',
            "/api/schedules/$bareId/feedback/export/csv",
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        $this->assertStringStartsWith('Question', $body);
        $this->assertStringNotContainsString('just a comment', $body);
    }

    public function testExportFeedbackCsvReturns401ForGuest()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Guest User');

        $request = $this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/feedback/export/csv",
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * Feedback is submitted through the documentation route, not written
     * directly, so the export is exercised against the shape the API actually
     * stores. Guest feedback is private, so this also guards the role
     * pass-through that lets a user role see it at all.
     *
     * @throws Exception
     */
    public function testExportFeedbackCsvIncludesPrivateGuestFeedbackSubmittedViaApi()
    {
        $guestJwt = Auth::generateJWT('guest-submitter@example.com', 'guest', 'Submitter');
        $feedbackContent = json_encode([
            'feedback_answers' => [
                'q1' => 9,
                'q2' => 'Läuft super, größere Schrift wäre schön',
            ]
        ]);

        $post = $this->app->handle($this->createRequest(
            'POST',
            "/api/schedules/$this->hashedId/sessions/event/event/documentation",
            ['Authorization' => "Bearer $guestJwt"],
            [
                'content' => $feedbackContent,
                'images' => [],
                'guest_name' => 'Submitter',
                'guest_id' => 'guest-abc',
                'is_public' => false,
            ]
        ));
        $this->assertEquals(200, $post->getStatusCode());

        // The stored entry must actually be private, otherwise this proves nothing.
        $this->assertCount(
            0,
            Documentation::getAllForSchedule($this->hashedId),
            'guest feedback must be stored private'
        );
        $this->assertCount(
            1,
            Documentation::getAllForSchedule($this->hashedId, 'guest-abc'),
            'the entry must exist for its own guest'
        );

        $userJwt = $this->getUserJwt();
        $response = $this->app->handle($this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/feedback/export/csv",
            ['Authorization' => "Bearer $userJwt"]
        ));

        $this->assertEquals(200, $response->getStatusCode());

        $rows = $this->csvRows((string)$response->getBody());
        $this->assertCount(3, $rows, 'expected a header row plus one row per question');

        $this->assertSame('How would you rate this session?', $rows[1][0]);
        $this->assertSame('9/10', $rows[1][2]);
        $this->assertSame('Submitter', $rows[1][3]);
        $this->assertSame('guest-abc', $rows[1][5]);
        $this->assertSame('event', $rows[1][7]);
        $this->assertSame('9', $rows[1][9], 'rating column should carry the rating answer');

        $this->assertSame('Any comments?', $rows[2][0]);
        $this->assertSame('Läuft super, größere Schrift wäre schön', $rows[2][2]);
        $this->assertSame('', $rows[2][9], 'non-rating question must not populate the rating column');
    }

    /**
     * A numeric question id in the schedule data must not silently blank the
     * question, type and rating columns.
     *
     * @throws Exception
     */
    public function testExportFeedbackCsvMatchesNumericQuestionIds()
    {
        $numericIdSchedule = Schedule::create('Numeric Id Schedule', [
            'rooms' => ['Room A'],
            'timeslots' => ['10:00'],
            'sessions' => [],
            'feedback_questions' => [
                ['id' => 1, 'question' => 'Rate the venue', 'type' => 'rating'],
            ],
        ]);

        Documentation::add(
            $numericIdSchedule,
            'event',
            'event',
            json_encode(['feedback_answers' => ['1' => 7]]),
            [],
            'CSV User'
        );

        $jwt = $this->getUserJwt();
        $response = $this->app->handle($this->createRequest(
            'GET',
            "/api/schedules/$numericIdSchedule/feedback/export/csv",
            ['Authorization' => "Bearer $jwt"]
        ));

        $this->assertEquals(200, $response->getStatusCode());

        $rows = $this->csvRows((string)$response->getBody());
        $this->assertCount(2, $rows);
        $this->assertSame('Rate the venue', $rows[1][0]);
        $this->assertSame('rating', $rows[1][1]);
        $this->assertSame('7/10', $rows[1][2]);
        $this->assertSame('7', $rows[1][9]);
    }

    /**
     * A skipped optional question is stored as null and must export as an empty
     * cell rather than the literal string "null".
     *
     * @throws Exception
     */
    public function testExportFeedbackCsvRendersNullAnswerAsEmptyCell()
    {
        Documentation::add(
            $this->hashedId,
            'event',
            'event',
            json_encode(['feedback_answers' => ['q2' => null]]),
            [],
            'CSV User'
        );

        $jwt = $this->getUserJwt();
        $response = $this->app->handle($this->createRequest(
            'GET',
            "/api/schedules/$this->hashedId/feedback/export/csv",
            ['Authorization' => "Bearer $jwt"]
        ));

        $this->assertEquals(200, $response->getStatusCode());

        $rows = $this->csvRows((string)$response->getBody());
        $this->assertCount(2, $rows);
        $this->assertSame('Any comments?', $rows[1][0]);
        $this->assertSame('', $rows[1][2]);
        $this->assertStringNotContainsString('null', (string)$response->getBody());
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testExportFeedbackCsvReturns404ForNonExistentSchedule()
    {
        $jwt = $this->getUserJwt();

        $request = $this->createRequest(
            'GET',
            '/api/schedules/non-existent-id/feedback/export/csv',
            ['Authorization' => "Bearer $jwt"]
        );
        $response = $this->app->handle($request);

        $this->assertEquals(404, $response->getStatusCode());
    }
}
