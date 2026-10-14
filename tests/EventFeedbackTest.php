<?php

namespace Tests;

use App\Documentation;
use App\Schedule;
use Doctrine\DBAL\Exception;
use Random\RandomException;

class EventFeedbackTest extends BaseTestCase
{
    protected string $hashedId;

    /**
     * @throws RandomException
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Create a schedule for testing
        $this->hashedId = Schedule::create('Feedback Schedule', ['sessions' => []]);
    }

    /**
     * @throws Exception
     */
    public function testAddEventFeedbackWithRating()
    {
        $timeslot = 'event';
        $room = 'event';
        $userEmail = 'tester@example.com';
        $content = 'Great event!';
        $rating = 9;

        $success = Documentation::add(
            $this->hashedId,
            $timeslot,
            $room,
            $content,
            [],
            'Tester',
            null,
            $rating,
            null,
            null,
            true
        );

        $this->assertTrue($success);

        $docs = Documentation::getForSession($this->hashedId, $timeslot, $room);
        $this->assertCount(1, $docs);
        $this->assertEquals($content, $docs[0]['content']);
        $this->assertEquals($rating, $docs[0]['rating']);
    }

    /**
     * @throws Exception
     */
    public function testDocumentationOverviewGroupsEventFeedback()
    {
        Documentation::add(
            $this->hashedId,
            'event',
            'event',
            'Feedback 1',
            [],
            'User 1',
            null,
            8,
            null,
            null,
            true
        );
        Documentation::add(
            $this->hashedId,
            'event',
            'event',
            'Feedback 2',
            [],
            'User 2',
            null,
            10,
            null,
            null,
            true
        );

        $allDocs = Documentation::getAllForSchedule($this->hashedId);

        $eventDocs = array_filter($allDocs, function ($doc) {
            return $doc['timeslot'] === 'event' && $doc['room'] === 'event';
        });

        $this->assertCount(2, $eventDocs);
        foreach ($eventDocs as $doc) {
            $this->assertNotNull($doc['rating']);
        }
    }

    /**
     * @throws Exception
     */
    public function testSessionDocumentationHasNoRatingByDefault()
    {
        Documentation::add(
            $this->hashedId,
            '09:00',
            'Room A',
            'Session Doc',
            [],
            'User',
            'Session title'
        );

        $docs = Documentation::getForSession($this->hashedId, '09:00', 'Room A');
        $this->assertCount(1, $docs);
        $this->assertNull($docs[0]['rating']);
    }
}
