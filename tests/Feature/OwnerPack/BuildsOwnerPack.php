<?php

namespace Tests\Feature\OwnerPack;

use App\Models\BoqItem;
use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\OwnerPack\QuotationDocument;
use App\Services\OwnerPack\QuotationService;
use App\Services\OwnerPack\QuotationShareService;
use App\Services\OwnerPack\ShownText;
use Illuminate\Support\Str;
use Tests\BuildsTenant;

/**
 * An invented house contractor with one house project and three BOQ lines, and the short steps the
 * quotation tests repeat: prepare a draft, add stages, tick everything, issue, share.
 *
 * The figures are made up. The GST percentage is a typed test value and stands for no real rate.
 */
trait BuildsOwnerPack
{
    use BuildsTenant;

    protected Organization $organization;

    protected User $owner;

    protected User $manager;

    protected Project $project;

    /** @var list<BoqItem> */
    protected array $lines = [];

    /** A percentage a test types for a quotation. Invented; no tax rate is meant. */
    protected const TYPED_GST = '7.5';

    protected function house(string $plan = 'contractor'): void
    {
        $this->organization = $this->tenant('Sahyadri Homes', $plan);
        $this->owner = $this->member($this->organization, 'owner');
        $this->manager = $this->member($this->organization, 'pm');
        $this->project = $this->makeProject($this->organization, ['name' => 'Deshmukh Bungalow']);

        // 12.500 x 4,200.10 = 52,501.25; 80 x 950 = 76,000.00; 1 x 18,333.33 = 18,333.33. Together 1,46,834.58.
        $this->lines = [
            $this->line($this->project, 'A-01', 'Excavation for foundation in all soils', 'cum', '12.500', '4200.10', 1),
            $this->line($this->project, 'B-04', 'Brick masonry in cement mortar', 'sqm', '80.000', '950.00', 2),
            $this->line($this->project, 'C-09', 'Site clearance and levelling', 'LS', '1.000', '18333.33', 3),
        ];
    }

    protected function line(Project $project, string $code, string $description, string $unit, string $qty, string $rate, int $sort = 0): BoqItem
    {
        return BoqItem::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'code' => $code,
            'description' => $description,
            'unit' => $unit,
            'qty' => $qty,
            'rate' => $rate,
            'sort' => $sort,
        ]);
    }

    protected function quotations(): QuotationService
    {
        return app(QuotationService::class);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function draft(array $overrides = []): ClientQuotation
    {
        return $this->quotations()->create($this->project, [
            'client_name' => 'Anil Deshmukh',
            'language' => 'en',
            'gst_pct' => self::TYPED_GST,
            'valid_until' => today()->addDays(20)->toDateString(),
            'boq_item_ids' => array_map(fn (BoqItem $line) => $line->id, $this->lines),
            ...$overrides,
        ], $this->manager);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function stage(ClientQuotation $quotation, string $title, ?string $percent, ?string $amount = null, array $overrides = []): ClientPaymentStage
    {
        return $this->quotations()->addStage($quotation, [
            'title' => $title,
            'basis' => $percent !== null ? ClientPaymentStage::PERCENT : ClientPaymentStage::AMOUNT,
            'percent' => $percent,
            'amount' => $amount,
            'client_uuid' => (string) Str::uuid(),
            ...$overrides,
        ], $this->manager);
    }

    /** @return array{fact_fingerprint: string, text_fingerprint: string, number_ticks: list<string>} What a person sends after ticking every figure on the page. */
    protected function allTicks(ClientQuotation $quotation): array
    {
        $quotation = $quotation->fresh();
        [$sheet, $note] = $this->quotations()->review($quotation);

        return ['fact_fingerprint' => $note->fingerprint, 'text_fingerprint' => ShownText::fingerprint($note), 'number_ticks' => app(QuotationDocument::class)->ticksNeeded($note, $sheet)];
    }

    protected function issued(?ClientQuotation $quotation = null): ClientQuotation
    {
        $quotation ??= $this->withStages($this->draft());

        return $this->quotations()->issue($quotation, $this->allTicks($quotation), $this->manager)->fresh();
    }

    /** Three percentage stages that add up to the whole. */
    protected function withStages(ClientQuotation $quotation): ClientQuotation
    {
        $this->stage($quotation, 'On agreement', '30');
        $this->stage($quotation, 'On completion of plinth', '33.3333', null, ['condition_text' => 'Plinth beam cast and cured']);
        $this->stage($quotation, 'On handing over', '36.6667');

        return $quotation->fresh();
    }

    /** @return array{0: ClientQuotation, 1: string} An issued quotation and the address of its open link. */
    protected function shared(?ClientQuotation $quotation = null): array
    {
        $quotation = app(QuotationShareService::class)->share($quotation ?? $this->issued(), $this->manager)->fresh();

        return [$quotation, app(QuotationShareService::class)->url($quotation)];
    }

    protected function signOut(): void
    {
        auth()->forgetUser();
        $this->app['auth']->forgetGuards();
    }
}
