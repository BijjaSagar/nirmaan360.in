<?php

namespace App\Services\DocumentReading;

use App\Services\DocumentReading\Contracts\TextCallHandler;
use LogicException;

/**
 * Every handler of a text call, collected from the "text_calls" lists in the module config files.
 * A module that is not deployed registers nothing, so its calls cannot be made.
 *
 * A purpose is listed with one class, which then handles every call of that purpose, or with a
 * map from the morph name of the record a call is about to a class, when several features share
 * the purpose. A class for the record wins over a class for the whole purpose.
 */
class TextCallHandlers
{
    /** How a class for a whole purpose is kept beside the classes for single records. */
    private const ANY = '*';

    /** @var array<string, array<string, class-string<TextCallHandler>>>|null */
    private ?array $classes = null;

    /** @return array<string, array<string, class-string<TextCallHandler>>> Purpose => record ("*" for any) => class. */
    public function all(): array
    {
        if ($this->classes !== null) {
            return $this->classes;
        }

        $classes = [];

        foreach (config('modules', []) as $module) {
            foreach ($module['text_calls'] ?? [] as $purpose => $listed) {
                if (! is_string($purpose) || ! is_array(config("modules.document_reading.purposes.{$purpose}"))) {
                    throw new LogicException("The text call purpose \"{$purpose}\" has no figures in config/modules/document_reading.php.");
                }

                foreach (is_array($listed) ? $listed : [self::ANY => $listed] as $subject => $class) {
                    if (! is_string($class) || ! is_subclass_of($class, TextCallHandler::class)) {
                        throw new LogicException((is_string($class) ? $class : 'An entry')." is listed under text_calls for \"{$purpose}\" but does not implement TextCallHandler.");
                    }

                    $subject = (string) $subject;

                    if (isset($classes[$purpose][$subject]) && $classes[$purpose][$subject] !== $class) {
                        throw new LogicException("Two text call handlers are registered for \"{$purpose}\"".($subject === self::ANY ? '.' : " and {$subject}."));
                    }

                    $classes[$purpose][$subject] = $class;
                }
            }
        }

        return $this->classes = $classes;
    }

    /**
     * A new handler each time it is asked for, so whatever a handler remembers about one call never
     * reaches another.
     *
     * @param  ?string  $subjectType  The morph name of the record the call is about, as ai_text_calls.subject_type holds it.
     */
    public function find(?string $purpose, ?string $subjectType = null): ?TextCallHandler
    {
        $listed = $purpose === null ? [] : ($this->all()[$purpose] ?? []);
        $class = ($subjectType === null ? null : ($listed[$subjectType] ?? null)) ?? $listed[self::ANY] ?? null;

        return $class === null ? null : app($class);
    }

    public function get(string $purpose, ?string $subjectType = null): TextCallHandler
    {
        return $this->find($purpose, $subjectType)
            ?? throw new LogicException("No text call handler is registered for \"{$purpose}\"".($subjectType === null ? '.' : " and {$subjectType}."));
    }

    /** @return list<string> The purposes some deployed module can make a call for. */
    public function purposes(): array
    {
        return array_keys($this->all());
    }
}
