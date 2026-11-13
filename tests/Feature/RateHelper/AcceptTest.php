<?php

namespace Tests\Feature\RateHelper;

use App\Models\AuditLog;
use App\Models\BoqCurrentRate;
use App\Models\BoqRevision;
use App\Models\RateLibraryItem;
use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRun;
use App\Services\RateHelper\Sources\OwnBoqSource;
use App\Services\RateHelper\Sources\RateLibrarySource;
use App\Services\RateHelper\TextGuards;
use Illuminate\Support\Facades\Route;

/** Accepting one rate for one line, and skipping a line. */
class AcceptTest extends RateHelperTestCase
{
    public function test_accepting_sets_the_boq_rate_from_the_source_row_and_keeps_why_in_the_history(): void
    {
        $this->travelTo('2026-11-13 10:00:00');

        $line = $this->line('RCC M25 in slab', 'cum', '5000.00');
        $library = $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $run = $this->startRun($this->qs, $line);
        $ref = $this->refOf($run, $line, RateLibrarySource::KEY, $library->id);

        $this->travelTo('2026-11-20 16:30:00');

        $this->accept($this->qs, $run, $line, $ref)
            ->assertRedirect(route('rate-suggestions.show', $run).'#line-'.$this->lineOf($run, $line)->id)
            ->assertSessionHasNoErrors();

        $this->assertSame('6200.00', $line->fresh()->rate);

        $stored = $this->lineOf($run, $line);
        $this->assertSame([$ref, RateLibrarySource::KEY, '6200.00', RateSuggestionLine::SET_BOQ_RATE, $this->qs->id],
            [$stored->accepted_ref, $stored->accepted_source, $stored->accepted_rate, $stored->accepted_mode, $stored->accepted_by]);
        $this->assertSame('2026-11-20 16:30:00', $stored->accepted_at->format('Y-m-d H:i:s'));
        $this->assertTrue($stored->isAccepted());
        $this->assertSame(RateSuggestionRun::DONE, $run->fresh()->status);

        // The BOQ history says where the rate came from, with the date of that rate.
        $revision = BoqRevision::withoutGlobalScopes()->where('boq_item_id', $line->id)->latest('id')->firstOrFail();
        $this->assertSame('Rate taken from Rate library: RCC M25 in slab (13 Nov 2026)', $revision->reason);
        $this->assertSame(['rate' => ['5000.00', '6200.00']], $revision->changes);
        $this->assertSame($this->qs->id, $revision->user_id);
        $this->assertSame(0, BoqCurrentRate::withoutGlobalScopes()->count());

        // And the audit log holds the acceptance itself.
        $audit = AuditLog::withoutGlobalScopes()->where('auditable_type', (new RateSuggestionLine)->getMorphClass())->where('auditable_id', $stored->id)->where('action', 'updated')->firstOrFail();
        $this->assertSame($this->qs->id, $audit->user_id);
        $this->assertSame($this->org->id, $audit->organization_id);
        $this->assertSame('6200.00', (string) $audit->new_values['accepted_rate']);
        $this->assertSame($ref, $audit->new_values['accepted_ref']);

        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Accepted ₹6,200.00 per cum.')
            ->assertSee('Source: Rate library.')
            ->assertSee('Set as the BOQ rate, and kept in the BOQ history.')
            ->assertDontSee('Accept this rate')
            ->assertDontSee('Skip this line');
    }

    public function test_the_rate_written_is_the_one_in_the_source_row_at_that_moment(): void
    {
        $line = $this->line('RCC M25 in slab');
        $library = $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $run = $this->startRun($this->qs, $line);
        $ref = $this->refOf($run, $line, RateLibrarySource::KEY, $library->id);

        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('6,200.00');

        // The library is edited while the list is open on someone's screen.
        RateLibraryItem::withoutGlobalScopes()->whereKey($library->id)->update(['rate' => '6515.75']);

        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('6,515.75')->assertDontSee('6,200.00');

        // Nothing the browser sends can carry a rate: only the ref is read.
        $this->accept($this->qs, $run, $line, $ref, ['rate' => '1.00', 'accepted_rate' => '1.00'])->assertSessionHasNoErrors();

        $this->assertSame('6515.75', $line->fresh()->rate);
        $this->assertSame('6515.75', $this->lineOf($run, $line)->accepted_rate);

        // A second edit after accepting changes neither the BOQ nor what was accepted.
        RateLibraryItem::withoutGlobalScopes()->whereKey($library->id)->update(['rate' => '7000.00']);
        $this->accept($this->qs, $run, $line, $ref)->assertSessionHasNoErrors();
        $this->assertSame('6515.75', $line->fresh()->rate);
        $this->assertSame('6515.75', $this->lineOf($run, $line)->accepted_rate);
    }

    public function test_a_rate_that_changed_after_the_page_was_opened_is_refused_and_not_written(): void
    {
        $line = $this->line('RCC M25 in slab');
        $library = $this->libraryRate('RCC M25 in slab', 'cum', '5800.00');
        $run = $this->startRun($this->qs, $line);
        $ref = $this->refOf($run, $line, RateLibrarySource::KEY, $library->id);
        $stored = $this->lineOf($run, $line);

        // The QS opens the page at ₹5,800.00.
        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('₹5,800.00');
        $seen = $this->shownOn($this->qs, $run, $stored, $ref);
        $this->assertNotNull($seen);

        // Someone else edits the row before the QS presses Accept on that page.
        RateLibraryItem::withoutGlobalScopes()->whereKey($library->id)->update(['rate' => '58000.00']);

        $changed = 'This rate or its description changed after you opened the page. Check what is shown now before you accept.';
        $this->accept($this->qs, $run, $line, $ref, ['shown' => $seen])
            ->assertRedirect(route('rate-suggestions.show', $run).'#line-'.$stored->id)
            ->assertSessionHasErrors(['candidate' => $changed])
            ->assertSessionMissing('success');

        $this->assertSame('0.00', $line->fresh()->rate);
        $this->assertTrue($this->lineOf($run, $line)->isOpen());
        $this->assertNull($this->lineOf($run, $line)->accepted_rate);
        $this->assertSame(0, BoqRevision::withoutGlobalScopes()->where('boq_item_id', $line->id)->where('action', 'edited')->count());

        // A form with no fingerprint, or one made up, is taken as out of date too. The number sent is never what is compared.
        foreach ([null, '', str_repeat('0', 64), 'rate=58000.00'] as $shown) {
            $this->accept($this->qs, $run, $line, $ref, ['shown' => $shown])->assertSessionHasErrors(['candidate' => $changed]);
        }

        // A change to the wording alone, with the same rate, is a change to what was approved as well.
        $page = $this->shownOn($this->qs, $run, $stored, $ref);
        RateLibraryItem::withoutGlobalScopes()->whereKey($library->id)->update(['item' => 'RCC M25 in slab and beams']);
        $this->accept($this->qs, $run, $line, $ref, ['shown' => $page])->assertSessionHasErrors(['candidate' => $changed]);
        $this->assertTrue($this->lineOf($run, $line)->isOpen());

        // After looking again, the figure now on the page is the one accepted.
        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('₹58,000.00')->assertSee('RCC M25 in slab and beams');
        $this->accept($this->qs, $run, $line, $ref)->assertSessionHasNoErrors()->assertSessionHas('success', 'Rate accepted.');
        $this->assertSame('58000.00', $line->fresh()->rate);
        $this->assertSame(['58000.00', $this->qs->id], [$this->lineOf($run, $line)->accepted_rate, $this->lineOf($run, $line)->accepted_by]);
    }

    public function test_a_refused_press_goes_back_to_its_own_line_and_says_why_there(): void
    {
        $slab = $this->line('RCC M25 in slab');
        $plaster = $this->line('Cement plaster 12 mm thick', 'sqm');
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $library = $this->libraryRate('Cement plaster 12 mm thick', 'sqm', '340.00');
        $run = $this->startRun($this->qs, $slab, $plaster);
        $first = $this->lineOf($run, $slab);
        $second = $this->lineOf($run, $plaster);
        $seen = $this->shownOn($this->qs, $run, $second, 'C1');

        $card = function (string $page, RateSuggestionLine $line): string {
            $this->assertSame(1, preg_match('#<section class="card mb-4" id="line-'.$line->id.'">(.*?)</section>#s', $page, $match));

            return $match[1];
        };

        $gone = 'This rate is no longer available to you. It may have been removed, changed or moved out of your projects.';
        RateLibraryItem::withoutGlobalScopes()->whereKey($library->id)->delete();

        // The page is read straight after the press, as the browser does. (Reading the session in the test
        // first would turn its stored errors back into a bag, and the next request would then lose them.)
        $this->accept($this->qs, $run, $plaster, 'C1', ['shown' => $seen])
            ->assertRedirect(route('rate-suggestions.show', $run).'#line-'.$second->id);

        $page = $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->getContent();
        $this->assertStringContainsString($gone, $card($page, $second), 'The reason is printed inside the line it is about.');
        $this->assertStringNotContainsString($gone, $card($page, $first));

        // Once read, it is not repeated on the next visit.
        $page = $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->getContent();
        $this->assertStringNotContainsString($gone, $page);

        // A request that fails the form rules, and a refused skip, go back to their line too.
        $this->accept($this->qs, $run, $slab, 'C1', ['mode' => 'overwrite_everything'])
            ->assertRedirect(route('rate-suggestions.show', $run).'#line-'.$first->id)
            ->assertSessionHasErrors('mode');
        $this->accept($this->qs, $run, $slab, 'C1')->assertSessionHasNoErrors();
        $this->actingAs($this->qs)->post(route('rate-suggestions.lines.skip', [$run, $first]))
            ->assertRedirect(route('rate-suggestions.show', $run).'#line-'.$first->id);
        $page = $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->getContent();
        $this->assertStringContainsString('A rate was already accepted for this line.', $card($page, $first));
        $this->assertStringNotContainsString('A rate was already accepted for this line.', $card($page, $second));

        // A JSON caller still gets the refusal as an error response.
        $this->actingAs($this->qs)->postJson(route('rate-suggestions.lines.accept', [$run, $first]), ['candidate' => 'C2', 'mode' => 'set_boq_rate'])
            ->assertUnprocessable()->assertJsonValidationErrors(['candidate' => 'A rate was already accepted for this line.']);
    }

    public function test_a_ref_that_is_not_on_the_lines_own_list_is_refused(): void
    {
        $slab = $this->line('RCC M25 in slab');
        $plaster = $this->line('Cement plaster 12 mm thick', 'sqm');
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $this->libraryRate('Cement plaster 12 mm thick', 'sqm', '340.00');
        $this->libraryRate('Cement plaster 12 mm thick with neeru finish', 'sqm', '365.00');

        $run = $this->startRun($this->qs, $slab, $plaster);
        $this->assertSame(['C1'], array_column($this->shortlist($run, $slab), 'ref'));
        $this->assertSame(['C1', 'C2'], array_column($this->shortlist($run, $plaster), 'ref'));

        // C2 is on the other line's list, C9 is on nobody's, and a row id is not a ref.
        foreach (['C2', 'C9', 'c1', '1', 'library:1'] as $ref) {
            $this->accept($this->qs, $run, $slab, $ref)->assertSessionHasErrors(['candidate' => 'This rate is not on the list for this line.']);
        }

        $this->accept($this->qs, $run, $slab, '')->assertSessionHasErrors('candidate');
        $this->accept($this->qs, $run, $slab, str_repeat('C', 13))->assertSessionHasErrors('candidate');
        $this->actingAs($this->qs)->post(route('rate-suggestions.lines.accept', [$run, $this->lineOf($run, $slab)]), ['candidate' => ['C1'], 'mode' => 'set_boq_rate'])->assertSessionHasErrors('candidate');
        $this->accept($this->qs, $run, $slab, 'C1', ['mode' => 'overwrite_everything'])->assertSessionHasErrors('mode');

        $this->assertSame('0.00', $slab->fresh()->rate);
        $this->assertFalse($this->lineOf($run, $slab)->isAccepted());
        $this->assertSame(0, BoqRevision::withoutGlobalScopes()->where('boq_item_id', $slab->id)->where('action', 'edited')->count());
    }

    public function test_a_rate_for_another_grade_mix_or_thickness_needs_the_tick(): void
    {
        $line = $this->line('RCC M25 in slab 150 mm thick');
        $other = $this->libraryRate('RCC M20 in slab 125 mm thick', 'cum', '5800.00');
        $same = $this->libraryRate('RCC slab 150 mm thick in M25', 'cum', '6200.00');

        $run = $this->startRun($this->qs, $line);
        $warned = $this->refOf($run, $line, RateLibrarySource::KEY, $other->id);
        $clean = $this->refOf($run, $line, RateLibrarySource::KEY, $same->id);

        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Concrete grade')->assertSee('this line: M25 · that rate: M20')
            ->assertSee('Thickness or size in mm')->assertSee('this line: 150 · that rate: 125')
            ->assertSee('I checked grade, mix and thickness');

        $message = 'Tick "I checked grade, mix and thickness" before you accept this rate.';
        $this->accept($this->qs, $run, $line, $warned)->assertSessionHasErrors(['checked_warnings' => $message]);
        $this->accept($this->qs, $run, $line, $warned, ['checked_warnings' => '0'])->assertSessionHasErrors(['checked_warnings' => $message]);
        $this->assertSame('0.00', $line->fresh()->rate);
        $this->assertFalse($this->lineOf($run, $line)->isAccepted());

        $this->accept($this->qs, $run, $line, $warned, ['checked_warnings' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('5800.00', $line->fresh()->rate);

        // A rate with nothing to check needs no tick.
        $second = $this->line('RCC M25 in slab 150 mm thick');
        $run = $this->startRun($this->qs, $second);
        $this->accept($this->qs, $run, $second, $this->refOf($run, $second, RateLibrarySource::KEY, $same->id))->assertSessionHasNoErrors();
        $this->assertSame('6200.00', $second->fresh()->rate);
        $this->assertNotNull($clean);
    }

    public function test_a_long_line_description_is_shown_in_full_beside_its_warnings(): void
    {
        // The figures a warning is about often sit at the end of a long BOQ description.
        $text = 'Providing and laying in position machine batched and machine mixed design mix cement concrete for reinforced cement concrete work in slabs, including pumping, '
            .'admixtures, curing and finishing, 150 mm thick, M25 grade, all leads and lifts';
        $this->assertGreaterThan(160, mb_strlen($text));
        $this->assertSame(['150'], TextGuards::read(mb_substr($text, 160))['size']);

        $line = $this->line($text);
        $this->libraryRate('Reinforced cement concrete work in slabs, M25 grade, 125 mm thick', 'cum', '6200.00');
        $run = $this->startRun($this->qs, $line);

        $page = $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Full description of this line')
            ->assertSee($text)
            ->assertSee('this line: 150 · that rate: 125')
            ->assertSee('I checked grade, mix and thickness')
            ->getContent();

        // With something to check, the full text is open; the heading alone is cut short.
        $this->assertMatchesRegularExpression('#<details [^>]*\bopen\b[^>]*>\s*<summary[^>]*>Full description of this line</summary>\s*<p[^>]*>'.preg_quote(e($text), '#').'</p>#', $page);

        // A short description is the heading itself, with nothing more to open.
        $short = $this->line('RCC M25 in slab');
        $this->libraryRate('RCC M25 in slab', 'cum', '6100.00');
        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $this->startRun($this->qs, $short)))->assertOk()
            ->assertSee('RCC M25 in slab')
            ->assertDontSee('Full description of this line');
    }

    public function test_the_tick_is_judged_by_the_descriptions_as_they_are_when_accepting(): void
    {
        $line = $this->line('RCC M25 in slab');
        $library = $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $run = $this->startRun($this->qs, $line);
        $ref = $this->refOf($run, $line, RateLibrarySource::KEY, $library->id);

        // The library item is rewritten for another grade after the list was made.
        RateLibraryItem::withoutGlobalScopes()->whereKey($library->id)->update(['item' => 'RCC M30 in slab']);

        $this->accept($this->qs, $run, $line, $ref)->assertSessionHasErrors('checked_warnings');
        $this->assertSame('0.00', $line->fresh()->rate);
    }

    public function test_a_row_that_has_gone_lost_its_rate_or_changed_its_unit_is_refused(): void
    {
        $gone = 'This rate is no longer available to you. It may have been removed, changed or moved out of your projects.';

        $changes = [
            'removed' => fn (RateLibraryItem $row) => $row->delete(),
            'no rate' => fn (RateLibraryItem $row) => $row->update(['rate' => '0.00']),
            'another unit' => fn (RateLibraryItem $row) => $row->update(['unit' => 'sqm']),
            'a lump sum' => fn (RateLibraryItem $row) => $row->update(['unit' => 'LS']),
        ];

        foreach ($changes as $case => $change) {
            $line = $this->line('Waterproofing to terrace, '.$case);
            $library = $this->libraryRate('Waterproofing to terrace, '.$case, 'cum', '880.00');
            $run = $this->startRun($this->qs, $line);
            $ref = $this->refOf($run, $line, RateLibrarySource::KEY, $library->id);
            $this->assertNotNull($ref, $case);

            $this->actingAs($this->owner);
            $change($library);

            $this->accept($this->qs, $run, $line, $ref)->assertSessionHasErrors(['candidate' => $gone]);
            $this->assertSame('0.00', $line->fresh()->rate, $case);
            $this->assertFalse($this->lineOf($run, $line)->isAccepted(), $case);

            // The list no longer offers it either.
            $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->assertDontSee('880.00')->assertDontSee('Accept this rate');
        }
    }

    public function test_a_boq_line_that_changed_its_unit_or_was_removed_cannot_take_the_rate(): void
    {
        $line = $this->line('Waterproofing to terrace');
        $library = $this->libraryRate('Waterproofing to terrace', 'cum', '880.00');
        $run = $this->startRun($this->qs, $line);
        $ref = $this->refOf($run, $line, RateLibrarySource::KEY, $library->id);

        $line->update(['unit' => 'sqm']);
        $this->accept($this->qs, $run, $line, $ref)->assertSessionHasErrors('candidate');
        $this->assertSame('0.00', $line->fresh()->rate);

        $line->update(['unit' => 'cum']);
        $line->delete();
        $this->accept($this->qs, $run, $line, $ref)->assertSessionHasErrors(['candidate' => 'This BOQ line is no longer in the BOQ.']);
        $this->assertFalse($this->lineOf($run, $line)->isAccepted());
        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('This BOQ line is no longer in the BOQ.')
            ->assertDontSee('880.00');
    }

    public function test_noting_a_current_rate_leaves_the_boq_rate_alone_and_needs_the_budget_permission(): void
    {
        $this->travelTo('2026-11-20 09:00:00');

        $line = $this->line('RCC M25 in slab', 'cum', '5000.00');
        $library = $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $run = $this->startRun($this->qs, $line);
        $ref = $this->refOf($run, $line, RateLibrarySource::KEY, $library->id);
        $note = ['mode' => RateSuggestionLine::NOTE_CURRENT_RATE];

        // The estimator sees rates but not the budget, so the choice is not offered and not taken.
        $this->assertFalse($this->qs->can('money.overview'));
        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->assertDontSee('Note as today');
        $this->accept($this->qs, $run, $line, $ref, $note)->assertForbidden();
        $this->assertSame(0, BoqCurrentRate::withoutGlobalScopes()->count());
        $this->assertFalse($this->lineOf($run, $line)->isAccepted());

        $this->actingAs($this->pm)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('Note as today');
        $this->accept($this->pm, $run, $line, $ref, $note)->assertSessionHasNoErrors();

        $this->assertSame('5000.00', $line->fresh()->rate);
        $this->assertSame(0, BoqRevision::withoutGlobalScopes()->where('boq_item_id', $line->id)->where('action', 'edited')->count());

        $noted = BoqCurrentRate::withoutGlobalScopes()->where('boq_item_id', $line->id)->firstOrFail();
        $this->assertSame(['6200.00', '2026-11-20', $this->pm->id, $this->project->id, $this->org->id],
            [$noted->rate, $noted->noted_on->toDateString(), $noted->noted_by, $noted->project_id, $noted->organization_id]);
        $this->assertSame('Rate library: RCC M25 in slab (20 Nov 2026)', $noted->source);

        $stored = $this->lineOf($run, $line);
        $this->assertSame(['6200.00', RateSuggestionLine::NOTE_CURRENT_RATE, $this->pm->id], [$stored->accepted_rate, $stored->accepted_mode, $stored->accepted_by]);

        $this->actingAs($this->pm)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Noted as the current rate on the budget screen. The BOQ rate was not changed.');

        // A company whose plan has no budget screen cannot note a rate there.
        config(['buildos.plans.business.features' => array_values(array_diff(config('buildos.plans.business.features'), ['site_control']))]);
        $second = $this->line('RCC M25 in slab');
        $run = $this->startRun($this->pm, $second);
        $this->accept($this->pm, $run, $second, 'C1', $note)->assertForbidden();
        $this->assertSame(1, BoqCurrentRate::withoutGlobalScopes()->count());
    }

    public function test_a_long_description_still_fits_the_history_and_the_budget_note(): void
    {
        $text = 'RCC M25 in slab '.str_repeat('with all shuttering centering curing and finishing ', 12);
        $line = $this->line($text);
        $second = $this->line($text);
        // A BOQ description may be far longer than a rate library name.
        $this->line($text, 'cum', '6200.00', $this->other);
        $run = $this->startRun($this->pm, $line, $second);

        $this->accept($this->pm, $run, $line, 'C1')->assertSessionHasNoErrors();
        $this->accept($this->pm, $run, $second, 'C1', ['mode' => RateSuggestionLine::NOTE_CURRENT_RATE])->assertSessionHasNoErrors();

        $this->assertLessThanOrEqual(500, mb_strlen(BoqRevision::withoutGlobalScopes()->where('boq_item_id', $line->id)->latest('id')->firstOrFail()->reason));
        $this->assertLessThanOrEqual(160, mb_strlen(BoqCurrentRate::withoutGlobalScopes()->where('boq_item_id', $second->id)->firstOrFail()->source));
    }

    public function test_accepting_twice_changes_nothing_and_a_second_rate_is_refused(): void
    {
        $line = $this->line('RCC M25 in slab');
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $this->line('RCC M25 in slab', 'cum', '5800.00', $this->other);
        $run = $this->startRun($this->pm, $line);

        $this->accept($this->pm, $run, $line, 'C1')->assertSessionHasNoErrors();
        $revisions = BoqRevision::withoutGlobalScopes()->where('boq_item_id', $line->id)->count();
        $acceptedAt = $this->lineOf($run, $line)->accepted_at;

        // The same press arriving again.
        $this->travel(5)->minutes();
        $this->accept($this->pm, $run, $line, 'C1')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($revisions, BoqRevision::withoutGlobalScopes()->where('boq_item_id', $line->id)->count());
        $this->assertTrue($acceptedAt->equalTo($this->lineOf($run, $line)->accepted_at));

        // Another rate, or another use of the same rate, for a line that already has one.
        $taken = 'A rate was already accepted for this line.';
        $this->accept($this->pm, $run, $line, 'C2')->assertSessionHasErrors(['candidate' => $taken]);
        $this->accept($this->pm, $run, $line, 'C1', ['mode' => RateSuggestionLine::NOTE_CURRENT_RATE])->assertSessionHasErrors(['candidate' => $taken]);
        $this->actingAs($this->pm)->post(route('rate-suggestions.lines.skip', [$run, $this->lineOf($run, $line)]))->assertSessionHasErrors(['candidate' => $taken]);

        $this->assertSame('6200.00', $line->fresh()->rate);
        $this->assertSame(0, BoqCurrentRate::withoutGlobalScopes()->count());
        $this->assertNull($this->lineOf($run, $line)->skipped_at);
    }

    public function test_a_line_can_be_skipped_and_a_run_is_done_when_every_line_is_decided(): void
    {
        $slab = $this->line('RCC M25 in slab');
        $plaster = $this->line('Cement plaster 12 mm thick', 'sqm');
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $this->libraryRate('Cement plaster 12 mm thick', 'sqm', '340.00');
        $run = $this->startRun($this->qs, $slab, $plaster);

        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('2 of 2 lines still to decide');

        $this->actingAs($this->qs)->post(route('rate-suggestions.lines.skip', [$run, $this->lineOf($run, $plaster)]))
            ->assertRedirect(route('rate-suggestions.show', $run).'#line-'.$this->lineOf($run, $plaster)->id)
            ->assertSessionHasNoErrors();

        $skipped = $this->lineOf($run, $plaster);
        $this->assertTrue($skipped->isSkipped());
        $this->assertSame($this->qs->id, $skipped->skipped_by);
        $this->assertSame('0.00', $plaster->fresh()->rate);
        $this->assertSame(RateSuggestionRun::OPEN, $run->fresh()->status);
        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('1 of 2 lines still to decide')->assertSee('Skipped');

        // Skipping again is the same press twice.
        $this->actingAs($this->qs)->post(route('rate-suggestions.lines.skip', [$run, $skipped]))->assertSessionHasNoErrors();
        $this->assertTrue($skipped->skipped_at->equalTo($this->lineOf($run, $plaster)->skipped_at));

        $this->accept($this->qs, $run, $slab, 'C1')->assertSessionHasNoErrors();
        $this->assertSame(RateSuggestionRun::DONE, $run->fresh()->status);

        // A skipped line can still take a rate later.
        $this->accept($this->qs, $run, $plaster, 'C1')->assertSessionHasNoErrors();
        $this->assertSame('340.00', $plaster->fresh()->rate);
        $this->assertTrue($this->lineOf($run, $plaster)->isAccepted());
        $this->assertNull($this->lineOf($run, $plaster)->skipped_at);
        $this->assertSame(RateSuggestionRun::DONE, $run->fresh()->status);
    }

    public function test_a_line_is_reached_only_through_its_own_run(): void
    {
        $slab = $this->line('RCC M25 in slab');
        $plaster = $this->line('Cement plaster 12 mm thick', 'sqm');
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $this->libraryRate('Cement plaster 12 mm thick', 'sqm', '340.00');

        $first = $this->startRun($this->qs, $slab);
        $second = $this->startRun($this->qs, $plaster);
        $foreign = $this->lineOf($second, $plaster);

        $this->actingAs($this->qs)->post(route('rate-suggestions.lines.accept', [$first, $foreign]), ['candidate' => 'C1', 'mode' => 'set_boq_rate'])->assertNotFound();
        $this->actingAs($this->qs)->post(route('rate-suggestions.lines.skip', [$first, $foreign]))->assertNotFound();
        $this->actingAs($this->qs)->post('/rate-suggestions/'.$first->id.'/lines/999999/accept', ['candidate' => 'C1', 'mode' => 'set_boq_rate'])->assertNotFound();

        $this->assertSame('0.00', $plaster->fresh()->rate);
        $this->assertTrue($this->lineOf($second, $plaster)->isOpen());

        // Through its own run it works.
        $this->actingAs($this->qs)->post(route('rate-suggestions.lines.accept', [$second, $foreign]), ['candidate' => 'C1', 'mode' => 'set_boq_rate', 'shown' => $this->shownOn($this->qs, $second, $foreign, 'C1')])->assertSessionHasNoErrors();
        $this->assertSame('340.00', $plaster->fresh()->rate);
    }

    public function test_there_is_no_way_to_accept_every_line_at_once(): void
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'rate-suggestions')) {
                $routes[implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri()] = $route->getName();
            }
        }

        // One rate for one line is the only write there is.
        $this->assertSame([
            'POST projects/{project}/rate-suggestions' => 'rate-suggestions.store',
            'GET rate-suggestions/{run}' => 'rate-suggestions.show',
            'POST rate-suggestions/{run}/lines/{line}/accept' => 'rate-suggestions.lines.accept',
            'POST rate-suggestions/{run}/lines/{line}/skip' => 'rate-suggestions.lines.skip',
        ], $routes);

        $slab = $this->line('RCC M25 in slab');
        $plaster = $this->line('Cement plaster 12 mm thick', 'sqm');
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $this->libraryRate('Cement plaster 12 mm thick', 'sqm', '340.00');
        $run = $this->startRun($this->qs, $slab, $plaster);

        $this->accept($this->qs, $run, $slab, 'C1')->assertSessionHasNoErrors();
        $this->assertSame('0.00', $plaster->fresh()->rate);

        $page = $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->getContent();
        $this->assertSame(1, substr_count($page, 'Accept this rate'));
        $this->assertStringNotContainsStringIgnoringCase('accept all', $page);
    }

    public function test_a_rate_from_another_projects_boq_names_only_the_kind_of_source_in_the_history(): void
    {
        $this->travelTo('2026-11-13 10:00:00');
        $line = $this->line('RCC M25 in slab');
        $from = $this->line('RCC M25 in slab', 'cum', '5800.00', $this->other);
        $run = $this->startRun($this->qs, $line);

        $this->accept($this->qs, $run, $line, $this->refOf($run, $line, OwnBoqSource::KEY, $from->id))->assertSessionHasNoErrors();

        $reason = BoqRevision::withoutGlobalScopes()->where('boq_item_id', $line->id)->latest('id')->firstOrFail()->reason;
        $this->assertSame('Rate taken from BOQs of your other projects: RCC M25 in slab (13 Nov 2026)', $reason);
        $this->assertStringNotContainsString('Maplewood', $reason);
        $this->assertSame(OwnBoqSource::KEY, $this->lineOf($run, $line)->accepted_source);
        $this->assertSame('5800.00', $from->fresh()->rate);
    }
}
