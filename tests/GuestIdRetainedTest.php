<?php

declare(strict_types=1);

namespace Tests;

use App\Documentation;
use App\Database;

class GuestIdRetainedTest extends BaseTestCase
{
    public function testAddDocumentationWithGuestIdIsRetrieved()
    {
        $scheduleId = 'test-schedule-' . bin2hex(random_bytes(4));
        $conn = Database::getConnection();
        $conn->insert('schedules', [
            'name' => 'Test Schedule ' . $scheduleId,
            'hashed_id' => $scheduleId,
            'data' => json_encode(['version' => 1, 'timeslots' => [], 'rooms' => []]),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $timeslot = '10:00';
        $room = 'Room A';
        $userEmail = 'guest';
        $content = 'Test content';
        $guestId = 'unique-guest-id-123';
        $userName = 'Guest User';

        $success = Documentation::add(
            $scheduleId,
            $timeslot,
            $room,
            $content,
            [],
            $userName,
            null,
            null,
            $guestId
        );

        $this->assertTrue($success);

        $docs = Documentation::getForSession($scheduleId, $timeslot, $room);
        $this->assertCount(1, $docs);
        $this->assertEquals($guestId, $docs[0]['guest_id'], 'guest_id should be present in fetched documentation item');

        $allDocs = Documentation::getAllForSchedule($scheduleId);
        $this->assertCount(1, $allDocs);
        $this->assertEquals(
            $guestId,
            $allDocs[0]['guest_id'],
            'guest_id should be present in all documentation items for schedule'
        );
    }
}
