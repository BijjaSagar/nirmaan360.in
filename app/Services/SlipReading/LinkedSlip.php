<?php

namespace App\Services\SlipReading;

use App\Models\SlipRead;

/**
 * A photo reading that passed SlipLink::check for one save: the field names a person confirmed
 * against the photo, those they typed over, and the warnings they ticked. Names only, never values.
 */
final readonly class LinkedSlip
{
    /**
     * @param  list<string>  $confirmed
     * @param  list<string>  $typedOver
     * @param  list<string>  $ticked
     */
    public function __construct(
        public SlipRead $slip,
        public string $context,
        public array $confirmed,
        public array $typedOver,
        public array $ticked,
    ) {}
}
