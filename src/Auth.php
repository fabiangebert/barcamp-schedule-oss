<?php

namespace App;

use DateMalformedStringException;
use DateTime;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Random\RandomException;
use stdClass;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

class Auth
{
    private static function getSecret(): string
    {
        return getenv('JWT_SECRET');
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    public static function generateMagicToken(string $email): ?string
    {
        $conn = Database::getConnection();

        // Check if user exists
        $user = $conn->fetchAssociative('SELECT id FROM users WHERE email = ?', [$email]);
        if (!$user) {
            return null; // Only registered users may get a magic link
        }

        $now = (new DateTime())->format('Y-m-d H:i:s');

        // Cleanup expired tokens
        $conn->executeStatement('DELETE FROM login_tokens WHERE expires_at <= ?', [$now]);

        // Per-email rate limiting to prevent spamming a single inbox
        $recentTokens = (int)$conn->fetchOne(
            'SELECT COUNT(*) FROM login_tokens WHERE email = ? AND expires_at > ?',
            [$email, $now]
        );

        if ($recentTokens >= 3) {
            throw new Exception('Rate limit exceeded');
        }

        $token = bin2hex(random_bytes(32));
        $expires = (new DateTime())->modify('+15 minutes')->format('Y-m-d H:i:s');

        $conn->insert('login_tokens', [
            'email' => $email,
            'token' => $token,
            'expires_at' => $expires
        ]);

        return $token;
    }

    /**
     * @throws TransportExceptionInterface
     * @throws Exception
     */
    public static function sendMagicLink(string $email, string $token): void
    {
        $baseUrl = getenv('APP_URL') ?: 'http://localhost:5173';
        $link = rtrim($baseUrl, '/') . "/#token=" . $token;

        // Dev mode: print magic link to console
        if (getenv('APP_ENV') === 'dev') {
            print "Magic link for $email: $link\n";
            file_put_contents('php://stderr', "--- MAGIC LINK FOR $email: $link ---\n");
        }

        try {
            $dsn = getenv('SMTP_DSN') ?: throw new Exception('SMTP_DSN environment variable is required');
            $from = getenv('MAIL_FROM') ?: throw new Exception('MAIL_FROM environment variable is required');

            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);

            $subject = getenv('MAIL_SUBJECT') ?: 'Your Magic Login Link';
            $body = getenv('MAIL_BODY') ?: "Click here to login: {link}";
            $body = str_replace('{link}', $link, $body);

            $emailObj = (new Email())
                ->from($from)
                ->to($email)
                ->subject($subject)
                ->text($body);

            $mailer->send($emailObj);
        } catch (Exception $e) {
            // In dev, we don't care if mailing fails if we printed it to console
            if (getenv('APP_ENV') !== 'dev') {
                throw $e;
            }
        }
    }

    /**
     * @param string $token
     * @return array{token: string, user: array{id: int, sub: string, role: string, name: ?string}}|null
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    public static function verifyMagicToken(string $token): ?array
    {
        $conn = Database::getConnection();
        $now = (new DateTime())->format('Y-m-d H:i:s');

        // Check if token exists and is valid
        $loginToken = $conn->fetchAssociative(
            'SELECT email FROM login_tokens WHERE token = ? AND expires_at > ?',
            [$token, $now]
        );

        if (!$loginToken) {
            return null;
        }

        $email = is_string($loginToken['email']) ? $loginToken['email'] : '';

        // Retrieve user details from users table
        $user = $conn->fetchAssociative(
            'SELECT id, role, name FROM users WHERE email = ?',
            [$email]
        );

        if (!$user) {
            return null;
        }

        $role = is_string($user['role']) ? $user['role'] : 'user';
        $name = is_string($user['name'] ?? null) ? $user['name'] : null;
        $id = is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;

        // Atomic consume: only one request can delete the token.
        // We delete by token and check affected rows to prevent TOCTOU.
        $affected = $conn->executeStatement(
            'DELETE FROM login_tokens WHERE token = ? AND expires_at > ?',
            [$token, $now]
        );

        if ($affected === 0) {
            return null;
        }

        return [
            'token' => self::generateJWT($email, $role, $name, null, $id, null),
            'user' => [
                'id' => $id,
                'sub' => $email,
                'role' => $role,
                'name' => $name
            ]
        ];
    }

    public static function generateJWT(
        string $email,
        string $role,
        ?string $userName = null,
        ?int $expirySeconds = null,
        ?int $userId = null,
        ?int $linkExpiryTimestamp = null
    ): string {
        if ($expirySeconds === null) {
            $expirySeconds = (int)(getenv('JWT_EXPIRY_SECONDS') ?: 86400);
        }
        $payload = [
            'iss' => 'barcamp-schedule',
            'sub' => $email,
            'role' => $role,
            'name' => $userName,
            'id' => $userId,
            'iat' => time(),
            'exp' => time() + $expirySeconds,
            'link_exp' => $linkExpiryTimestamp
        ];

        return JWT::encode($payload, self::getSecret(), 'HS256');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function validateJWT(string $jwt): ?array
    {
        if (empty($jwt)) {
            return null;
        }
        try {
            $decoded = JWT::decode($jwt, new Key(self::getSecret(), 'HS256'));
            return (array)$decoded;
        } catch (Exception $e) {
            return null;
        }
    }
}
