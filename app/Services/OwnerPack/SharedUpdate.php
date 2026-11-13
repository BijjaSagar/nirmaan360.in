<?php

namespace App\Services\OwnerPack;

use App\Models\OwnerUpdate;

/** Everything the shared update page may show, and nothing else: one approved update of one project of one company. */
final readonly class SharedUpdate
{
    /**
     * @param  list<array{id: string, text: string}>  $body  The text as it was approved, slot by slot. Without the payment position once its quotation was replaced.
     * @param  list<array{n: int, name: string, qty: ?string, unit: ?string}>  $work  The rows of the work table.
     * @param  list<array{n: int, id: int, date: string, caption: ?string}>  $photos  The photos that can still be shown.
     * @param  array<int, string>  $paths  Photo id => where its file is stored. For the photo route only; never printed.
     * @param  ?int  $replacedBy  The number of a later issued revision of the quotation the payment position was stated on.
     */
    public function __construct(
        public OwnerUpdate $update,
        public string $company,
        public string $project,
        public array $body,
        public array $work,
        public array $photos,
        public array $paths,
        public ?int $replacedBy,
    ) {}

    /** @return list<array{id: string, text: string}> The slots of the text whose id is one of these. */
    public function slots(string ...$ids): array
    {
        return array_values(array_filter($this->body, fn (array $slot) => in_array($slot['id'], $ids, true)));
    }

    /** @return list<array{id: string, text: string}> The payment position, when it is shown. */
    public function moneySlots(): array
    {
        return array_values(array_filter($this->body, fn (array $slot) => OwnerUpdateDocument::isMoneySlot($slot['id'])));
    }
}
