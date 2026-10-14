<?php

namespace Tests;

use App\Database;
use App\App;
use App\Auth;
use DateMalformedStringException;
use Doctrine\DBAL\Exception;
use Random\RandomException;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class UserRoutesTest extends BaseTestCase
{
    protected \Slim\App $app;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
    }

    private function createRequest(string $method, string $path, string $jwt = '', ?array $body = null): Request
    {
        $uri = new Uri('', '', null, "/api$path");
        $handle = fopen('php://temp', 'w+');
        if ($body !== null) {
            fwrite($handle, json_encode($body));
        }
        rewind($handle);

        $headersObj = new Headers();
        $headersObj->addHeader('Content-Type', 'application/json');
        if ($jwt) {
            $headersObj->addHeader('Authorization', "Bearer $jwt");
        }

        return new Request($method, $uri, $headersObj, [], $_SERVER, new Stream($handle));
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    private function getAdminJwt(): string
    {
        $result = Auth::verifyMagicToken(Auth::generateMagicToken('admin@example.com'));
        return $result['token'] ?? '';
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testGetUsers()
    {
        $jwt = $this->getAdminJwt();
        $request = $this->createRequest('GET', '/users', $jwt);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $users = $data['data'];
        $this->assertIsArray($users);
        $this->assertNotEmpty($users);

        // At least the admin should be there
        $adminFound = false;
        foreach ($users as $user) {
            if ($user['email'] === 'admin@example.com') {
                $adminFound = true;
                $this->assertEquals('admin', $user['role']);
            }
        }
        $this->assertTrue($adminFound);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testAddUser()
    {
        $jwt = $this->getAdminJwt();
        $newUser = ['email' => 'newuser@example.com', 'role' => 'user'];
        $request = $this->createRequest('POST', '/users', $jwt, $newUser);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());

        $conn = Database::getConnection();
        $user = $conn->fetchAssociative('SELECT * FROM users WHERE email = ?', [$newUser['email']]);
        $this->assertNotFalse($user);
        $this->assertEquals('user', $user['role']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testUpdateExistingUserRole()
    {
        $jwt = $this->getAdminJwt();
        $email = 'existing@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);

        $request = $this->createRequest('POST', '/users', $jwt, ['email' => $email, 'role' => 'admin']);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());

        $user = $conn->fetchAssociative('SELECT * FROM users WHERE email = ?', [$email]);
        $this->assertEquals('admin', $user['role']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testAddUserInvalidEmail()
    {
        $jwt = $this->getAdminJwt();
        $request = $this->createRequest('POST', '/users', $jwt, ['email' => 'invalid-email']);
        $response = $this->app->handle($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('Invalid email', $data['error']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws Exception
     * @throws RandomException
     * @throws \Exception
     */
    public function testDeleteUser()
    {
        $jwt = $this->getAdminJwt();
        $email = 'to-delete@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user']);
        $id = $conn->lastInsertId();

        $request = $this->createRequest('DELETE', "/users/$id", $jwt);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());

        $user = $conn->fetchAssociative('SELECT * FROM users WHERE id = ?', [$id]);
        $this->assertFalse($user);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testDeleteNonExistentUserReturns404()
    {
        $jwt = $this->getAdminJwt();
        $request = $this->createRequest('DELETE', '/users/999999', $jwt);
        $response = $this->app->handle($request);

        $this->assertEquals(404, $response->getStatusCode(), "Deleting non-existent user should return 404");
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testGetUserMeReturnsUserData()
    {
        $email = 'user_me@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user', 'name' => 'Me User']);
        $user = $conn->fetchAssociative('SELECT id FROM users WHERE email = ?', [$email]);
        $userId = (int)$user['id'];

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'];

        $request = $this->createRequest('GET', '/user/me', $jwt);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals($email, $data['data']['sub']);
        $this->assertEquals('user', $data['data']['role']);
        $this->assertEquals('Me User', $data['data']['name']);
        $this->assertEquals($userId, $data['data']['id']);
    }

    public function testGetUserMeWithoutAuthReturns401()
    {
        $request = $this->createRequest('GET', '/user/me');
        $response = $this->app->handle($request);

        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testPostUserNameUpdatesGuestToken()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Old Guest');

        $request = $this->createRequest('POST', '/user/name', $jwt, ['name' => 'New Guest']);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEquals($jwt, $data['token']);
        $this->assertEquals('New Guest', $data['user']['name']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws Exception
     */
    public function testPostUserNameUpdatesDatabaseForRegisteredUser()
    {
        $email = 'update_name@example.com';
        $conn = Database::getConnection();
        $conn->insert('users', ['email' => $email, 'role' => 'user', 'name' => 'Old Name']);

        $token = Auth::generateMagicToken($email);
        $result = Auth::verifyMagicToken($token);
        $jwt = $result['token'];

        $request = $this->createRequest('POST', '/user/name', $jwt, ['name' => 'New Name']);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('New Name', $data['user']['name']);

        $updated = $conn->fetchAssociative('SELECT name FROM users WHERE email = ?', [$email]);
        $this->assertEquals('New Name', $updated['name']);
    }

    public function testPostUserNameEmptyReturns400()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Guest');

        $request = $this->createRequest('POST', '/user/name', $jwt, ['name' => '']);
        $response = $this->app->handle($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status']);
        $this->assertEquals('Name cannot be empty', $data['error']);
    }

    public function testPostUserNameWithoutAuthReturns401()
    {
        $request = $this->createRequest('POST', '/user/name', '', ['name' => 'Unauthed']);
        $response = $this->app->handle($request);

        $this->assertEquals(401, $response->getStatusCode());
    }
}
