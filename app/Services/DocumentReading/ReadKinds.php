<?php

namespace App\Services\DocumentReading;

use App\Services\DocumentReading\Contracts\ReadKind;
use LogicException;

/**
 * Every kind of document a reader can read, collected from the "read_kinds" lists in the module
 * config files. A module that is not deployed registers nothing, so its kind does not exist.
 */
class ReadKinds
{
    /** @var array<string, ReadKind>|null */
    private ?array $kinds = null;

    /** @return array<string, ReadKind> By key. */
    public function all(): array
    {
        if ($this->kinds !== null) {
            return $this->kinds;
        }

        $kinds = [];

        foreach (config('modules', []) as $module) {
            foreach ($module['read_kinds'] ?? [] as $class) {
                $kind = app($class);

                if (! $kind instanceof ReadKind) {
                    throw new LogicException("{$class} is listed as a reader kind but does not implement ReadKind.");
                }

                $key = $kind->key();

                if (isset($kinds[$key]) && $kind::class !== $kinds[$key]::class) {
                    throw new LogicException("Two reader kinds are registered as \"{$key}\".");
                }

                foreach ($kind->passes() as $pass) {
                    if (! is_array(config("modules.document_reading.kinds.{$key}.passes.{$pass}"))) {
                        throw new LogicException("The reader kind \"{$key}\" has no figures for its pass \"{$pass}\" in config/modules/document_reading.php.");
                    }
                }

                $kinds[$key] = $kind;
            }
        }

        return $this->kinds = $kinds;
    }

    public function find(?string $key): ?ReadKind
    {
        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    public function get(string $key): ReadKind
    {
        return $this->find($key) ?? throw new LogicException("No reader is registered for \"{$key}\".");
    }

    /** Whether any reader is deployed at all. Screens that only make sense with a reader ask this. */
    public function any(): bool
    {
        return $this->all() !== [];
    }
}
