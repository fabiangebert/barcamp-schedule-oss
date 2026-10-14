<?php

namespace Tests;

use App\Auth;
use App\Database;
use DateMalformedStringException;
use DateTime;
use Doctrine\DBAL\Exception;
use Random\RandomException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AuthTest extends BaseTestCase
{
    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testGenerateMagicToken()
    {
        $email = 'test@example.com';
        // First register the user
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email]);

        $token = Auth::generateMagicToken($email);
        $this->assertNotEmpty($token);

        $user = $conn->fetchAssociative('SELECT * FROM users WHERE email = ?', [$email]);
        $this->assertEquals($email, $user['email']);

        $loginToken = $conn->fetchAssociative('SELECT * FROM login_tokens WHERE email = ?', [$email]);
        $this->assertEquals($token, $loginToken['token']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testGenerateMagicTokenUnregistered()
    {
        $email = 'unregistered@example.com';
        $token = Auth::generateMagicToken($email);
        $this->assertNull($token);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testVerifyMagicToken()
    {
        $email = 'test@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $token = Auth::generateMagicToken($email);

        $result = Auth::verifyMagicToken($token);
        $this->assertIsArray($result);
        $jwt = $result['token'] ?? '';
        $this->assertNotEmpty($jwt);

        $decoded = Auth::validateJWT($jwt);
        $this->assertIsArray($decoded);
        $this->assertEquals($email, $decoded['sub'] ?? '');
        $this->assertEquals('user', $decoded['role'] ?? '');
    }

    /**
     * @throws Exception
     */
    public function testInvalidMagicToken()
    {
        $result = Auth::verifyMagicToken('invalid-token');
        $this->assertNull($result);
    }

    /**
     * @throws DateMalformedStringException
     * @throws Exception
     * @throws \Exception
     */
    public function testExpiredMagicToken()
    {
        $email = 'expired@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', [
            'email' => $email,
            'role' => 'user'
        ]);
        $conn->insert('login_tokens', [
            'email' => $email,
            'token' => 'expired-token',
            'expires_at' => (new DateTime())->modify('-1 minute')->format('Y-m-d H:i:s')
        ]);

        $result = Auth::verifyMagicToken('expired-token');
        $this->assertNull($result);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testRateLimit()
    {
        $email = 'ratelimit@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email]);

        // Request 3 tokens
        $t1 = Auth::generateMagicToken($email);
        $t2 = Auth::generateMagicToken($email);
        $t3 = Auth::generateMagicToken($email);

        $this->assertNotEmpty($t1);
        $this->assertNotEmpty($t2);
        $this->assertNotEmpty($t3);

        // 4th token should throw rate limit exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Rate limit exceeded');
        Auth::generateMagicToken($email);
    }

    public function testValidateInvalidJWT()
    {
        $this->assertNull(Auth::validateJWT('invalid-jwt'));
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function testSendMagicLinkUsesEnvVars()
    {
        $email = 'test@example.com';
        $token = 'test-token';

        // Setup env vars
        putenv('APP_URL=https://myapp.com');
        putenv('SMTP_DSN=null://default');
        putenv('MAIL_FROM=sender@myapp.com');
        putenv('MAIL_SUBJECT=Custom Subject');
        putenv('MAIL_BODY=Custom Body {link}');
        putenv('APP_ENV=dev');

        // We don't want it to actually try sending via SMTP if it's not dev,
        // but in dev it will just print to stderr.
        // Since we can't easily mock the Mailer or Transport without refactoring,
        // we at least ensure it doesn't crash and we could potentially capture stderr if needed.

        Auth::sendMagicLink($email, $token);

        // If it reaches here without exception, it's a good sign.
        $this->assertTrue(true);

        // Cleanup
        putenv('APP_URL');
        putenv('SMTP_DSN');
        putenv('MAIL_FROM');
        putenv('MAIL_SUBJECT');
        putenv('MAIL_BODY');
        putenv('APP_ENV');
    }
}
