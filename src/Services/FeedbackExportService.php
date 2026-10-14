<?php

namespace App\Services;

use App\Documentation;
use App\Schedule;
use Doctrine\DBAL\Exception;

class FeedbackExportService
{
    private const CSV_HEADERS = [
        'Question',
        'Question Type',
        'Answer',
        'User',
        'User ID',
        'Guest ID',
        'Timestamp',
        'Timeslot',
        'Room',
        'Rating',
    ];

    /**
     * Build the feedback CSV for a schedule.
     *
     * Only feedback submitted through the event feedback form is included, i.e.
     * documentation entries whose content is a JSON object carrying a
     * feedback_answers map. Per-session documentation is a different shape and
     * is covered by the LLM/ZIP export instead.
     *
     * A schedule without feedback yields a headers-only CSV, since an export
     * with no data rows is still a valid download.
     *
     * @param string $hashedId
     * @param ?int $userId
     * @param ?string $role
     * @return ?string CSV content, or null when the schedule does not exist
     * @throws Exception
     * @throws \Exception
     */
    public static function toCsv(string $hashedId, ?int $userId = null, ?string $role = null): ?string
    {
        $schedule = Schedule::getByHashedId($hashedId);
        if (!$schedule) {
            return null;
        }

        $scheduleId = isset($schedule['hashed_id']) && is_string($schedule['hashed_id'])
            ? $schedule['hashed_id']
            : $hashedId;
        $questions = self::questionsById($schedule);

        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            throw new \RuntimeException('Could not open a temporary stream for the feedback CSV export');
        }

        try {
            fputcsv($output, self::CSV_HEADERS, escape: '');

            $docs = Documentation::getAllForSchedule($scheduleId, null, $userId, $role);
            foreach ($docs as $doc) {
                foreach (self::feedbackAnswers($doc) as $questionId => $answer) {
                    $row = self::row($doc, $questions, (string)$questionId, $answer);
                    fputcsv($output, $row, escape: '');
                }
            }

            rewind($output);
            $csv = stream_get_contents($output);
        } finally {
            fclose($output);
        }

        return $csv === false ? '' : $csv;
    }

    /**
     * Index the schedule's feedback questions by their id.
     *
     * Ids are normalised to strings on both sides so a numeric id in the
     * schedule data still matches the JSON object keys of a stored answer map.
     *
     * @param array<string, mixed> $schedule
     * @return array<string, array<string, mixed>>
     */
    private static function questionsById(array $schedule): array
    {
        $data = isset($schedule['data']) && is_array($schedule['data']) ? $schedule['data'] : [];
        $questions = isset($data['feedback_questions']) && is_array($data['feedback_questions'])
            ? $data['feedback_questions']
            : [];

        $byId = [];
        foreach ($questions as $question) {
            if (is_array($question) && isset($question['id']) && is_scalar($question['id'])) {
                $byId[(string)$question['id']] = $question;
            }
        }

        return $byId;
    }

    /**
     * Extract the feedback_answers map from a documentation entry.
     *
     * Anything that is not a JSON object with a feedback_answers map is not
     * feedback and contributes no rows.
     *
     * @param array<string, mixed> $doc
     * @return array<array-key, mixed>
     */
    private static function feedbackAnswers(array $doc): array
    {
        $content = isset($doc['content']) && is_string($doc['content']) ? $doc['content'] : '';
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['feedback_answers'])) {
            return [];
        }
        if (!is_array($decoded['feedback_answers'])) {
            return [];
        }

        return $decoded['feedback_answers'];
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, array<string, mixed>> $questions
     * @param string $questionId
     * @param mixed $answer
     * @return array<int, string>
     */
    private static function row(array $doc, array $questions, string $questionId, $answer): array
    {
        $question = $questions[$questionId] ?? null;

        $questionText = $questionId;
        $questionType = '';
        if (is_array($question)) {
            $questionText = isset($question['question']) && is_string($question['question'])
                ? $question['question']
                : $questionId;
            $questionType = isset($question['type']) && is_string($question['type'])
                ? $question['type']
                : '';
        }

        $rating = '';
        if ($questionType === 'rating' && is_numeric($answer)) {
            $rating = (string)$answer;
            $answerText = (string)$answer . '/10';
        } else {
            $answerText = self::answerToText($answer);
        }

        return [
            $questionText,
            $questionType,
            $answerText,
            self::stringField($doc, 'display_name'),
            isset($doc['user_id']) ? (string)$doc['user_id'] : '',
            self::stringField($doc, 'guest_id'),
            self::stringField($doc, 'created_at'),
            self::stringField($doc, 'timeslot'),
            self::stringField($doc, 'room'),
            $rating,
        ];
    }

    /**
     * @param array<string, mixed> $doc
     * @param string $key
     * @return string
     */
    private static function stringField(array $doc, string $key): string
    {
        return isset($doc[$key]) && is_scalar($doc[$key]) ? (string)$doc[$key] : '';
    }

    /**
     * Render an answer value for a single CSV cell.
     *
     * A skipped optional question is stored as null and must export as an empty
     * cell rather than the literal string "null".
     *
     * @param mixed $answer
     * @return string
     */
    private static function answerToText($answer): string
    {
        if ($answer === null) {
            return '';
        }
        if (is_scalar($answer)) {
            return (string)$answer;
        }
        $encoded = json_encode($answer);

        return $encoded === false ? '' : $encoded;
    }
}
