<?php

namespace App\Services\Reports;

/** What an Excel BOQ import found, and what it did. Nothing is written while there are errors. */
final class BoqImportResult
{
    /**
     * @param  list<array{row:int,field:?string,message:string}>  $errors  Row 0 is a problem with the file as a whole.
     */
    public function __construct(
        public readonly array $errors = [],
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $unchanged = 0,
    ) {}

    public function ok(): bool
    {
        return $this->errors === [];
    }

    public function rows(): int
    {
        return $this->created + $this->updated + $this->unchanged;
    }
}
