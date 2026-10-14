<?php

namespace App;

use App\Services\S3Service;
use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use ZipArchive;

class Documentation
{
    /**
     * @param string $id
     * @param string $timeslot
     * @param string $room
     * @param ?string $guestId
     * @param ?int $userId
     * @return array<int, array<string, mixed>>
     * @throws Exception
     * @throws \Exception
     */
    public static function getForSession(
        string $id,
        string $timeslot,
        string $room,
        ?string $guestId = null,
        ?int $userId = null,
        ?string $role = null
    ): array {
        $conn = Database::getConnection();

        $schedule = Schedule::getByHashedId($id);
        $hashedId = $schedule ? $schedule['hashed_id'] : $id;

        $sql = 'SELECT d.*, u.name as user_real_name, u.role as user_role FROM documentation d 
                LEFT JOIN users u ON d.user_id = u.id 
                WHERE d.schedule_id = ? AND d.timeslot = ? AND d.room = ?';
        $params = [$hashedId, $timeslot, $room];

        // Fetching user who is requesting to determine visibility
        // Actually, requester is already defined by userId (if provided)

        $sql .= ' AND (';
        $conditions = [];
        // 1. Show all public documentation
        $conditions[] = 'd.is_public = TRUE';
        // 2. Own guest feedback (even if private)
        if ($guestId !== null) {
            $conditions[] = 'd.guest_id = ?';
            $params[] = $guestId;
        }
        // 3. Own user feedback (even if private)
        if ($userId !== null) {
            $conditions[] = 'd.user_id = ?';
            $params[] = $userId;
        }

        // 4. Admins and users can see all private content
        if (in_array($role, ['admin', 'user'], true)) {
            $conditions[] = '1 = 1';
        }
        $sql .= implode(' OR ', $conditions) . ')';

        $sql .= ' ORDER BY d.created_at';

        $docs = $conn->fetchAllAssociative($sql, $params);

        foreach ($docs as &$doc) {
            $doc['display_name'] = $doc['user_real_name'] ?: ($doc['guest_name'] ?: 'Anonymous');
        }

        return self::attachImages($docs);
    }

    /**
     * @param string $id
     * @param ?string $guestId
     * @param ?int $userId
     * @return array<int, array<string, mixed>>
     * @throws Exception
     * @throws \Exception
     */
    public static function getAllForSchedule(
        string $id,
        ?string $guestId = null,
        ?int $userId = null,
        ?string $role = null
    ): array {
        $conn = Database::getConnection();
        // Try fetching as hashed_id first
        $schedule = Schedule::getByHashedId($id);
        $hashedId = $schedule ? $schedule['hashed_id'] : $id;

        $sql = 'SELECT d.*, u.name as user_real_name 
                FROM documentation d 
                LEFT JOIN users u ON d.user_id = u.id 
                WHERE d.schedule_id = ?';
        $params = [$hashedId];

        $sql .= ' AND (';
        $conditions = [];
        // 1. Show all public documentation
        $conditions[] = 'd.is_public = TRUE';
        // 2. Own guest feedback (even if private)
        if ($guestId !== null) {
            $conditions[] = 'd.guest_id = ?';
            $params[] = $guestId;
        }
        // 3. Own user feedback
        if ($userId !== null) {
            $conditions[] = 'd.user_id = ?';
            $params[] = $userId;
        }
        // 4. Admins and users can see all private content
        if (in_array($role, ['admin', 'user'], true)) {
            $conditions[] = '1 = 1';
        }
        $sql .= implode(' OR ', $conditions) . ')';

        $sql .= ' ORDER BY d.timeslot, d.room, d.created_at';

        $docs = $conn->fetchAllAssociative($sql, $params);

        foreach ($docs as &$doc) {
            $doc['display_name'] = $doc['user_real_name'] ?: ($doc['guest_name'] ?: 'Anonymous');
        }

        return self::attachImages($docs);
    }

    /**
     * @param array<int, array<string, mixed>> $docs
     * @return array<int, array<string, mixed>>
     * @throws Exception
     * @throws \Exception
     */
    private static function attachImages(array $docs): array
    {
        if (empty($docs)) {
            return [];
        }
        $conn = Database::getConnection();
        $docIds = array_column($docs, 'id');
        $images = $conn->fetchAllAssociative(
            'SELECT documentation_id, image_path FROM documentation_images WHERE documentation_id IN (?)',
            [$docIds],
            [ArrayParameterType::INTEGER]
        );
        $imagesByDocId = [];
        foreach ($images as $img) {
            $docId = $img['documentation_id'] ?? null;
            $path = $img['image_path'] ?? null;
            if (is_scalar($docId) && is_string($path)) {
                $imagesByDocId[(string)$docId][] = $path;
            }
        }

        foreach ($docs as &$doc) {
            $id = isset($doc['id']) && is_scalar($doc['id']) ? (string)$doc['id'] : '';
            $doc['images'] = $imagesByDocId[$id] ?? [];

            // Backwards compatibility for single image_path if it still exists
            if (isset($doc['image_path']) && $doc['image_path'] && !in_array($doc['image_path'], $doc['images'])) {
                array_unshift($doc['images'], $doc['image_path']);
            }
        }
        return $docs;
    }

    /**
     * @param string $id
     * @param string $timeslot
     * @param string $room
     * @param ?string $content
     * @param string|array<string>|null $imagePaths
     * @param ?string $guestName
     * @param ?string $sessionTitle
     * @param ?int $rating
     * @param ?string $guestId
     * @param ?int $userId
     * @param ?bool $isPublic
     * @return bool
     * @throws Exception
     * @throws \Exception
     */
    public static function add(
        string $id,
        string $timeslot,
        string $room,
        ?string $content,
        array|string|null $imagePaths = [],
        ?string $guestName = null,
        ?string $sessionTitle = null,
        ?int $rating = null,
        ?string $guestId = null,
        ?int $userId = null,
        ?bool $isPublic = null
    ): bool {
        if ($userId !== null && $guestName !== null && $guestName !== '') {
            throw new \InvalidArgumentException('Logged-in users must not specify a guest_name');
        }
        if ($imagePaths === null) {
            $imagePaths = [];
        }
        if (is_string($imagePaths)) {
            $imagePaths = [$imagePaths];
        }
        /** @var array<string> $imagePaths */
        $conn = Database::getConnection();

        // Try fetching as hashed_id first
        $schedule = Schedule::getByHashedId($id);
        $hashedId = $schedule ? $schedule['hashed_id'] : $id;

        // Default visibility:
        // - sessions are public by default
        // - user feedback is public by default
        // - guest feedback is private by default
        if ($isPublic === null) {
            $isSessionDoc = ($timeslot !== 'event' || $room !== 'event');
            $isUserFeedback = ($userId !== null);
            $isPublic = ($isSessionDoc || $isUserFeedback);
        }

        $image_path = count($imagePaths) === 1 ? $imagePaths[0] : null;

        $conn->beginTransaction();
        try {
            $conn->insert('documentation', [
                'schedule_id' => $hashedId,
                'timeslot' => $timeslot,
                'room' => $room,
                'user_id' => $userId,
                'guest_name' => $guestName,
                'session_title' => $sessionTitle,
                'content' => $content,
                'image_path' => $image_path,
                'rating' => $rating,
                'guest_id' => $guestId,
                'is_public' => $isPublic ? 1 : 0,
                'created_at' => (new DateTime())->format('Y-m-d H:i:s')
            ]);

            $docId = $conn->lastInsertId();
            foreach ($imagePaths as $path) {
                $conn->insert('documentation_images', [
                    'documentation_id' => $docId,
                    'image_path' => $path
                ]);
            }
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
        return true;
    }

    /**
     * @param string $session_id
     * @return array<int, array<string, mixed>>
     * @throws Exception
     * @throws \Exception
     */
    public static function getDocumentationCounts(string $session_id): array
    {
        $conn = Database::getConnection();
        $schedule = Schedule::getByHashedId($session_id);
        $hashedId = $schedule ? $schedule['hashed_id'] : $session_id;

        return $conn->fetchAllAssociative(
            'SELECT timeslot, room, COUNT(*) as count, AVG(rating) as avg_rating 
             FROM documentation 
             WHERE schedule_id = ? 
             GROUP BY timeslot, room',
            [$hashedId]
        );
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public static function getLatestTimestamp(string $id): ?string
    {
        $conn = Database::getConnection();
        $schedule = Schedule::getByHashedId($id);
        $hashedId = $schedule ? $schedule['hashed_id'] : $id;

        $timestamp = $conn->fetchOne(
            'SELECT MAX(created_at) FROM documentation WHERE schedule_id = ?',
            [$hashedId]
        );
        return is_string($timestamp) ? $timestamp : null;
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public static function delete(int $id): bool
    {
        $conn = Database::getConnection();
        // Get all images before deleting
        $images = $conn->fetchAllAssociative(
            'SELECT image_path FROM documentation_images WHERE documentation_id = ?',
            [$id]
        );

        // Also check the old single image_path column
        $doc = $conn->fetchAssociative('SELECT image_path FROM documentation WHERE id = ?', [$id]);
        if ($doc && $doc['image_path']) {
            $images[] = ['image_path' => $doc['image_path']];
        }

        // File cleanup is best-effort — don't let S3 failures block DB deletion
        try {
            if (S3Service::isConfigured()) {
                foreach ($images as $img) {
                    if ($img['image_path'] && is_string($img['image_path'])) {
                        $filename = basename($img['image_path']);
                        S3Service::delete('uploads/' . $filename);
                    }
                }
            } else {
                $directory = getenv('UPLOADS_DIR') ?: (__DIR__ . '/../public/uploads');

                foreach ($images as $img) {
                    if ($img['image_path'] && is_string($img['image_path'])) {
                        $filename = basename($img['image_path']);
                        $filePath = $directory . DIRECTORY_SEPARATOR . $filename;
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('File cleanup failed during documentation deletion: ' . $e->getMessage());
        }

        $conn->beginTransaction();
        try {
            $conn->delete('documentation_images', ['documentation_id' => $id]);
            $result = $conn->delete('documentation', ['id' => $id]) > 0;
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
        return $result;
    }

    /**
     * @throws Exception
     */
    public static function exportToMarkdown(string $hashedId, ?int $userId = null, ?string $role = null): string
    {
        $schedule = Schedule::getByHashedId($hashedId);
        if (!$schedule) {
            return "Schedule not found.";
        }

        $id = isset($schedule['hashed_id']) && is_string($schedule['hashed_id']) ? $schedule['hashed_id'] : '';
        $name = isset($schedule['name']) && is_string($schedule['name']) ? $schedule['name'] : 'Schedule';
        $docs = self::getAllForSchedule($id, null, $userId, $role);
        return self::formatMarkdown($name, $docs, $schedule);
    }

    /**
     * @param string $scheduleName
     * @param array<int, array<string, mixed>> $docs
     * @param array<string, mixed> $schedule
     * @return string
     */
    private static function formatMarkdown(string $scheduleName, array $docs, array $schedule): string
    {
        $output = "# Documentation for " . $scheduleName . "\n\n";

        // Group by session
        $grouped = [];
        foreach ($docs as $doc) {
            $timeslot = isset($doc['timeslot']) && is_string($doc['timeslot']) ? $doc['timeslot'] : '';
            $room = isset($doc['room']) && is_string($doc['room']) ? $doc['room'] : '';
            $key = $timeslot . " | " . $room;
            $grouped[$key][] = $doc;
        }

        foreach ($grouped as $key => $items) {
            [$timeslot, $room] = explode(" | ", $key);
            $session = null;
            $data = is_array($schedule['data']) ? $schedule['data'] : [];
            $sessions = isset($data['sessions']) && is_array($data['sessions']) ? $data['sessions'] : [];
            foreach ($sessions as $s) {
                if (is_array($s) && ($s['timeslot'] ?? '') === $timeslot && ($s['room'] ?? '') === $room) {
                    $session = $s;
                    break;
                }
            }

            $title = is_array($session) && isset($session['title']) && is_string($session['title'])
                ? $session['title']
                : '(Untitled)';
            $output .= "## " . $timeslot . " - " . $room . ": " . $title . "\n\n";

            if (is_array($session)) {
                $topic = isset($session['topic']) && is_string($session['topic']) ? $session['topic'] : 'N/A';
                $speakers = isset($session['speakers']) && is_string($session['speakers'])
                    ? $session['speakers']
                    : 'N/A';
                $format = isset($session['format']) && is_string($session['format']) ? $session['format'] : 'N/A';
                $output .= "Topic: " . $topic . "\n";
                $output .= "Speakers: " . $speakers . "\n";
                $output .= "Format: " . $format . "\n\n";
            }

            foreach ($items as $item) {
                $author = $item['display_name'] ?: 'Unknown';
                $created_at = isset($item['created_at']) && is_string($item['created_at'])
                    ? $item['created_at']
                    : 'Unknown';
                $output .= "### Comment by " . $author . " at " . $created_at . "\n";
                if (isset($item['rating']) && is_numeric($item['rating'])) {
                    $output .= "Rating: " . $item['rating'] . "/10\n";
                }
                if (isset($item['content']) && is_string($item['content']) && $item['content']) {
                    $output .= $item['content'] . "\n\n";
                }

                $images = isset($item['images']) && is_array($item['images']) ? $item['images'] : [];
                if (empty($images) && isset($item['image_path']) && is_string($item['image_path'])) {
                    $images = [$item['image_path']];
                }

                foreach ($images as $imgPath) {
                    if (is_string($imgPath)) {
                        $output .= "![Image](" . basename($imgPath) . ")\n\n";
                    }
                }
            }
            $output .= "---\n\n";
        }

        return $output;
    }

    /**
     * @throws Exception
     */
    public static function exportToZip(string $hashedId, ?int $userId = null, ?string $role = null): ?string
    {
        $schedule = Schedule::getByHashedId($hashedId);
        if (!$schedule) {
            return null;
        }

        $id = isset($schedule['hashed_id']) && is_string($schedule['hashed_id']) ? $schedule['hashed_id'] : '';
        $name = isset($schedule['name']) && is_string($schedule['name']) ? $schedule['name'] : 'Schedule';
        $docs = self::getAllForSchedule($id, null, $userId, $role);
        if (empty($docs)) {
            return null;
        }

        $zip = new ZipArchive();
        $zipFileBase = tempnam(sys_get_temp_dir(), 'doc_export_');
        if ($zipFileBase === false) {
            return null;
        }
        $zipFile = $zipFileBase . '.zip';

        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        // Group by session
        $grouped = [];
        foreach ($docs as $doc) {
            $timeslot = isset($doc['timeslot']) && is_string($doc['timeslot']) ? $doc['timeslot'] : '';
            $room = isset($doc['room']) && is_string($doc['room']) ? $doc['room'] : '';
            $key = $timeslot . " | " . $room;
            $grouped[$key][] = $doc;
        }

        $uploadsDir = (string)getenv('UPLOADS_DIR');
        if ($uploadsDir === '') {
            $uploadsDir = __DIR__ . '/../public/uploads';
        }
        $useS3 = S3Service::isConfigured();
        $tempDir = sys_get_temp_dir() . '/s3_export_' . bin2hex(random_bytes(4));

        foreach ($grouped as $key => $items) {
            [$timeslot, $room] = explode(" | ", $key);
            $folderName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $timeslot . ' - ' . $room);

            $session = null;
            $data = is_array($schedule['data']) ? $schedule['data'] : [];
            $sessions = isset($data['sessions']) && is_array($data['sessions']) ? $data['sessions'] : [];
            foreach ($sessions as $s) {
                if (is_array($s) && ($s['timeslot'] ?? '') === $timeslot && ($s['room'] ?? '') === $room) {
                    $session = $s;
                    break;
                }
            }

            $sessionMarkdown = self::formatMarkdown($name, $items, $schedule);
            $zip->addFromString($folderName . '/README.md', $sessionMarkdown);

            foreach ($items as $item) {
                $images = isset($item['images']) && is_array($item['images']) ? $item['images'] : [];
                if (empty($images) && isset($item['image_path']) && is_string($item['image_path'])) {
                    $images = [$item['image_path']];
                }

                foreach ($images as $imgPath) {
                    if (is_string($imgPath)) {
                        $filename = basename($imgPath);
                        if ($useS3) {
                            $content = S3Service::get('uploads/' . $filename);
                            if ($content !== null) {
                                $tmpFile = $tempDir . '/' . $filename;
                                if (!is_dir(dirname($tmpFile))) {
                                    mkdir(dirname($tmpFile), 0777, true);
                                }
                                file_put_contents($tmpFile, $content);
                                $zip->addFile($tmpFile, $folderName . '/' . $filename);
                            }
                        } else {
                            $filePath = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
                            if (file_exists($filePath)) {
                                $zip->addFile($filePath, $folderName . '/' . $filename);
                            }
                        }
                    }
                }
            }
        }

        $zip->close();

        if ($useS3 && is_dir($tempDir)) {
            array_map('unlink', glob($tempDir . '/*'));
            rmdir($tempDir);
        }

        return $zipFile;
    }
}
