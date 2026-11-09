<?php

namespace App\Services\Agents;

/** Checks decoded JSON against the part of JSON Schema that the agent schemas use. */
final class SchemaValidator
{
    /** @return list<string> One message per problem. Empty means valid. */
    public function errors(mixed $value, array $schema, string $path = '$'): array
    {
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $option) {
                if ($this->errors($value, $option, $path) === []) {
                    return [];
                }
            }

            return ["{$path} does not match any allowed form."];
        }

        if (isset($schema['type']) && ! $this->matchesType($value, (array) $schema['type'])) {
            return ["{$path} must be ".implode(' or ', (array) $schema['type']).'.'];
        }

        $errors = [];

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path} must be one of: ".implode(', ', $schema['enum']).'.';
        }

        if (is_string($value)) {
            $errors = [...$errors, ...$this->stringErrors($value, $schema, $path)];
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path} must be at least {$schema['minimum']}.";
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path} must be at most {$schema['maximum']}.";
            }
        }

        if (is_array($value)) {
            $errors = [...$errors, ...$this->arrayErrors($value, $schema, $path)];
        }

        return $errors;
    }

    /** @return list<string> */
    private function stringErrors(string $value, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            $errors[] = "{$path} must be at most {$schema['maxLength']} characters.";
        }

        if (isset($schema['pattern']) && preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/u', $value) !== 1) {
            $errors[] = "{$path} has the wrong format.";
        }

        if (($schema['format'] ?? null) === 'date' && ! $this->isDate($value)) {
            $errors[] = "{$path} must be a date like 2026-11-09.";
        }

        return $errors;
    }

    /** @return list<string> */
    private function arrayErrors(array $value, array $schema, string $path): array
    {
        $errors = [];

        foreach ($schema['required'] ?? [] as $name) {
            if (! array_key_exists($name, $value)) {
                $errors[] = "{$path}.{$name} is required.";
            }
        }

        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (array_key_exists($name, $value)) {
                $errors = [...$errors, ...$this->errors($value[$name], $property, "{$path}.{$name}")];
            }
        }

        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys($value) as $name) {
                if (! isset($schema['properties'][$name])) {
                    $errors[] = "{$path}.{$name} is not allowed.";
                }
            }
        }

        if (isset($schema['items'])) {
            foreach (array_values($value) as $index => $item) {
                $errors = [...$errors, ...$this->errors($item, $schema['items'], "{$path}[{$index}]")];
            }
        }

        if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
            $errors[] = "{$path} can have at most {$schema['maxItems']} items.";
        }

        return $errors;
    }

    private function matchesType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                // A decoded empty JSON object and an empty list are both [].
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
