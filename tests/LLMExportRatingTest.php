<?php

namespace Tests;

use App\Documentation;
use App\Schedule;
use Exception;

class LLMExportRatingTest extends BaseTestCase
{
    /**
     * @throws Exception
     */
    public function testMarkdownExportIncludesRating()
    {
        $scheduleData = [
            'timeslots' => ['09:00', '10:00'],
            'rooms' => ['Room 1', 'Room 2'],
        ];
        $hashedId = Schedule::create('Test Schedule', $scheduleData);

        // Add documentation with rating
        Documentation::add(
            $hashedId,
            '09:00',
            'Room 1',
            'This is a comment with a rating.',
            [],
            'Test User',
            'Session Title',
            8
        );

        // Add documentation without rating
        Documentation::add(
            $hashedId,
            '10:00',
            'Room 2',
            'This is a comment without a rating.',
            [],
            'Other User',
            'Other Session'
        );

        $markdown = Documentation::exportToMarkdown($hashedId);

        // Verify rating is present for the first comment
        $this->assertStringContainsString('Rating: 8/10', $markdown);
        $this->assertStringContainsString('This is a comment with a rating.', $markdown);

        // Verify no rating is present for the second comment (or at least not a 0/10 or something)
        $this->assertStringContainsString('This is a comment without a rating.', $markdown);

        // Count occurrences of "Rating:"
        $this->assertEquals(1, substr_count($markdown, 'Rating:'));
    }

    /**
     * @throws Exception
     */
    public function testMarkdownExportIncludesPrivateComments()
    {
        $scheduleData = [
            'timeslots' => ['09:00'],
            'rooms' => ['Room 1'],
        ];
        $hashedId = Schedule::create('Test Schedule Private', $scheduleData);

        // Add public comment
        Documentation::add(
            $hashedId,
            '09:00',
            'Room 1',
            'Public Comment',
            [],
            'Public User',
            'Public Session',
            null,
            null,
            null,
            true // is_public
        );

        // Add private comment
        Documentation::add(
            $hashedId,
            '09:00',
            'Room 1',
            'Private Comment',
            [],
            null, // guestName must be null for userId
            'Private Session',
            null,
            null,
            99, // user_id
            false // is_public
        );

        // Verify private comment is NOT present when exported without userId
        $markdownWithoutUser = Documentation::exportToMarkdown($hashedId);
        $this->assertStringNotContainsString('Private Comment', $markdownWithoutUser);

        // Verify private comment IS present when exported with userId
        $markdownWithUser = Documentation::exportToMarkdown($hashedId, 99);
        $this->assertStringContainsString('Private Comment', $markdownWithUser);
        $this->assertStringContainsString('Public Comment', $markdownWithUser);
    }
}
