<?php

namespace App\Services\DocumentReading;

/**
 * Checks decoded reader output against the small part of JSON Schema the reader schemas use:
 * objects with exactly their listed keys, lists, strings, strings from a fixed set, whole numbers.
 *
 * The provider already holds output to the schema while it is written. This is the server's own
 * check on top, because a reply can be cut short or come from a fallback, and nothing is staged
 * from output the code has not looked at itself.
 */
final class ShapeCheck
{
    /** Enough to say what went wrong. A reply that is wrong everywhere would otherwise list every cell. */
    private const MOST_PROBLEMS = 20;

    private const KEYWORDS = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'description'];

    /**
     * One line per problem, at most twenty, naming the place and never the value found there, so a
     * line can be logged without copying anything from the page. Empty means the value has the shape.
     *
     * @return list<string>
     */
    public static function errors(mixed $value, array $schema): array
    {
        $problems = [];
        self::check($value, $schema, '$', $problems);

        return array_slice($problems, 0, self::MOST_PROBLEMS);
    }

    public static function passes(mixed $value, array $schema): bool
    {
        return self::errors($value, $schema) === [];
    }

    /**
     * The value with every fixed-set string in lower case, the way the schema spells it. The set is
     * compared without regard to case, so "Read" passes and comes out as "read". Call it on a value
     * that passed.
     */
    public static function lowered(mixed $value, array $schema): mixed
    {
        $type = $schema['type'] ?? null;

        if ($type === 'string') {
            return is_string($value) && isset($schema['enum']) ? strtolower($value) : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if ($type === 'array') {
            return array_map(fn ($item) => self::lowered($item, (array) ($schema['items'] ?? [])), $value);
        }

        if ($type === 'object') {
            foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
                if (array_key_exists($name, $value)) {
                    $value[$name] = self::lowered($value[$name], (array) $property);
                }
            }
        }

        return $value;
    }

    /**
     * Ways a schema breaks the rules every reader schema follows: every property required, nothing
     * optional, no extra keys allowed, only the four types above, fixed sets in lower-case ASCII.
     * For each reader's own test of its schema.
     *
     * @return list<string>
     */
    public static function schemaProblems(array $schema, string $path = '$'): array
    {
        $problems = [];

        foreach (array_diff(array_keys($schema), self::KEYWORDS) as $keyword) {
            $problems[] = "{$path} uses \"{$keyword}\", which the reader schemas do not use.";
        }

        $type = $schema['type'] ?? null;

        if ($type !== 'string' && isset($schema['enum'])) {
            $problems[] = "{$path} has a fixed set on something that is not a string.";
        }

        if ($type === 'object') {
            $properties = $schema['properties'] ?? null;

            if (! is_array($properties) || $properties === [] || array_is_list($properties)) {
                return [...$problems, "{$path} must list its properties."];
            }

            $required = $schema['required'] ?? null;

            if (! is_array($required) || array_diff(array_keys($properties), $required) !== [] || array_diff($required, array_keys($properties)) !== []) {
                $problems[] = "{$path} must require every property it lists.";
            }

            if (($schema['additionalProperties'] ?? null) !== false) {
                $problems[] = "{$path} must set additionalProperties to false.";
            }

            foreach ($properties as $name => $property) {
                $problems = [...$problems, ...(is_array($property) ? self::schemaProblems($property, "{$path}.{$name}") : ["{$path}.{$name} is not a schema."])];
            }

            return $problems;
        }

        if ($type === 'array') {
            return is_array($schema['items'] ?? null)
                ? [...$problems, ...self::schemaProblems($schema['items'], "{$path}[]")]
                : [...$problems, "{$path} must say what its items are."];
        }

        if ($type === 'string') {
            foreach ((array) ($schema['enum'] ?? []) as $option) {
                if (! is_string($option) || preg_match('/^[a-z0-9_]+$/D', $option) !== 1) {
                    $problems[] = "{$path} has a fixed-set value that is not lower-case ASCII.";
                }
            }

            if (array_key_exists('enum', $schema) && (! is_array($schema['enum']) || $schema['enum'] === [])) {
                $problems[] = "{$path} has an empty fixed set.";
            }

            return $problems;
        }

        return $type === 'integer' ? $problems : [...$problems, "{$path} has a type the reader schemas do not use."];
    }

    /** @param  list<string>  $problems */
    private static function check(mixed $value, array $schema, string $path, array &$problems): void
    {
        if (count($problems) >= self::MOST_PROBLEMS) {
            return;
        }

        switch ($schema['type'] ?? null) {
            case 'object':
                self::checkObject($value, $schema, $path, $problems);
                break;
            case 'array':
                if (! is_array($value) || ! array_is_list($value)) {
                    $problems[] = "{$path} must be a list.";
                    break;
                }

                foreach ($value as $index => $item) {
                    self::check($item, (array) ($schema['items'] ?? []), "{$path}[{$index}]", $problems);
                }
                break;
            case 'string':
                if (! is_string($value)) {
                    $problems[] = "{$path} must be text.";
                } elseif (isset($schema['enum']) && ! in_array(strtolower($value), $schema['enum'], true)) {
                    $problems[] = "{$path} must be one of: ".implode(', ', $schema['enum']).'.';
                }
                break;
            case 'integer':
                if (! is_int($value)) {
                    $problems[] = "{$path} must be a whole number.";
                }
                break;
            default:
                // A schema this check cannot read must never pass output through unchecked.
                $problems[] = "{$path} has a type this check does not know.";
        }
    }

    /** @param  list<string>  $problems */
    private static function checkObject(mixed $value, array $schema, string $path, array &$problems): void
    {
        $properties = (array) ($schema['properties'] ?? []);

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $problems[] = "{$path} must be an object.";

            return;
        }

        foreach (array_keys($properties) as $name) {
            if (! array_key_exists($name, $value)) {
                $problems[] = "{$path}.{$name} is missing.";
            }
        }

        foreach (array_keys($value) as $name) {
            if (! array_key_exists($name, $properties)) {
                $problems[] = "{$path}.{$name} is not part of the form.";
            }
        }

        foreach ($properties as $name => $property) {
            if (array_key_exists($name, $value)) {
                self::check($value[$name], (array) $property, "{$path}.{$name}", $problems);
            }
        }
    }
}
