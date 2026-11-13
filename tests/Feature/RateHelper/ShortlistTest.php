<?php

namespace Tests\Feature\RateHelper;

use App\Models\BoqItem;
use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRun;
use App\Services\RateHelper\RateSources;
use App\Services\RateHelper\Shortlist;
use App\Services\RateHelper\Sources\OwnBoqSource;
use App\Services\RateHelper\Sources\OwnTenderSource;
use App\Services\RateHelper\Sources\RateLibrarySource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/** Which rows are offered for a BOQ line, and what is kept of them. */
class ShortlistTest extends RateHelperTestCase
{
    /** @return list<array{0: string, 1: int}> Source and row of each stored candidate, in order. */
    private function listed(RateSuggestionRun $run, BoqItem $item): array
    {
        return array_map(fn (array $candidate) => [$candidate['source'], (int) $candidate['row_id']], $this->shortlist($run, $item));
    }

    public function test_a_run_lists_rates_from_the_library_other_projects_and_past_tenders_best_first(): void
    {
        Http::fake();

        $line = $this->line('Providing and laying RCC M25 in slab');
        $library = $this->libraryRate('RCC M25 slab', 'cum', '6200.00');
        $boq = $this->line('RCC M25 in slab, beams and columns', 'cum', '5800.00', $this->other);
        $tender = $this->tenderLine('RCC M25 slab with shuttering', 'cum', '7100.00');
        $this->libraryRate('Brick masonry in superstructure', 'cum', '4100.00');

        $run = $this->startRun($this->qs, $line);

        $this->assertSame([
            [RateLibrarySource::KEY, $library->id],
            [OwnTenderSource::KEY, $tender->id],
            [OwnBoqSource::KEY, $boq->id],
        ], $this->listed($run, $line));
        $this->assertSame(['C1', 'C2', 'C3'], array_column($this->shortlist($run, $line), 'ref'));
        $this->assertSame([100, 85, 75], array_column($this->shortlist($run, $line), 'score'));

        $this->assertSame($this->project->id, $run->project_id);
        $this->assertSame($this->qs->id, $run->user_id);
        $this->assertSame(RateSuggestionRun::OPEN, $run->status);
        $this->assertSame($this->org->id, $this->lineOf($run, $line)->organization_id);
        $this->assertSame($this->project->id, $this->lineOf($run, $line)->project_id);

        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSeeInOrder(['RCC M25 slab', '6,200.00', 'RCC M25 slab with shuttering', '7,100.00', 'RCC M25 in slab, beams and columns', '5,800.00'])
            ->assertSee('Ward office building')
            ->assertSee('GP2 · Maplewood Plaza')
            ->assertDontSee('Brick masonry in superstructure');

        // The whole of this is plain code on the company's own records.
        Http::assertNothingSent();
    }

    public function test_what_is_stored_holds_no_rate_no_description_and_no_name(): void
    {
        $line = $this->line('Providing and laying RCC M25 in slab');
        $this->libraryRate('RCC M25 slab', 'cum', '6234.50');
        $this->line('RCC M25 in slab and beams', 'cum', '5817.25', $this->other);
        $this->tenderLine('RCC M25 slab with shuttering', 'cum', '7149.75');

        $run = $this->startRun($this->owner, $line);
        $stored = RateSuggestionLine::withoutGlobalScopes()->where('run_id', $run->id)->firstOrFail();

        foreach ($stored->candidates as $candidate) {
            $this->assertSame(['ref', 'source', 'row_id', 'score', 'same_work', 'note'], array_keys($candidate));
            $this->assertNull($candidate['same_work']);
            $this->assertNull($candidate['note']);
        }

        $raw = (string) $stored->getRawOriginal('candidates');

        foreach (['6234', '5817', '7149', 'Maplewood', 'GP2', 'Ward office', 'District', 'RCC', 'slab', 'shuttering'] as $word) {
            $this->assertStringNotContainsString($word, $raw);
        }

        // Nothing is accepted by starting a run.
        $this->assertNull($stored->accepted_rate);
        $this->assertSame('0.00', $line->fresh()->rate);
    }

    public function test_a_row_in_another_kind_of_unit_is_never_listed(): void
    {
        $line = $this->line('Cement plaster 12 mm thick in CM 1:4', 'sqm');
        $this->libraryRate('Cement plaster 12 mm thick in CM 1:4', 'cum', '4100.00');
        $this->libraryRate('Cement plaster 12 mm thick in CM 1:4', 'sqft', '32.00');
        $this->line('Cement plaster 12 mm thick in CM 1:4', 'rmt', '90.00', $this->other);
        $this->tenderLine('Cement plaster 12 mm thick in CM 1:4', 'nos', '75.00');
        $same = $this->libraryRate('Cement plaster 12 mm thick in CM 1:4', 'Sq.M', '345.00');
        $alsoSame = $this->line('Cement plaster 12 mm thick in CM 1:4', 'm2', '330.00', $this->other);

        $run = $this->startRun($this->owner, $line);

        $this->assertSame([[RateLibrarySource::KEY, $same->id], [OwnBoqSource::KEY, $alsoSame->id]], $this->listed($run, $line));

        $this->actingAs($this->owner)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('345.00')->assertSee('330.00')
            ->assertDontSee('4,100.00')->assertDontSee('32.00')->assertDontSee('90.00')->assertDontSee('75.00');
    }

    public function test_units_that_are_not_a_dimension_must_be_the_same_text(): void
    {
        $line = $this->line('TMT reinforcement steel Fe500', 'kg');
        $perTonne = $this->libraryRate('TMT reinforcement steel Fe500', 'MT', '62000.00');
        $perKg = $this->libraryRate('TMT reinforcement steel Fe500', 'Kg.', '62.00');

        $run = $this->startRun($this->owner, $line);

        $this->assertSame([[RateLibrarySource::KEY, $perKg->id]], $this->listed($run, $line));
        $this->assertNull($this->refOf($run, $line, RateLibrarySource::KEY, $perTonne->id));
    }

    public function test_a_lump_sum_line_gets_no_candidates_and_a_lump_sum_row_is_never_one(): void
    {
        $lumpSum = $this->line('Site clearance and levelling', 'LS');
        $job = $this->line('Site clearance and levelling', 'Job');
        $measured = $this->line('Site clearance and levelling', 'sqm');

        // Rows priced as a lump sum, in every source.
        $this->libraryRate('Site clearance and levelling', 'L.S.', '250000.00');
        $this->libraryRate('Site clearance and levelling', 'lump sum', '260000.00');
        $this->line('Site clearance and levelling', 'LS', '270000.00', $this->other);
        $this->tenderLine('Site clearance and levelling', 'job', '280000.00');
        $perSqm = $this->libraryRate('Site clearance and levelling', 'sqm', '18.00');

        $run = $this->startRun($this->owner, $lumpSum, $job, $measured);

        $this->assertSame([], $this->shortlist($run, $lumpSum));
        $this->assertSame([], $this->shortlist($run, $job));
        $this->assertSame([[RateLibrarySource::KEY, $perSqm->id]], $this->listed($run, $measured));

        $this->actingAs($this->owner)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('This is a lump-sum line.')
            ->assertSee('18.00')
            ->assertDontSee('2,50,000.00')->assertDontSee('2,60,000.00')->assertDontSee('2,70,000.00')->assertDontSee('2,80,000.00');
    }

    public function test_rows_with_no_rate_removed_rows_and_the_projects_own_lines_are_left_out(): void
    {
        $line = $this->line('Vitrified tile flooring 600 x 600 mm');

        $this->libraryRate('Vitrified tile flooring 600 x 600 mm', 'cum', '0.00');
        $this->libraryRate('Vitrified tile flooring 600 x 600 mm', 'cum', '980.00')->delete();
        // Another line of the same BOQ is not a second opinion.
        $this->line('Vitrified tile flooring 600 x 600 mm', 'cum', '900.00');
        $this->line('Vitrified tile flooring 600 x 600 mm', 'cum', '0.00', $this->other);
        $this->line('Vitrified tile flooring 600 x 600 mm', 'cum', '910.00', $this->other)->delete();
        // A project that was removed takes its BOQ with it.
        $gone = $this->makeProject($this->org, ['code' => 'OLD', 'name' => 'Old Mill Road']);
        $this->line('Vitrified tile flooring 600 x 600 mm', 'cum', '920.00', $gone);
        $gone->delete();
        $kept = $this->line('Vitrified tile flooring 600 x 600 mm', 'cum', '930.00', $this->other);

        $run = $this->startRun($this->owner, $line);

        $this->assertSame([[OwnBoqSource::KEY, $kept->id]], $this->listed($run, $line));
    }

    public function test_only_tenders_that_were_priced_and_sent_are_listed(): void
    {
        $line = $this->line('Granite kitchen platform 18 mm thick', 'sqm');

        $this->tenderLine('Granite kitchen platform 18 mm thick', 'sqm', '3100.00', 'enquiry');
        $this->tenderLine('Granite kitchen platform 18 mm thick', 'sqm', '3200.00', 'bidding');
        $this->tenderLine('Granite kitchen platform 18 mm thick', 'sqm', '3300.00', 'no_bid');
        $submitted = $this->tenderLine('Granite kitchen platform 18 mm thick', 'sqm', '3400.00', 'submitted');
        $won = $this->tenderLine('Granite kitchen platform 18 mm thick', 'sqm', '3500.00', 'won', ['project_id' => $this->other->id]);
        $lost = $this->tenderLine('Granite kitchen platform 18 mm thick', 'sqm', '3600.00', 'lost');
        // The tender this very project came from holds the lines its BOQ started with.
        $this->tenderLine('Granite kitchen platform 18 mm thick', 'sqm', '3700.00', 'won', ['project_id' => $this->project->id]);
        $this->tenderLine('Granite kitchen platform 18 mm thick', 'sqm', '3800.00', 'won')->tender->delete();

        $run = $this->startRun($this->owner, $line);

        $this->assertEqualsCanonicalizing(
            [[OwnTenderSource::KEY, $submitted->id], [OwnTenderSource::KEY, $won->id], [OwnTenderSource::KEY, $lost->id]],
            $this->listed($run, $line),
        );
    }

    public function test_another_companys_records_are_never_listed(): void
    {
        [$rivalOrg, , $rivalProject] = $this->rival();
        $rivalLibrary = $this->libraryRate('RCC M25 in slab', 'cum', '9911.00', $rivalOrg);
        $rivalBoq = $this->line('RCC M25 in slab', 'cum', '9922.00', $rivalProject);
        $rivalTender = $this->tenderLine('RCC M25 in slab', 'cum', '9933.00', 'won', [], $rivalOrg);

        $line = $this->line('RCC M25 in slab');
        $own = $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');

        $run = $this->startRun($this->owner, $line);

        $this->assertSame([[RateLibrarySource::KEY, $own->id]], $this->listed($run, $line));
        $this->assertNull($this->refOf($run, $line, RateLibrarySource::KEY, $rivalLibrary->id));
        $this->assertNull($this->refOf($run, $line, OwnBoqSource::KEY, $rivalBoq->id));
        $this->assertNull($this->refOf($run, $line, OwnTenderSource::KEY, $rivalTender->id));

        $this->actingAs($this->owner)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('6,200.00')
            ->assertDontSee('9,911.00')->assertDontSee('9,922.00')->assertDontSee('9,933.00')
            ->assertDontSee('Rival');
    }

    public function test_only_the_best_few_are_kept_for_a_line(): void
    {
        $line = $this->line('Anti termite treatment to foundation', 'sqm');

        foreach (range(1, 9) as $number) {
            $this->libraryRate('Anti termite treatment to foundation, make '.$number, 'sqm', (string) (40 + $number).'.00');
        }

        $exact = $this->libraryRate('Anti termite treatment to foundation', 'sqm', '55.00');

        $run = $this->startRun($this->owner, $line);

        $this->assertCount(5, $this->shortlist($run, $line));
        $this->assertSame([RateLibrarySource::KEY, $exact->id], $this->listed($run, $line)[0]);

        config(['modules.rate_helper.shortlist_size' => 2]);
        $this->assertCount(2, $this->shortlist($this->startRun($this->owner, $line), $line));
    }

    public function test_a_line_with_nothing_alike_says_so(): void
    {
        $line = $this->line('Kota stone flooring 25 mm thick', 'sqm');
        $this->libraryRate('Mild steel grill', 'sqm', '2100.00');

        $run = $this->startRun($this->owner, $line);

        $this->assertSame([], $this->shortlist($run, $line));
        $this->actingAs($this->owner)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Nothing alike enough was found in your records for this line, in the same unit.')
            ->assertDontSee('2,100.00')
            ->assertDontSee('Accept this rate');
    }

    public function test_a_source_answers_only_for_the_person_who_is_signed_in(): void
    {
        $line = $this->line('RCC M25 in slab');
        $library = $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $boq = $this->line('RCC M25 in slab', 'cum', '5800.00', $this->other);

        // Nobody signed in, as on the queue or in the console: the tenant scopes would not apply, so nothing is read.
        Auth::logout();
        $sources = app(RateSources::class)->open();
        $this->assertSame([], app(Shortlist::class)->for($sources, $this->owner, $line));
        $this->assertNull($sources[RateLibrarySource::KEY]->rateOf($this->owner, $library->id));
        $this->assertNull($sources[OwnBoqSource::KEY]->rateOf($this->owner, $boq->id));

        // Signed in as one person and asked on behalf of another: the scopes would be the wrong person's.
        $this->actingAs($this->engineer);
        $sources = app(RateSources::class)->open();
        $this->assertSame([], app(Shortlist::class)->for($sources, $this->owner, $line));
        $this->assertNull($sources[RateLibrarySource::KEY]->rateOf($this->owner, $library->id));

        // A person who may not see money gets no rate from any source, even when asked directly.
        $this->assertSame([], app(Shortlist::class)->for($sources, $this->engineer, $line));
        $this->assertNull($sources[RateLibrarySource::KEY]->rateOf($this->engineer, $library->id));
        $this->assertNull($sources[OwnBoqSource::KEY]->rateOf($this->engineer, $boq->id));

        $this->actingAs($this->owner);
        $sources = app(RateSources::class)->open();
        $this->assertCount(2, app(Shortlist::class)->for($sources, $this->owner, $line));
        $this->assertSame('6200.00', $sources[RateLibrarySource::KEY]->rateOf($this->owner, $library->id)->rate);
    }
}
