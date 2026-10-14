<?php

namespace Tests;

use App\Services\ScheduleValidationService;

class ScheduleValidationServiceTest extends BaseTestCase
{
    private function validData(): array
    {
        return [
            'rooms' => [['name' => 'Plenary', 'size' => 'L']],
            'timeslots' => [['name' => '10:00', 'hidden' => false]],
            'sessions' => [[
                'timeslot' => '10:00',
                'room' => 'Plenary',
                'title' => 'Keynote',
                'speakers' => 'John Doe',
                'format' => 'talk',
                'topic' => 'Tech',
                'audience_interest' => 'M',
                'link' => 'https://example.com',
            ]],
            'topics' => [['name' => 'Tech', 'colorIndex' => 1]],
            'ask_for_feedback' => true,
        ];
    }

    public function testValidDataPasses(): void
    {
        $result = ScheduleValidationService::validate($this->validData());
        $this->assertTrue($result['passed']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidMinimalDataPasses(): void
    {
        $data = [
            'rooms' => [['name' => 'R1']],
            'timeslots' => [['name' => '10:00']],
            'sessions' => [],
            'topics' => [['name' => 'T1']],
        ];
        $result = ScheduleValidationService::validate($data);
        $this->assertTrue($result['passed']);
    }

    public function testMissingRoomsFails(): void
    {
        $data = $this->validData();
        unset($data['rooms']);
        $result = ScheduleValidationService::validate($data);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('missing required', implode(' ', $result['errors']));
        $this->assertStringContainsString('rooms', implode(' ', $result['errors']));
    }

    public function testMissingTimeslotsFails(): void
    {
        $data = $this->validData();
        unset($data['timeslots']);
        $result = ScheduleValidationService::validate($data);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('timeslots', implode(' ', $result['errors']));
    }

    public function testMissingSessionsFails(): void
    {
        $data = $this->validData();
        unset($data['sessions']);
        $result = ScheduleValidationService::validate($data);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('sessions', implode(' ', $result['errors']));
    }

    public function testMissingTopicsFails(): void
    {
        $data = $this->validData();
        unset($data['topics']);
        $result = ScheduleValidationService::validate($data);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('topics', implode(' ', $result['errors']));
    }

    public function testRoomWithoutNameFails(): void
    {
        $data = $this->validData();
        $data['rooms'] = [['size' => 'M']];
        $result = ScheduleValidationService::validate($data);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('name', implode(' ', $result['errors']));
    }

    public function testInvalidSizeValueFails(): void
    {
        $data = $this->validData();
        $data['rooms'] = [['name' => 'R1', 'size' => 'XL']];
        $result = ScheduleValidationService::validate($data);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('rooms.0.size', implode(' ', $result['errors']));
    }

    public function testSessionMissingRequiredFieldsFails(): void
    {
        $data = $this->validData();
        $data['sessions'] = [['title' => 'Incomplete']];
        $result = ScheduleValidationService::validate($data);
        $this->assertFalse($result['passed']);
    }

    public function testWrongTypesFails(): void
    {
        $data = $this->validData();
        $data['timeslots'] = 'not-an-array';
        $result = ScheduleValidationService::validate($data);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('timeslots', implode(' ', $result['errors']));
    }

    public function testExtraPropertiesAreRejected(): void
    {
        $data = $this->validData();
        $data['rooms'][] = ['name' => 'R2', 'unknown_field' => 'value'];
        $result = ScheduleValidationService::validate($data);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('rooms.1', implode(' ', $result['errors']));
    }

    public function testEmptyDataReturnsAllMissingFields(): void
    {
        $result = ScheduleValidationService::validate([]);
        $this->assertFalse($result['passed']);
        $this->assertNotEmpty($result['errors']);
    }
}
