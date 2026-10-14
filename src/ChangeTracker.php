<?php

namespace App;

use Doctrine\DBAL\Exception;
use Firebase\JWT\JWT;

class ChangeTracker
{
    /**
     * @throws Exception
     */
    public static function update(string $scheduleId): void
    {
        // Compute and store the payload for the specific schedule
        $schedule = Schedule::getByHashedId($scheduleId);
        if (!$schedule) {
            return;
        }

        $documentationCounts = Documentation::getDocumentationCounts($scheduleId);

        $payload = [
            'schedule' => $schedule,
            'documentation_counts' => $documentationCounts,
        ];

        self::pushToMercure($scheduleId, $payload);
    }

    /**
     * @param string $scheduleId
     * @param array<string, mixed> $payload
     * @return void
     */
    private static function pushToMercure(string $scheduleId, array $payload): void
    {
        $mercureUrl = getenv('MERCURE_URL');
        $mercureSecret = (string)(getenv('MERCURE_JWT_SECRET') ?: '');

        if (!$mercureUrl || !$mercureSecret) {
            error_log("Mercure configuration missing (URL or Secret).");
            return;
        }

        // 1. Generate the Publisher Token (with 'publish' claim)
        $token = self::generateMercureToken($mercureSecret);

        // 2. Define the topic.
        // Pro-tip: Use an absolute URI or a unique string like 'https://app.local'
        // to avoid collisions.
        $topic = "schedule/$scheduleId";

        $postData = [
            'topic' => $topic,
            'data'  => json_encode($payload),
        ];

        $ch = curl_init($mercureUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token",
            "Content-Type: application/x-www-form-urlencoded"
        ]);

        // Strict timeouts prevent worker starvation when the Mercure hub is
        // slow or unresponsive. The schedule save itself has already succeeded
        // by the time we push, so a timeout here must never block the request.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 500);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1000);

        // Handle dev environments
        if (in_array(getenv('APP_ENV'), ['dev', 'test'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 && getenv('APP_ENV') !== 'test') {
            error_log("Mercure Push Failed: HTTP $httpCode - Response: $response");
        }
    }


    private static function generateMercureToken(string $secret): string
    {
        $payload = [
            'mercure' => [
                'publish' => ['*'],
            ],
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }
}
