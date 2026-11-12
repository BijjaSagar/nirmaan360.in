<?php

namespace Tests\Feature\RateHelper;

use App\Models\BoqItem;
use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRun;
use App\Services\Access\TeamAccessService;
use App\Services\RateHelper\Sources\OwnBoqSource;
use App\Services\RateHelper\Sources\OwnTenderSource;
use App\Services\RateHelper\Sources\RateLibrarySource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Who may use the rate helper, and what each of them can reach through it. */
class AccessTest extends RateHelperTestCase
{
    public function test_site_roles_and_accounts_are_refused_on_every_route(): void
    {
        $line = $this->line('RCC M25 in slab');
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $run = $this->startRun($this->owner, $line);
        $stored = $this->lineOf($run, $line);

        foreach (['engineer', 'store', 'viewer', 'accounts'] as $role) {
            $this->actingAs($this->{$role});

            $this->post(route('rate-suggestions.store', $this->project), ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => [$line->id]])->assertForbidden();
            $this->get(route('rate-suggestions.show', $run))->assertForbidden();
            $this->post(route('rate-suggestions.lines.accept', [$run, $stored]), ['candidate' => 'C1', 'mode' => 'set_boq_rate'])->assertForbidden();
            $this->post(route('rate-suggestions.lines.skip', [$run, $stored]))->assertForbidden();

            // The BOQ page they do open carries neither the button nor a rate.
            $this->get(route('boq.index', $this->project))->assertOk()->assertDontSee('Suggest rates')->assertDontSee('6,200.00');
        }

        $this->assertSame(1, RateSuggestionRun::withoutGlobalScopes()->count());
        $this->assertSame('0.00', $line->fresh()->rate);
        $this->assertTrue($this->lineOf($run, $line)->isOpen());

        foreach (['owner', 'pm', 'qs'] as $role) {
            $this->actingAs($this->{$role})->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('6,200.00');
            $this->actingAs($this->{$role})->get(route('boq.index', $this->project))->assertOk()->assertSee('Suggest rates');
        }
    }

    public function test_seeing_money_and_managing_the_boq_are_both_needed(): void
    {
        $line = $this->line('RCC M25 in slab');
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $run = $this->startRun($this->owner, $line);

        foreach ([['boq.manage', 'boq.view', 'rate.view'], ['money.view', 'boq.view', 'rate.view']] as $permissions) {
            $this->actingAs($this->holding(['project.view', ...$permissions]));
            $this->post(route('rate-suggestions.store', $this->project), ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => [$line->id]])->assertForbidden();
            $this->get(route('rate-suggestions.show', $run))->assertForbidden();
            $this->post(route('rate-suggestions.lines.accept', [$run, $this->lineOf($run, $line)]), ['candidate' => 'C1', 'mode' => 'set_boq_rate'])->assertForbidden();
        }

        $this->actingAs($this->holding(['project.view', 'boq.manage', 'boq.view', 'money.view', 'rate.view']))
            ->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('6,200.00');
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $line = $this->line('RCC M25 in slab');
        $run = $this->startRun($this->owner, $line);
        auth()->logout();

        $this->get(route('rate-suggestions.show', $run))->assertRedirect(route('login'));
        $this->post(route('rate-suggestions.store', $this->project), ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => [$line->id]])->assertRedirect(route('login'));
    }

    public function test_a_plan_without_reports_does_not_have_it(): void
    {
        $org = $this->tenant('Small Site Works', 'free');
        $owner = $this->member($org, 'owner');
        $project = $this->makeProject($org, ['code' => 'SS1', 'name' => 'Lake View Row House']);
        $line = $this->line('RCC M25 in slab', 'cum', '0.00', $project);

        $this->actingAs($owner);
        $this->post(route('rate-suggestions.store', $project), ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => [$line->id]])->assertForbidden();
        $this->get(route('boq.index', $project))->assertOk()->assertDontSee('Suggest rates');
        $this->assertSame(0, RateSuggestionRun::withoutGlobalScopes()->count());
    }

    public function test_another_company_cannot_reach_a_run_or_price_from_this_companys_lines(): void
    {
        [, $rivalOwner, $rivalProject] = $this->rival();
        $line = $this->line('RCC M25 in slab');
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $run = $this->startRun($this->owner, $line);
        $stored = $this->lineOf($run, $line);
        $rivalLine = $this->line('RCC M25 in slab', 'cum', '0.00', $rivalProject);

        $this->actingAs($rivalOwner);
        $this->get(route('rate-suggestions.show', $run))->assertNotFound();
        $this->post(route('rate-suggestions.lines.accept', [$run, $stored]), ['candidate' => 'C1', 'mode' => 'set_boq_rate'])->assertNotFound();
        $this->post(route('rate-suggestions.lines.skip', [$run, $stored]))->assertNotFound();
        $this->post(route('rate-suggestions.store', $this->project), ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => [$line->id]])->assertNotFound();

        // Their own project, but a line id from this company.
        $this->post(route('rate-suggestions.store', $rivalProject), ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => [$line->id]])->assertSessionHasErrors('boq_item_ids.0');
        // And this company's client_uuid is no key to its run.
        $this->post(route('rate-suggestions.store', $rivalProject), ['client_uuid' => $run->client_uuid, 'boq_item_ids' => [$rivalLine->id]])->assertRedirect();

        $this->assertSame('0.00', $line->fresh()->rate);
        $this->assertTrue($this->lineOf($run, $line)->isOpen());

        $theirs = RateSuggestionRun::withoutGlobalScopes()->where('organization_id', $rivalProject->organization_id)->get();
        $this->assertCount(1, $theirs);
        $this->assertNotSame($run->id, $theirs[0]->id);
        // Their list is empty: this company's library is not theirs to see.
        $this->assertSame([], $this->shortlist($theirs[0], $rivalLine));
    }

    public function test_a_stored_list_that_points_at_another_companys_rows_shows_and_accepts_nothing(): void
    {
        [$rivalOrg, , $rivalProject] = $this->rival();
        $rivalLibrary = $this->libraryRate('RCC M25 in slab', 'cum', '9911.00', $rivalOrg);
        $rivalBoq = $this->line('RCC M25 in slab', 'cum', '9922.00', $rivalProject);
        $rivalTender = $this->tenderLine('RCC M25 in slab', 'cum', '9933.00', 'won', [], $rivalOrg);

        $line = $this->line('RCC M25 in slab');
        $run = $this->startRun($this->owner, $line);
        $this->assertSame([], $this->shortlist($run, $line));

        // The list is never built this way. It is written by hand here, as a fault or a tampered row would leave it.
        RateSuggestionLine::withoutGlobalScopes()->whereKey($this->lineOf($run, $line)->id)->update(['candidates' => json_encode([
            ['ref' => 'C1', 'source' => RateLibrarySource::KEY, 'row_id' => $rivalLibrary->id, 'score' => 100, 'same_work' => null, 'note' => null],
            ['ref' => 'C2', 'source' => OwnBoqSource::KEY, 'row_id' => $rivalBoq->id, 'score' => 100, 'same_work' => null, 'note' => null],
            ['ref' => 'C3', 'source' => OwnTenderSource::KEY, 'row_id' => $rivalTender->id, 'score' => 100, 'same_work' => null, 'note' => null],
            ['ref' => 'C4', 'source' => 'no_such_source', 'row_id' => 1, 'score' => 100, 'same_work' => null, 'note' => null],
        ])]);
        $this->assertCount(4, $this->shortlist($run, $line));

        $this->actingAs($this->owner)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertDontSee('9,911.00')->assertDontSee('9,922.00')->assertDontSee('9,933.00')
            ->assertDontSee('Rival')->assertDontSee('Accept this rate');

        foreach (['C1', 'C2', 'C3', 'C4'] as $ref) {
            $this->accept($this->owner, $run, $line, $ref)->assertSessionHasErrors('candidate');
        }

        $this->assertSame('0.00', $line->fresh()->rate);
        $this->assertTrue($this->lineOf($run, $line)->isOpen());
    }

    public function test_a_tender_that_went_back_to_pricing_or_was_removed_is_no_longer_offered(): void
    {
        $line = $this->line('RCC M25 in slab');
        $reopened = $this->tenderLine('RCC M25 in slab', 'cum', '7149.75', 'submitted', ['title' => 'Ward office building']);
        $removed = $this->tenderLine('RCC M25 in slab', 'cum', '7388.25', 'won', ['title' => 'Bus depot shed']);

        $run = $this->startRun($this->qs, $line);
        $first = $this->refOf($run, $line, OwnTenderSource::KEY, $reopened->id);
        $second = $this->refOf($run, $line, OwnTenderSource::KEY, $removed->id);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('7,149.75')->assertSee('7,388.25');

        $this->actingAs($this->owner);
        $reopened->tender->update(['stage' => 'bidding']);
        $removed->tender->delete();

        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertDontSee('7,149.75')->assertDontSee('7,388.25')
            ->assertDontSee('Ward office building')->assertDontSee('Bus depot shed');

        foreach ([$first, $second] as $ref) {
            $this->accept($this->qs, $run, $line, $ref)->assertSessionHasErrors('candidate');
        }

        $this->assertSame('0.00', $line->fresh()->rate);
    }

    public function test_only_live_lines_of_this_projects_boq_can_be_picked(): void
    {
        $line = $this->line('RCC M25 in slab');
        $elsewhere = $this->line('RCC M25 in slab', 'cum', '0.00', $this->other);
        $removed = $this->line('Old line');
        $removed->delete();
        $send = fn (array $data) => $this->actingAs($this->qs)->post(route('rate-suggestions.store', $this->project), $data);
        $uuid = fn () => (string) Str::uuid();

        $send(['client_uuid' => $uuid(), 'boq_item_ids' => [$elsewhere->id]])->assertSessionHasErrors(['boq_item_ids.0' => 'One of these items is not in this project.']);
        $send(['client_uuid' => $uuid(), 'boq_item_ids' => [$line->id, $removed->id]])->assertSessionHasErrors('boq_item_ids.1');
        $send(['client_uuid' => $uuid(), 'boq_item_ids' => [$line->id, $line->id]])->assertSessionHasErrors('boq_item_ids.0');
        $send(['client_uuid' => $uuid(), 'boq_item_ids' => [999999]])->assertSessionHasErrors('boq_item_ids.0');
        $send(['client_uuid' => $uuid(), 'boq_item_ids' => ['abc']])->assertSessionHasErrors('boq_item_ids.0');
        $send(['client_uuid' => $uuid(), 'boq_item_ids' => []])->assertSessionHasErrors(['boq_item_ids' => 'Choose at least one BOQ line.']);
        $send(['client_uuid' => $uuid()])->assertSessionHasErrors('boq_item_ids');
        $send(['client_uuid' => $uuid(), 'boq_item_ids' => $line->id])->assertSessionHasErrors('boq_item_ids');
        $send(['boq_item_ids' => [$line->id]])->assertSessionHasErrors('client_uuid');
        $send(['client_uuid' => 'not-a-uuid', 'boq_item_ids' => [$line->id]])->assertSessionHasErrors('client_uuid');

        $this->assertSame(0, RateSuggestionRun::withoutGlobalScopes()->count());
    }

    public function test_one_run_takes_at_most_a_hundred_lines(): void
    {
        $this->assertSame(100, config('modules.rate_helper.max_lines'));

        $ids = [];

        foreach (range(1, 101) as $number) {
            $ids[] = $this->line('Line '.$number)->id;
        }

        $this->actingAs($this->qs)->post(route('rate-suggestions.store', $this->project), ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => $ids])
            ->assertSessionHasErrors(['boq_item_ids' => 'Choose at most 100 BOQ lines at a time.']);
        $this->assertSame(0, RateSuggestionRun::withoutGlobalScopes()->count());

        $this->actingAs($this->qs)->post(route('rate-suggestions.store', $this->project), ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => array_slice($ids, 0, 100)])
            ->assertSessionHasNoErrors();
        $this->assertSame(100, RateSuggestionLine::withoutGlobalScopes()->count());
    }

    public function test_the_same_client_uuid_gives_the_same_run(): void
    {
        $line = $this->line('RCC M25 in slab');
        $second = $this->line('Cement plaster', 'sqm');
        $data = ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => [$line->id]];

        $first = $this->actingAs($this->qs)->postJson(route('rate-suggestions.store', $this->project), $data)->assertCreated();
        $run = RateSuggestionRun::withoutGlobalScopes()->sole();
        $first->assertExactJson(['id' => $run->id]);

        // Sent again, even with other lines: the run made the first time, and nothing new.
        $this->actingAs($this->qs)->postJson(route('rate-suggestions.store', $this->project), [...$data, 'boq_item_ids' => [$second->id]])->assertOk()->assertExactJson(['id' => $run->id]);
        $this->actingAs($this->pm)->post(route('rate-suggestions.store', $this->project), $data)->assertRedirect(route('rate-suggestions.show', $run));

        $this->assertSame(1, RateSuggestionRun::withoutGlobalScopes()->count());
        $this->assertSame([$line->id], RateSuggestionLine::withoutGlobalScopes()->pluck('boq_item_id')->all());

        // Someone who cannot reach that run's project is told so, rather than handed it or a duplicate.
        $elsewhere = $this->limited('qs', $this->other);
        $otherLine = $this->line('RCC M25 in slab', 'cum', '0.00', $this->other);
        $this->actingAs($elsewhere)->post(route('rate-suggestions.store', $this->other), [...$data, 'boq_item_ids' => [$otherLine->id]])->assertSessionHasErrors('client_uuid');
        $this->assertSame(1, RateSuggestionRun::withoutGlobalScopes()->count());
    }

    public function test_starting_a_run_has_its_own_counter(): void
    {
        $line = $this->line('RCC M25 in slab');
        $send = fn () => $this->actingAs($this->qs)->post(route('rate-suggestions.store', $this->project), ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => [$line->id]]);

        // The plain per-person counter other screens share is full, and this route still answers.
        $shared = sha1((string) $this->qs->id);

        foreach (range(1, 500) as $hit) {
            RateLimiter::hit($shared, 60);
        }

        $send()->assertRedirect();

        // Its own counter allows ten a minute.
        foreach (range(2, 10) as $attempt) {
            $send()->assertRedirect();
        }

        $this->actingAs($this->qs)->postJson(route('rate-suggestions.store', $this->project), ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => [$line->id]])->assertStatus(429);
        $this->assertSame(10, RateSuggestionRun::withoutGlobalScopes()->count());

        // Another person has a counter of their own.
        $this->actingAs($this->pm)->post(route('rate-suggestions.store', $this->project), ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => [$line->id]])->assertRedirect();
    }

    public function test_someone_limited_to_other_projects_cannot_reach_this_projects_runs(): void
    {
        $line = $this->line('RCC M25 in slab');
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $run = $this->startRun($this->owner, $line);
        $stored = $this->lineOf($run, $line);

        $this->actingAs($this->limited('qs', $this->other));
        $this->get(route('rate-suggestions.show', $run))->assertNotFound();
        $this->post(route('rate-suggestions.lines.accept', [$run, $stored]), ['candidate' => 'C1', 'mode' => 'set_boq_rate'])->assertNotFound();
        $this->post(route('rate-suggestions.lines.skip', [$run, $stored]))->assertNotFound();
        $this->post(route('rate-suggestions.store', $this->project), ['client_uuid' => (string) Str::uuid(), 'boq_item_ids' => [$line->id]])->assertNotFound();

        $this->assertSame('0.00', $line->fresh()->rate);
        $this->assertTrue($this->lineOf($run, $line)->isOpen());

        // Limited to this project, the same person works as usual.
        $this->actingAs($this->limited('qs', $this->project))->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('6,200.00');
    }

    public function test_someone_limited_to_chosen_projects_is_offered_nothing_from_a_project_outside_them(): void
    {
        $line = $this->line('RCC M25 in slab');
        $hidden = $this->line('RCC M25 in slab with secret admixture', 'cum', '5817.25', $this->other);
        $hiddenTender = $this->tenderLine('RCC M25 in slab for the hidden tender', 'cum', '7149.75', 'won', ['title' => 'Maplewood Plaza tender', 'project_id' => $this->other->id]);
        $openTender = $this->tenderLine('RCC M25 in slab', 'cum', '7300.00', 'submitted', ['title' => 'Ward office building']);
        $third = $this->makeProject($this->org, ['code' => 'CC3', 'name' => 'Cedar Court']);
        $reachable = $this->line('RCC M25 in slab', 'cum', '5900.00', $third);
        $library = $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');

        $limited = $this->limited('qs', $this->project, $third);
        $run = $this->startRun($limited, $line);

        // At the shortlist: the library, the project on their list and the tender that is no project yet.
        $this->assertNull($this->refOf($run, $line, OwnBoqSource::KEY, $hidden->id));
        $this->assertNull($this->refOf($run, $line, OwnTenderSource::KEY, $hiddenTender->id));
        $this->assertNotNull($this->refOf($run, $line, OwnBoqSource::KEY, $reachable->id));
        $this->assertNotNull($this->refOf($run, $line, OwnTenderSource::KEY, $openTender->id));
        $this->assertNotNull($this->refOf($run, $line, RateLibrarySource::KEY, $library->id));

        $this->actingAs($limited)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('5,900.00')->assertSee('Cedar Court')->assertSee('7,300.00')->assertSee('6,200.00')
            ->assertDontSee('Maplewood')->assertDontSee('GP2')
            ->assertDontSee('5,817.25')->assertDontSee('7,149.75')
            ->assertDontSee('secret admixture')->assertDontSee('hidden tender');
    }

    public function test_a_run_made_by_someone_who_sees_more_shows_a_limited_person_only_their_own_reach(): void
    {
        $line = $this->line('RCC M25 in slab');
        $hidden = $this->line('RCC M25 in slab with secret admixture', 'cum', '5817.25', $this->other);
        $hiddenTender = $this->tenderLine('RCC M25 in slab for the hidden tender', 'cum', '7149.75', 'won', ['title' => 'Maplewood Plaza tender', 'project_id' => $this->other->id]);
        $library = $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');

        $run = $this->startRun($this->owner, $line);
        $boqRef = $this->refOf($run, $line, OwnBoqSource::KEY, $hidden->id);
        $tenderRef = $this->refOf($run, $line, OwnTenderSource::KEY, $hiddenTender->id);
        $this->assertNotNull($boqRef);
        $this->assertNotNull($tenderRef);

        // The owner sees all three.
        $this->actingAs($this->owner)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('5,817.25')->assertSee('GP2 · Maplewood Plaza')->assertSee('7,149.75')->assertSee('Maplewood Plaza tender');

        $limited = $this->limited('qs', $this->project);

        // When the list is shown: no candidate, name or rate from the project outside their list.
        $this->actingAs($limited)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('6,200.00')
            ->assertDontSee('Maplewood')->assertDontSee('GP2')
            ->assertDontSee('5,817.25')->assertDontSee('7,149.75')
            ->assertDontSee('secret admixture')->assertDontSee('hidden tender');

        // When accepting: the refs exist on the line, and are still refused for this person.
        foreach ([$boqRef, $tenderRef] as $ref) {
            $this->accept($limited, $run, $line, $ref, ['checked_warnings' => '1'])->assertSessionHasErrors('candidate');
        }

        $this->assertSame('0.00', $line->fresh()->rate);
        $this->assertTrue($this->lineOf($run, $line)->isOpen());

        // What they can reach, they can accept.
        $this->accept($limited, $run, $line, $this->refOf($run, $line, RateLibrarySource::KEY, $library->id))->assertSessionHasNoErrors();
        $this->assertSame('6200.00', $line->fresh()->rate);
    }

    public function test_a_rate_that_moved_out_of_a_persons_projects_after_the_list_was_made_is_refused(): void
    {
        $line = $this->line('RCC M25 in slab');
        $from = $this->line('RCC M25 in slab', 'cum', '5817.25', $this->other);

        $limited = $this->limited('qs', $this->project, $this->other);
        $run = $this->startRun($limited, $line);
        $ref = $this->refOf($run, $line, OwnBoqSource::KEY, $from->id);
        $this->assertNotNull($ref);
        $this->actingAs($limited)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('5,817.25');

        app(TeamAccessService::class)->applyProjects($limited, true, [$this->project->id]);
        $limited->refresh();

        $this->actingAs($limited)->get(route('rate-suggestions.show', $run))->assertOk()->assertDontSee('5,817.25')->assertDontSee('Maplewood');
        $this->accept($limited, $run, $line, $ref)->assertSessionHasErrors('candidate');
        $this->assertSame('0.00', $line->fresh()->rate);
    }

    public function test_tender_lines_are_only_for_people_who_may_open_tenders(): void
    {
        $line = $this->line('RCC M25 in slab');
        $tender = $this->tenderLine('RCC M25 in slab', 'cum', '7149.75', 'won', ['title' => 'Ward office building']);
        $library = $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');

        $this->assertTrue($this->qs->can('crm.view'));
        $withoutSales = $this->holding(['project.view', 'boq.view', 'boq.manage', 'money.view', 'rate.view']);
        $this->assertFalse($withoutSales->can('crm.view'));

        // At the shortlist.
        $own = $this->startRun($withoutSales, $line);
        $this->assertNull($this->refOf($own, $line, OwnTenderSource::KEY, $tender->id));
        $this->assertNotNull($this->refOf($own, $line, RateLibrarySource::KEY, $library->id));
        $this->actingAs($withoutSales)->get(route('rate-suggestions.show', $own))->assertOk()
            ->assertSee('Not open to your role')
            ->assertDontSee('7,149.75')->assertDontSee('Ward office building');

        // On a run made by someone who may: not shown, and not accepted.
        $run = $this->startRun($this->qs, $line);
        $ref = $this->refOf($run, $line, OwnTenderSource::KEY, $tender->id);
        $this->assertNotNull($ref);
        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('7,149.75')->assertSee('Ward office building');
        $this->actingAs($withoutSales)->get(route('rate-suggestions.show', $run))->assertOk()->assertDontSee('7,149.75')->assertDontSee('Ward office building');
        $this->accept($withoutSales, $run, $line, $ref)->assertSessionHasErrors('candidate');
        $this->assertSame('0.00', $line->fresh()->rate);

        // A company whose plan has no sales module has no tender source at all.
        config(['buildos.plans.business.features' => array_values(array_diff(config('buildos.plans.business.features'), ['crm']))]);
        $third = $this->startRun($this->owner, $line);
        $this->assertNull($this->refOf($third, $line, OwnTenderSource::KEY, $tender->id));
        $this->actingAs($this->owner)->get(route('rate-suggestions.show', $third))->assertOk()->assertSee('Your past tenders: none loaded.')->assertDontSee('7,149.75');
    }

    public function test_the_rate_library_is_only_for_people_who_may_read_rates(): void
    {
        $line = $this->line('RCC M25 in slab');
        $library = $this->libraryRate('RCC M25 in slab', 'cum', '6234.50');
        $boq = $this->line('RCC M25 in slab', 'cum', '5800.00', $this->other);

        $withoutRates = $this->holding(['project.view', 'boq.view', 'boq.manage', 'money.view']);
        $own = $this->startRun($withoutRates, $line);

        $this->assertNull($this->refOf($own, $line, RateLibrarySource::KEY, $library->id));
        $this->assertNotNull($this->refOf($own, $line, OwnBoqSource::KEY, $boq->id));

        $run = $this->startRun($this->owner, $line);
        $this->actingAs($withoutRates)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('5,800.00')->assertDontSee('6,234.50');
        $this->accept($withoutRates, $run, $line, $this->refOf($run, $line, RateLibrarySource::KEY, $library->id))->assertSessionHasErrors('candidate');
        $this->assertSame('0.00', $line->fresh()->rate);
    }

    public function test_the_boq_page_offers_the_lines_without_a_rate(): void
    {
        $unpriced = $this->line('Unpriced slab work');
        $priced = $this->line('Priced brick work', 'cum', '4100.00');
        $lumpSum = $this->line('Mobilisation of plant', 'LS');

        $page = $this->actingAs($this->qs)->get(route('boq.index', $this->project))->assertOk()
            ->assertSee('Suggest rates')
            ->assertSee(route('rate-suggestions.store', $this->project))
            ->assertSee('Lump-sum line: no rate is suggested.')
            ->assertSee('No government schedule is loaded.')
            ->getContent();

        // Ticked to begin with: the line with no rate, and not the lump sum.
        $this->assertStringContainsString('open: false, picked: JSON.parse(\'[\u0022'.$unpriced->id.'\u0022]\')', $page);
        $this->assertMatchesRegularExpression('/name="boq_item_ids\[\]" value="'.$lumpSum->id.'"[^>]*disabled/', $page);
        $this->assertDoesNotMatchRegularExpression('/name="boq_item_ids\[\]" value="'.$priced->id.'"[^>]*disabled/', $page);
        $this->assertMatchesRegularExpression('/name="client_uuid" value="[0-9a-f-]{36}"/', $page);

        // A project with no BOQ has nothing to price.
        $this->actingAs($this->qs)->get(route('boq.index', $this->other))->assertOk()->assertDontSee('Suggest rates');

        // A refused form opens again with its ticks.
        $this->actingAs($this->qs)->from(route('boq.index', $this->project))
            ->post(route('rate-suggestions.store', $this->project), ['form' => 'rate-suggest', 'client_uuid' => 'broken', 'boq_item_ids' => [$priced->id]])
            ->assertRedirect(route('boq.index', $this->project));
        $this->actingAs($this->qs)->get(route('boq.index', $this->project))->assertOk()
            ->assertSee('open: true, picked: JSON.parse(\'[\u0022'.$priced->id.'\u0022]\')', false);
    }

    public function test_a_run_goes_with_its_project_when_the_project_is_removed(): void
    {
        $line = $this->line('RCC M25 in slab', 'cum', '0.00', $this->other);
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $run = $this->startRun($this->owner, $line);
        $stored = $this->lineOf($run, $line);

        $this->actingAs($this->owner);
        $this->other->delete();

        $this->get(route('rate-suggestions.show', $run))->assertNotFound();
        $this->post(route('rate-suggestions.lines.accept', [$run, $stored]), ['candidate' => 'C1', 'mode' => 'set_boq_rate'])->assertNotFound();
        $this->post(route('rate-suggestions.lines.skip', [$run, $stored]))->assertNotFound();

        $this->assertSame('0.00', $line->fresh()->rate);
        $this->assertTrue($this->lineOf($run, $line)->isOpen());
    }

    public function test_a_run_keeps_the_order_of_the_boq(): void
    {
        $first = $this->line('First line', 'cum', '0.00', null, ['sort' => 1]);
        $second = $this->line('Second line', 'cum', '0.00', null, ['sort' => 2]);
        $third = $this->line('Third line', 'cum', '0.00', null, ['sort' => 3]);

        $run = $this->startRun($this->qs, $third, $first, $second);

        $this->assertSame([$first->id, $second->id, $third->id], $run->lines()->pluck('boq_item_id')->all());
        $this->actingAs($this->qs)->get(route('rate-suggestions.show', $run))->assertOk()->assertSeeInOrder(['First line', 'Second line', 'Third line']);
        $this->assertSame(3, BoqItem::withoutGlobalScopes()->where('project_id', $this->project->id)->count());
    }
}
