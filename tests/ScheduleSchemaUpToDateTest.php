<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class ScheduleSchemaUpToDateTest extends TestCase
{
    private string $schemaPath;
    private string $frontendDir;

    protected function setUp(): void
    {
        $this->schemaPath = dirname(__DIR__) . '/backend/resources/schedule.schema.json';
        $this->frontendDir = dirname(__DIR__) . '/frontend';
    }

    public function testSchemaIsUpToDate(): void
    {
        $committed = file_get_contents($this->schemaPath);
        if ($committed === false) {
            $this->fail('Committed schema file not found at ' . $this->schemaPath);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'schema_') . '.json';
        $generator = 'npx ts-json-schema-generator';
        $command = sprintf(
            'cd %s && %s --path src/types/schedule.ts --type ScheduleData --expose all > %s 2>/dev/null',
            escapeshellarg($this->frontendDir),
            $generator,
            escapeshellarg($tempPath)
        );

        $exitCode = null;
        $output = null;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            unlink($tempPath);
            $this->fail('Schema generator command failed (exit code: ' . $exitCode . ')');
        }

        $generated = file_get_contents($tempPath);
        unlink($tempPath);

        if ($generated === false) {
            $this->fail('Failed to read generated schema');
        }

        $this->assertEquals(
            $committed,
            $generated,
            'JSON Schema is out of date. Run `composer generate:schema` to regenerate it from the TypeScript types.'
        );
    }
}
