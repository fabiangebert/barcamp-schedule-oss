<?php

namespace App;

use DateMalformedStringException;
use DateTime;
use Doctrine\DBAL\Exception;
use Random\RandomException;

class ShareLink
{
    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public static function create(string $scheduleId, string $createdBy, ?string $expiresAt = null): string
    {
        $conn = Database::getConnection();
        $token = bin2hex(random_bytes(16));
        if ($expiresAt === null) {
            $expiresAt = (new DateTime())->modify('+1 week')->format('Y-m-d H:i:s');
        }

        $conn->insert('share_links', [
            'schedule_id' => $scheduleId,
            'token' => $token,
            'created_by' => $createdBy,
            'expires_at' => $expiresAt
        ]);

        return $token;
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public static function updateExpiry(int $id, string $expiresAt, string $email, bool $isElevated = false): bool
    {
        $conn = Database::getConnection();
        $params = ['expires_at' => $expiresAt];
        $where = ['id' => $id];
        if (!$isElevated) {
            $where['created_by'] = $email;
        }
        $result = $conn->update('share_links', $params, $where);
        return $result > 0;
    }

    /**
     * @param string $token
     * @return array<string, mixed>|null
     * @throws Exception
     * @throws \Exception
     */
    public static function validate(string $token): ?array
    {
        $conn = Database::getConnection();
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $link = $conn->fetchAssociative(
            'SELECT * FROM share_links WHERE token = ? AND expires_at > ?',
            [$token, $now]
        );

        return $link ?: null;
    }

    /**
     * @param string $email
     * @return array<int, array<string, mixed>>
     * @throws Exception
     * @throws \Exception
     */
    public static function getAllByUser(string $email, bool $isElevated = false): array
    {
        $conn = Database::getConnection();
        if ($isElevated) {
            return $conn->fetchAllAssociative(
                'SELECT * FROM share_links ORDER BY created_at DESC'
            );
        }
        return $conn->fetchAllAssociative(
            'SELECT * FROM share_links WHERE created_by = ? ORDER BY created_at DESC',
            [$email]
        );
    }

    /**
     * @param string $scheduleId
     * @param string $email
     * @return array<int, array<string, mixed>>
     * @throws Exception
     * @throws \Exception
     */
    public static function getBySchedule(string $scheduleId, string $email, bool $isElevated = false): array
    {
        $conn = Database::getConnection();
        if ($isElevated) {
            return $conn->fetchAllAssociative(
                'SELECT * FROM share_links WHERE schedule_id = ? ORDER BY created_at DESC',
                [$scheduleId]
            );
        }
        return $conn->fetchAllAssociative(
            'SELECT * FROM share_links WHERE schedule_id = ? AND created_by = ? ORDER BY created_at DESC',
            [$scheduleId, $email]
        );
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public static function delete(int $id, string $email, bool $isElevated = false): bool
    {
        $conn = Database::getConnection();
        $where = ['id' => $id];
        if (!$isElevated) {
            $where['created_by'] = $email;
        }
        $result = $conn->delete('share_links', $where);
        return $result > 0;
    }

    public static function generateGuestName(): string
    {
        $adjectives = [
            'Funny', 'Happy', 'Cooperative', 'Clever', 'Brave', 'Gentle', 'Quick', 'Sleepy', 'Curious', 'Patient'
        ];
        $animals = ['Beaver', 'Weasel', 'Panda', 'Otter', 'Badger', 'Squirrel', 'Rabbit', 'Hamster', 'Fox', 'Owl'];

        return $adjectives[array_rand($adjectives)] . ' ' . $animals[array_rand($animals)];
    }
}
