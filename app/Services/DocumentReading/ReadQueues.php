<?php

namespace App\Services\DocumentReading;

use App\Support\PlatformSettings;

/**
 * Which queue a reader job is sent to. There are three lanes: "pages" for long reads in the
 * background, "interactive" for a slip, a bill or a text call that someone is waiting for, and
 * "prepare" for taking the text out of a tender PDF.
 *
 * Every lane starts on the queue the worker already listens to. The platform admin can give a
 * lane its own queue, but only after a worker listens to that queue.
 */
class ReadQueues
{
    public const PAGES = 'pages';

    public const INTERACTIVE = 'interactive';

    public const PREPARE = 'prepare';

    public const LANES = [self::PAGES, self::INTERACTIVE, self::PREPARE];

    public function __construct(private PlatformSettings $settings) {}

    /**
     * The queue of a lane, to pass to onQueue(). Null means the connection's own queue, which is
     * what "default" stands for: whatever name the host has given the queue its worker reads.
     */
    public function name(string $lane): ?string
    {
        $lane = in_array($lane, self::LANES, true) ? $lane : self::PAGES;
        $name = $this->usable($this->settings->get("ai_queue_{$lane}")) ?? $this->usable(config("modules.document_reading.queues.{$lane}"));

        return $name === null || $name === 'default' ? null : $name;
    }

    /** The queue for the page jobs of a kind. */
    public function forKind(string $kind): ?string
    {
        return $this->name((string) config("modules.document_reading.kinds.{$kind}.queue", self::PAGES));
    }

    /** A name a person typed is used only when it looks like a queue name. Anything else falls back. */
    private function usable(mixed $name): ?string
    {
        return is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,59}$/', $name) === 1 ? $name : null;
    }
}
