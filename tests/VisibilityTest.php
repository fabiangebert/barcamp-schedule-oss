<?php

namespace Tests;

use App\App;
use App\Auth;
use App\Database;
use App\Documentation;
use App\Schedule;

class VisibilityTest extends BaseTestCase
{
    protected \Slim\App $app;
    protected string $hashedId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
        $this->hashedId = Schedule::create('visibility-camp', [
            'rooms' => ['Room A'],
            'timeslots' => ['10:00'],
            'sessions' => [['timeslot' => '10:00', 'room' => 'Room A', 'title' => 'Session A']]
        ]);
    }

    public function testDocumentationVisibility()
    {
        // 1. Session documentation by guest (should be public)
        Documentation::add(
            $this->hashedId,
            '10:00',
            'Room A',
            'Guest Session Doc',
            [],
            'Guest 1',
            'Session A',
            null,
            'guest-1'
        );

        // 2. Feedback by guest (should be private)
        Documentation::add(
            $this->hashedId,
            'event',
            'event',
            'Guest Event Feedback',
            [],
            'Guest 1',
            null,
            5,
            'guest-1'
        );

        // 3. Feedback by another guest (should be private to them)
        Documentation::add(
            $this->hashedId,
            'event',
            'event',
            'Another Guest Feedback',
            [],
            'Guest 2',
            null,
            4,
            'guest-2'
        );

        // 4. Feedback by registered user (should be public)
        $userEmail = 'user@example.com';
        Database::getConnection()->insert('users', ['email' => $userEmail, 'role' => 'user', 'name' => 'Real User']);
        $user = Database::getConnection()->fetchAssociative('SELECT id FROM users WHERE email = ?', [$userEmail]);
        Documentation::add(
            $this->hashedId,
            'event',
            'event',
            'User Event Feedback',
            [],
            null,
            null,
            10,
            null,
            (int)$user['id']
        );

        // TEST AS GUEST 1
        $docsSession = Documentation::getForSession($this->hashedId, '10:00', 'Room A', 'guest-1');
        $this->assertCount(1, $docsSession, 'Guest 1 should see the public session doc');
        $this->assertEquals('Guest Session Doc', $docsSession[0]['content']);

        $docsEvent = Documentation::getForSession($this->hashedId, 'event', 'event', 'guest-1');
        // Guest 1 should see: their own feedback (2), and user feedback (4). They should NOT see guest 2 feedback (3).
        $this->assertCount(2, $docsEvent, 'Guest 1 should see their own feedback and the public user feedback');
        $contents = array_column($docsEvent, 'content');
        $this->assertContains('Guest Event Feedback', $contents);
        $this->assertContains('User Event Feedback', $contents);
        $this->assertNotContains('Another Guest Feedback', $contents);

        // TEST AS GUEST 2
        $docsEventG2 = Documentation::getForSession($this->hashedId, 'event', 'event', 'guest-2');
        $this->assertCount(2, $docsEventG2);
        $contentsG2 = array_column($docsEventG2, 'content');
        $this->assertContains('Another Guest Feedback', $contentsG2);
        $this->assertContains('User Event Feedback', $contentsG2);
        $this->assertNotContains('Guest Event Feedback', $contentsG2);

        // TEST AS REGISTERED USER (non-admin) — users are organizers and see everything
        $docsEventUser = Documentation::getForSession(
            $this->hashedId,
            'event',
            'event',
            null,
            (int)$user['id'],
            'user'
        );
        $this->assertCount(3, $docsEventUser, 'Regular user (organizer) should see everything');
        $contentsUser = array_column($docsEventUser, 'content');
        $this->assertContains('Guest Event Feedback', $contentsUser);
        $this->assertContains('Another Guest Feedback', $contentsUser);
        $this->assertContains('User Event Feedback', $contentsUser);

        // TEST AS ADMIN — should see everything
        $docsEventAdmin = Documentation::getForSession(
            $this->hashedId,
            'event',
            'event',
            null,
            (int)$user['id'],
            'admin'
        );
        $this->assertCount(3, $docsEventAdmin, 'Admin should see EVERYTHING');
        $contentsAdmin = array_column($docsEventAdmin, 'content');
        $this->assertContains('Guest Event Feedback', $contentsAdmin);
        $this->assertContains('Another Guest Feedback', $contentsAdmin);
        $this->assertContains('User Event Feedback', $contentsAdmin);
    }
}
