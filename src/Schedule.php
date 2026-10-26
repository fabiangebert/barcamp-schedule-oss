<?php

namespace App;

use DateTime;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Random\RandomException;

class Schedule
{
    /**
     * @param string $name
     * @return bool
     * @throws Exception
     */
    public static function nameExists(string $name): bool
    {
        $conn = Database::getConnection();
        $existing = $conn->fetchAssociative('SELECT id FROM schedules WHERE name = ?', [$name]);
        return $existing !== false;
    }

    /**
     * @param string $name
     * @param array<string, mixed> $data
     * @param ?string $date
     * @return ?string
     * @throws Exception
     * @throws RandomException
     * @throws \Exception
     */
    public static function create(string $name, array $data, ?string $date = null): ?string
    {
        $conn = Database::getConnection();
        $hashedId = bin2hex(random_bytes(16));
        $newData = json_encode($data);
        $insertData = [
            'name' => $name,
            'hashed_id' => $hashedId,
            'data' => $newData,
            'version' => 1
        ];
        if ($date !== null) {
            $insertData['date'] = $date;
        }
        $conn->insert('schedules', $insertData);
        ChangeTracker::update($hashedId);
        return $hashedId;
    }

    /**
     * @param string $hashedId
     * @return array<string, mixed>|null
     * @throws Exception
     * @throws \Exception
     */
    public static function getByHashedId(string $hashedId): ?array
    {
        $conn = Database::getConnection();
        $schedule = $conn->fetchAssociative('SELECT * FROM schedules WHERE hashed_id = ?', [$hashedId]);
        if (!$schedule) {
            return null;
        }
        $data = is_string($schedule['data']) ? $schedule['data'] : '{}';
        $schedule['data'] = json_decode($data, true);
        return $schedule;
    }

    /**
     * @param string $hashedId
     * @param array<string, mixed> $data
     * @param int $version
     * @param ?string $date
     * @param ?string $name
     * @return bool|string
     * @throws Exception
     * @throws \Exception
     */
    public static function saveByHashedId(
        string $hashedId,
        array $data,
        int $version,
        ?string $date = null,
        ?string $name = null
    ): bool|string {
        $conn = Database::getConnection();

        $current = self::getByHashedId($hashedId);
        if (!$current) {
            return 'not_found';
        }

        // If name is provided and changed, check for uniqueness
        if ($name !== null && $name !== $current['name']) {
            $existing = $conn->fetchAssociative(
                'SELECT id FROM schedules WHERE name = ? AND hashed_id != ?',
                [$name, $hashedId]
            );
            if ($existing) {
                return 'name_exists';
            }
        }

        $newData = json_encode($data);
        $newVersion = $version + 1;

        $updateData = [
            'data' => $newData,
            'version' => $newVersion,
            'updated_at' => (new DateTime())->format('Y-m-d H:i:s')
        ];
        if ($date !== null) {
            $updateData['date'] = $date;
        }
        if ($name !== null) {
            $updateData['name'] = $name;
        }

        // Atomic Compare-And-Swap: scope the UPDATE by both hashed_id AND the
        // expected version. If two concurrent requests both read version N,
        // only one of them can successfully update the row to N+1; the other
        // will affect zero rows and is reported as a version mismatch. The row
        // existence was already proven by getByHashedId above, so a zero-row
        // update unambiguously means the version no longer matches.
        try {
            $affected = $conn->update('schedules', $updateData, [
                'hashed_id' => $hashedId,
                'version' => $version,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            return 'name_exists';
        }

        if ($affected === 0) {
            return 'version_mismatch';
        }

        ChangeTracker::update($hashedId);

        return true;
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public static function renameByHashedId(string $hashedId, string $newName): bool
    {
        $conn = Database::getConnection();

        // Check if $newName already exists (by name)
        $existing = $conn->fetchAssociative('SELECT id FROM schedules WHERE name = ?', [$newName]);
        if ($existing) {
            return false;
        }

        try {
            $conn->update('schedules', ['name' => $newName], ['hashed_id' => $hashedId]);
        } catch (UniqueConstraintViolationException $e) {
            return false;
        }

        ChangeTracker::update($hashedId);

        return true;
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public static function deleteByHashedId(string $hashedId): bool
    {
        $conn = Database::getConnection();
        $deleted = $conn->delete('schedules', ['hashed_id' => $hashedId]);

        if ($deleted > 0) {
            ChangeTracker::update($hashedId);
            return true;
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws Exception
     * @throws \Exception
     */
    public static function all(): array
    {
        $conn = Database::getConnection();
        $schedules = $conn->fetchAllAssociative('SELECT * FROM schedules');
        foreach ($schedules as &$s) {
            $data = is_string($s['data']) ? $s['data'] : '{}';
            $s['data'] = json_decode($data, true);
        }
        return $schedules;
    }
}
