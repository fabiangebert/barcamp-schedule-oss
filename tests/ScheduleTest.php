<?php

namespace Tests;

use App\Database;
use App\Schedule;
use Doctrine\DBAL\Exception;
use Random\RandomException;

class ScheduleTest extends BaseTestCase
{
    /**
     * @throws RandomException
     * @throws Exception
     */
    public function testCreateAndGetSchedule()
    {
        $name = 'test-schedule';
        $data = ['rooms' => ['Room 1'], 'timeslots' => ['10:00'], 'sessions' => []];

        $hashedId = Schedule::create($name, $data);
        $this->assertNotNull($hashedId);

        $schedule = Schedule::getByHashedId($hashedId);
        $this->assertNotNull($schedule);
        $this->assertEquals($name, $schedule['name']);
        $this->assertEquals($data, $schedule['data']);
        $this->assertEquals(1, $schedule['version']);
    }

    /**
     * @throws RandomException
     * @throws Exception
     */
    public function testVersionMismatch()
    {
        $name = 'test-schedule';
        $data = ['sessions' => []];

        $hashedId = Schedule::create($name, $data);

        // Try to save with wrong version
        $result = Schedule::saveByHashedId($hashedId, $data, 0); // initial version is 1
        $this->assertEquals('version_mismatch', $result);

        $result = Schedule::saveByHashedId($hashedId, $data, 1);
        $this->assertTrue($result);
    }

    /**
     * @throws RandomException
     * @throws Exception
     */
    public function testUpdateSchedule()
    {
        $name = 'test-schedule';
        $data1 = ['v' => 1];
        $data2 = ['v' => 2];

        $hashedId = Schedule::create($name, $data1);
        $result = Schedule::saveByHashedId($hashedId, $data2, 1);

        $this->assertTrue($result);
        $schedule = Schedule::getByHashedId($hashedId);
        $this->assertEquals($data2, $schedule['data']);
        $this->assertEquals(2, $schedule['version']);
    }

    /**
     * @throws RandomException
     * @throws Exception
     */
    public function testRenameSchedule()
    {
        $oldName = 'old-name';
        $newName = 'new-name';
        $data = ['test' => true];

        $hashedId = Schedule::create($oldName, $data);
        $success = Schedule::renameByHashedId($hashedId, $newName);

        $this->assertTrue($success);
        $schedule = Schedule::getByHashedId($hashedId);
        $this->assertNotNull($schedule);
        $this->assertEquals($newName, $schedule['name']);
        $this->assertEquals($data, $schedule['data']);
    }

    /**
     * @throws RandomException
     * @throws Exception
     */
    public function testRenameConflict()
    {
        $name1 = 'name1';
        $name2 = 'name2';
        $data = ['test' => true];

        Schedule::create($name1, $data);
        Schedule::create($name2, $data);

        $schedule1 = Schedule::all()[0]; // Just get one
        $hashedId1 = $schedule1['hashed_id'];
        $name1_actual = $schedule1['name'];

        $success = Schedule::renameByHashedId($hashedId1, ($name1_actual === 'name1' ? 'name2' : 'name1'));
        $this->assertFalse($success);
    }

    /**
     * @throws RandomException
     * @throws Exception
     */
    public function testNameExists()
    {
        $name = 'unique-name-check';
        $data = ['rooms' => [], 'timeslots' => [], 'sessions' => []];

        $this->assertFalse(Schedule::nameExists($name));
        Schedule::create($name, $data);
        $this->assertTrue(Schedule::nameExists($name));
    }

    /**
     * @throws RandomException
     * @throws Exception
     */
    public function testCreateWithDate()
    {
        $name = 'date-schedule';
        $data = ['rooms' => [], 'timeslots' => [], 'sessions' => []];
        $date = '2026-03-20';

        $hashedId = Schedule::create($name, $data, $date);
        $this->assertNotNull($hashedId);

        $schedule = Schedule::getByHashedId($hashedId);
        $this->assertEquals($date, $schedule['date']);
    }

    /**
     * @throws RandomException
     * @throws Exception
     */
    public function testUpdateDate()
    {
        $name = 'update-date-schedule';
        $data = ['rooms' => [], 'timeslots' => [], 'sessions' => []];
        $date1 = '2026-03-20';
        $date2 = '2026-03-21';

        $hashedId = Schedule::create($name, $data, $date1);
        $result = Schedule::saveByHashedId($hashedId, $data, 1, $date2);

        $this->assertTrue($result);
        $schedule = Schedule::getByHashedId($hashedId);
        $this->assertEquals($date2, $schedule['date']);
    }

    /**
     * @throws RandomException
     * @throws Exception
     * @throws \Exception
     */
    public function testAllSchedules()
    {
        $conn = Database::getConnection();
        $conn->executeStatement('DELETE FROM schedules');

        Schedule::create('s1', []);
        Schedule::create('s2', []);

        $all = Schedule::all();
        $this->assertCount(2, $all);
    }

    /**
     * @throws RandomException
     * @throws Exception
     */
    public function testDeleteSchedule()
    {
        $name = 'to-be-deleted';
        $data = ['test' => true];

        $hashedId = Schedule::create($name, $data);
        $this->assertNotNull(Schedule::getByHashedId($hashedId));

        $success = Schedule::deleteByHashedId($hashedId);
        $this->assertTrue($success);
        $this->assertNull(Schedule::getByHashedId($hashedId));
    }
}
