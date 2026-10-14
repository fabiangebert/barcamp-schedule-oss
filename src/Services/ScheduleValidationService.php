<?php

namespace App\Services;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

class ScheduleValidationService
{
    public static function validate(array $data): array
    {
        $schemaPath = dirname(__DIR__, 2) . '/backend/resources/schedule.schema.json';

        if (!is_file($schemaPath)) {
            throw new \RuntimeException('Schedule schema file not found: ' . $schemaPath);
        }

        $schema = json_decode((string)file_get_contents($schemaPath));
        if (!$schema instanceof \stdClass) {
            throw new \RuntimeException('Invalid schedule schema file');
        }

        $validator = new Validator();
        $validator->setMaxErrors(0);
        $result = $validator->validate(
            self::arrayToStdClass($data),
            $schema
        );

        if ($result->isValid()) {
            return ['passed' => true, 'errors' => []];
        }

        return [
            'passed' => false,
            'errors' => self::collectLeafErrors($result->error()),
        ];
    }

    private static function resolvePointer(ValidationError $error): string
    {
        $segments = [];
        $current = $error->data();
        while ($current !== null) {
            $path = $current->path();
            if (!empty($path)) {
                array_unshift($segments, ...$path);
            }
            $current = $current->parent();
        }
        if (empty($segments)) {
            return 'data';
        }
        return 'data.' . implode('.', array_map(function ($s) {
            return is_string($s) ? $s : (string)$s;
        }, $segments));
    }

    private static function collectLeafErrors(ValidationError $error): array
    {
        $subErrors = $error->subErrors();
        $keyword = $error->keyword();

        if (empty($subErrors)) {
            $pointer = self::resolvePointer($error);
            $args = $error->args();
            $message = match ($keyword) {
                'required' => sprintf(
                    'missing required %s',
                    implode(', ', $args['missing'] ?? [])
                ),
                'type' => $error->message(),
                'enum' => 'value is not one of the allowed values',
                default => $error->message(),
            };
            return [sprintf('%s: %s', $pointer, $message)];
        }

        $errors = [];
        foreach ($subErrors as $sub) {
            $errors = array_merge($errors, self::collectLeafErrors($sub));
        }
        return $errors;
    }

    private static function arrayToStdClass(array $data): \stdClass
    {
        $obj = new \stdClass();
        foreach ($data as $key => $value) {
            $obj->$key = self::valueToJsonCompatible($value);
        }
        return $obj;
    }

    private static function valueToJsonCompatible(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map([self::class, 'valueToJsonCompatible'], $value);
            }
            return self::arrayToStdClass($value);
        }
        return $value;
    }
}
