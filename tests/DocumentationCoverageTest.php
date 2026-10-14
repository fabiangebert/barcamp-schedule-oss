<?php

namespace Tests;

use App\Database;
use App\Documentation;
use Doctrine\DBAL\Exception;

class DocumentationCoverageTest extends BaseTestCase
{
    /**
     * @throws Exception
     */
    public function testGetForSessionEmpty()
    {
        $result = Documentation::getForSession('non-existent', '9:00', 'Room 1');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @throws Exception
     */
    public function testExportToMarkdownNonExistentSchedule()
    {
        $result = Documentation::exportToMarkdown('non-existent');
        $this->assertEquals('Schedule not found.', $result);
    }

    /**
     * @throws Exception
     */
    public function testExportToZipNonExistentSchedule()
    {
        $result = Documentation::exportToZip('non-existent');
        $this->assertNull($result);
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public function testExportToZipEmptyDocumentation()
    {
        $scheduleName = 'Empty Schedule';
        $hashedId = 'empty-hashed-id';
        $conn = Database::getConnection();
        $conn->insert('schedules', [
            'name' => $scheduleName,
            'hashed_id' => $hashedId,
            'data' => json_encode(['sessions' => []])
        ]);

        $result = Documentation::exportToZip($hashedId);
        $this->assertNull($result);
    }

    /**
     * @throws Exception
     */
    public function testDeleteNonExistentDocumentation()
    {
        $result = Documentation::delete(9999);
        $this->assertFalse($result);
    }

    /**
     * @throws Exception
     */
    public function testAddDocumentationWithEmptyImages()
    {
        $scheduleId = 'test-schedule';
        $conn = Database::getConnection();
        // FK enforcement requires the parent schedule row to exist.
        $conn->insert('schedules', [
            'name' => 'Test Schedule',
            'hashed_id' => $scheduleId,
            'data' => json_encode([]),
            'version' => 1,
        ]);

        $result = Documentation::add(
            $scheduleId,
            '10:00',
            'Room 1',
            'Content',
            [], // Empty images
            'Guest Name',
            'Session Title'
        );
        $this->assertTrue($result);

        $docs = Documentation::getAllForSchedule($scheduleId);
        $this->assertCount(1, $docs);
        // Images are attached automatically by getAllForSchedule via internal call,
        // but since we provided empty array and no image_path in DB, it should be empty
        $this->assertEmpty($docs[0]['images'] ?? []);
        $this->assertEquals('Guest Name', $docs[0]['display_name']);
    }
}
