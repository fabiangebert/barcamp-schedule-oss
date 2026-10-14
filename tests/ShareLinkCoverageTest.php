<?php

namespace Tests;

use App\Database;
use App\ShareLink;
use DateMalformedStringException;
use DateTime;
use Doctrine\DBAL\Exception;
use Random\RandomException;

class ShareLinkCoverageTest extends BaseTestCase
{
    /**
     * @throws Exception
     */
    public function testValidateReturnsNullForInvalidToken()
    {
        $result = ShareLink::validate('non-existent-token');
        $this->assertNull($result);
    }

    /**
     * @throws DateMalformedStringException
     * @throws Exception
     * @throws \Exception
     */
    public function testValidateReturnsNullForExpiredToken()
    {
        $conn = Database::getConnection();
        $token = 'expired-token';
        $expiresAt = (new DateTime())->modify('-1 day')->format('Y-m-d H:i:s');

        // FK enforcement requires the parent schedule row to exist.
        $conn->insert('schedules', [
            'name' => 'expired-token-schedule',
            'hashed_id' => 'test-id',
            'data' => json_encode([]),
            'version' => 1,
        ]);

        $conn->insert('share_links', [
            'schedule_id' => 'test-id',
            'token' => $token,
            'created_by' => 'user@example.com',
            'expires_at' => $expiresAt
        ]);

        $result = ShareLink::validate($token);
        $this->assertNull($result);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testDeleteReturnsFalseForWrongUser()
    {
        $conn = Database::getConnection();
        // FK enforcement requires the parent schedule row to exist.
        $conn->insert('schedules', [
            'name' => 'wrong-user-schedule',
            'hashed_id' => 'test-id',
            'data' => json_encode([]),
            'version' => 1,
        ]);

        $token = ShareLink::create('test-id', 'user1@example.com');
        $link = $conn->fetchAssociative('SELECT id FROM share_links WHERE token = ?', [$token]);

        $result = ShareLink::delete($link['id'], 'user2@example.com');
        $this->assertFalse($result);
    }

    public function testGenerateGuestName()
    {
        $name = ShareLink::generateGuestName();
        $this->assertIsString($name);
        $this->assertStringContainsString(' ', $name);

        $parts = explode(' ', $name);
        $this->assertCount(2, $parts);
    }
}
