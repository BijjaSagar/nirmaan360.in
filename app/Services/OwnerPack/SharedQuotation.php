<?php

namespace App\Services\OwnerPack;

use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationItem;
use App\Services\Wording\Rendered;
use Illuminate\Support\Collection;

/** Everything the shared quotation page may show, and nothing else: one quotation of one project of one company. */
final readonly class SharedQuotation
{
    /**
     * @param  Collection<int, ClientQuotationItem>  $items
     * @param  Collection<int, ClientPaymentStage>  $stages  Empty when the revision was replaced: its schedule is no longer shown.
     * @param  ?int  $replacedBy  The number of a later issued revision.
     */
    public function __construct(
        public ClientQuotation $quotation,
        public string $company,
        public string $project,
        public Collection $items,
        public Collection $stages,
        public Rendered $document,
        public ?int $replacedBy,
    ) {}
}
