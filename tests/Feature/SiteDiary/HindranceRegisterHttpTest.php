<?php

namespace Tests\Feature\SiteDiary;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Hindrance;
use App\Models\HindranceSource;
use Illuminate\Support\Str;

/** The register screen and its four writes: who may use them, what they accept and what they save. */
class HindranceRegisterHttpTest extends SiteDiaryTestCase
{
    private function index(array $query = [], $project = null): string
    {
        return route('site-diary.hindrances.index', [$project ?? $this->project, ...$query]);
    }

    private function store($project = null): string
    {
        return route('site-diary.hindrances.store', $project ?? $this->project);
    }

    public function test_every_report_that_says_something_under_hindrances_is_offered_and_no_other(): void
    {
        $this->dpr('2026-03-02', 'Podium slab drawings not received');
        $this->dpr('2026-03-03', "Heavy rain from 2 pm.\nPour stopped.", ['status' => 'APPROVED', 'site_id' => $this->site('Wing B')->id]);
        $this->dpr('2026-03-04', null);
        $this->dpr('2026-03-05', '   ');
        $this->dpr('2026-03-06', '--');
        $this->dpr('2026-03-07', 'Removed report about the crane')->delete();
        $this->dpr('2026-03-08', 'Belongs to the other project', [], $this->makeProject($this->org, ['code' => 'SG2']));

        $page = $this->actingAs($this->pm)->get($this->index())->assertOk()
            ->assertSee('Make an entry')
            ->assertSee('Podium slab drawings not received')
            ->assertSee('Heavy rain from 2 pm.')->assertSee('Pour stopped.')
            ->assertSee('02 Mar 2026')->assertSee('03 Mar 2026')->assertSee('Wing B')
            ->assertDontSee('Removed report about the crane')
            ->assertDontSee('Belongs to the other project')
            ->assertSee('No entries in the register');

        $this->assertSame(2, substr_count($page->getContent(), 'name="dpr_ids[]"'));

        // No category, date or name is filled in on the empty form.
        $page->assertSee('<option value="">Choose</option>', false);
        $this->assertSame(0, preg_match('/<option value="[a-z_]+" selected/', $page->getContent()));
        $this->assertSame(1, preg_match('/name="started_on" type="date" max="2026-03-20" value=""/', $page->getContent()));
        $this->assertSame(1, preg_match('/name="ended_on" type="date" max="2026-03-20" value=""/', $page->getContent()));
        $this->assertSame(0, preg_match('/name="dpr_ids\[\]"[^>]*checked/', $page->getContent()));
    }

    public function test_someone_who_only_reads_the_register_is_offered_no_form_and_no_reports(): void
    {
        $this->dpr('2026-03-02', 'Podium slab drawings not received');
        $entry = $this->cite($this->hindrance(), $this->dpr('2026-03-03', 'Still no drawings'));

        $this->actingAs($this->qs)->get($this->index())->assertOk()
            ->assertSee('Hindrance register')
            ->assertSee('Podium slab drawings awaited')
            ->assertSee('Still no drawings')
            ->assertDontSee('Make an entry')
            ->assertDontSee('Podium slab drawings not received')
            ->assertDontSee('name="dpr_ids[]"', false)
            ->assertDontSee(route('site-diary.hindrances.dismiss', $entry), false);
    }

    public function test_an_entry_takes_its_dates_from_the_ticked_reports_and_keeps_what_they_said(): void
    {
        $site = $this->site();
        $first = $this->dpr('2026-03-02', 'Podium slab drawings not received');
        $middle = $this->dpr('2026-03-04', '  Drawings still awaited.  ', ['status' => 'APPROVED']);
        $last = $this->dpr('2026-03-06', 'Drawings received at 5 pm', ['status' => 'IN_REVIEW']);

        $this->actingAs($this->engineer)
            ->post($this->store(), $this->form([$last, $first, $middle], ['responsible' => '  Architect of the client  ', 'site_id' => $site->id]))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $entry = Hindrance::sole();

        $this->assertSame($this->org->id, $entry->organization_id);
        $this->assertSame($this->project->id, $entry->project_id);
        $this->assertSame(['drawings_or_decisions', 'Podium slab drawings awaited from the architect', 'Architect of the client'], [$entry->category, $entry->description, $entry->responsible]);
        $this->assertSame(['2026-03-02', '2026-03-06'], [$entry->started_on->toDateString(), $entry->ended_on->toDateString()]);
        $this->assertSame($site->id, $entry->site_id);
        $this->assertSame([Hindrance::CONFIRMED, Hindrance::BY_PERSON], [$entry->status, $entry->proposed_by]);
        $this->assertSame($this->engineer->id, $entry->confirmed_by);
        $this->assertNotNull($entry->confirmed_at);
        $this->assertNull($entry->locked_at);
        $this->assertNull($entry->supersedes_id);

        $this->assertSame([
            [$first->id, '2026-03-02', 'Podium slab drawings not received', 'DRAFT'],
            [$middle->id, '2026-03-04', 'Drawings still awaited.', 'APPROVED'],
            [$last->id, '2026-03-06', 'Drawings received at 5 pm', 'IN_REVIEW'],
        ], $entry->sources->map(fn (HindranceSource $source) => [$source->dpr_id, $source->report_date->toDateString(), $source->excerpt, $source->dpr_status])->all());
        $this->assertSame([$this->org->id], $entry->sources->pluck('organization_id')->unique()->values()->all());
        $this->assertSame([$this->project->id], $entry->sources->pluck('project_id')->unique()->values()->all());

        // Who made it and what it rests on are in the audit log.
        $this->assertSame(1, AuditLog::where('action', 'created')->where('auditable_type', $entry->getMorphClass())->where('user_id', $this->engineer->id)->count());
        $cited = AuditLog::where('action', 'hindrance.reports_cited')->sole();
        $this->assertNull($cited->old_values);
        $this->assertSame([$first->id, $middle->id, $last->id], array_column($cited->new_values['reports'], 'dpr_id'));
        $this->assertStringNotContainsString('Drawings', json_encode($cited->new_values));
    }

    public function test_typed_dates_win_over_the_report_dates_and_one_still_going_on_has_no_end(): void
    {
        $report = $this->dpr('2026-03-04', 'No power at site since last week');
        $this->actingAs($this->pm);

        $this->post($this->store(), $this->form([$report], ['category' => 'utilities_or_permissions', 'started_on' => '2026-02-26', 'ended_on' => '2026-03-10']))->assertSessionHasNoErrors();
        $typed = Hindrance::latest('id')->first();
        $this->assertSame(['2026-02-26', '2026-03-10'], [$typed->started_on->toDateString(), $typed->ended_on->toDateString()]);

        $this->post($this->store(), $this->form([$report], ['still_going_on' => '1']))->assertSessionHasNoErrors();
        $ongoing = Hindrance::latest('id')->first();
        $this->assertSame('2026-03-04', $ongoing->started_on->toDateString());
        $this->assertNull($ongoing->ended_on);
        $this->assertTrue($ongoing->isOngoing());

        // A blank end date sent beside the tick is the same thing.
        $this->post($this->store(), $this->form([$report], ['still_going_on' => '1', 'ended_on' => '', 'started_on' => '2026-03-01']))->assertSessionHasNoErrors();
        $this->assertNull(Hindrance::latest('id')->first()->ended_on);
        $this->assertSame(3, Hindrance::count());
    }

    public function test_an_entry_is_refused_when_something_it_needs_is_missing_or_wrong(): void
    {
        $report = $this->dpr('2026-03-04', 'No power at site');
        $otherProject = $this->makeProject($this->org, ['code' => 'SG2']);
        [, , $rivalProject] = $this->rival();
        $this->actingAs($this->pm);
        $post = fn (array $overrides, array $reports = [null]) => $this->post($this->store(), $this->form($reports === [null] ? [$report] : $reports, $overrides));

        $post(['category' => ''])->assertSessionHasErrors('category');
        $post(['category' => 'act_of_god'])->assertSessionHasErrors('category');
        $post(['description' => ''])->assertSessionHasErrors('description');
        $post(['description' => str_repeat('a', 2001)])->assertSessionHasErrors('description');
        $post(['responsible' => str_repeat('a', 161)])->assertSessionHasErrors('responsible');
        $post(['client_uuid' => 'not-a-uuid'])->assertSessionHasErrors('client_uuid');
        $post(['client_uuid' => ''])->assertSessionHasErrors('client_uuid');

        // Dates: a real day, not after today, and the end not before the start.
        $post(['started_on' => '04/03/2026'])->assertSessionHasErrors('started_on');
        $post(['started_on' => '2026-03-21'])->assertSessionHasErrors('started_on');
        $post(['ended_on' => '2026-03-21'])->assertSessionHasErrors('ended_on');
        $post(['started_on' => '2026-03-05', 'ended_on' => '2026-03-04'])->assertSessionHasErrors('ended_on');
        $post(['still_going_on' => '1', 'ended_on' => '2026-03-10'])->assertSessionHasErrors('ended_on');
        $post(['still_going_on' => 'perhaps'])->assertSessionHasErrors('still_going_on');
        // The start is typed after the last ticked report and no end is given.
        $post(['started_on' => '2026-03-10'])->assertSessionHasErrors(['ended_on' => 'The last chosen report is dated before the start date. Type the day the hindrance ended, or tick that it is still going on.']);
        // The end is typed before the first ticked report and no start is given: the start taken from the report is what to change.
        $post(['ended_on' => '2026-03-01'])
            ->assertSessionHasErrors(['started_on' => 'The end date you typed is before the first chosen report, whose date is taken as the start. Type the day the hindrance started, or choose the reports again.'])
            ->assertSessionDoesntHaveErrors('ended_on');

        // Reports: at least one, of this project, still there and still saying something.
        $post([], [])->assertSessionHasErrors('dpr_ids');
        $post(['dpr_ids' => 'all'])->assertSessionHasErrors('dpr_ids');
        $post([], [$report, $report])->assertSessionHasErrors('dpr_ids.0');
        $post([], [$this->dpr('2026-03-05', 'Other project', [], $otherProject)])->assertSessionHasErrors('dpr_ids.0');
        $post([], [$this->dpr('2026-03-05', 'Other company', [], $rivalProject)])->assertSessionHasErrors('dpr_ids.0');
        $post([], [tap($this->dpr('2026-03-05', 'Removed'))->delete()])->assertSessionHasErrors('dpr_ids.0');
        $post([], [999999])->assertSessionHasErrors('dpr_ids.0');
        $post([], [$report, $this->dpr('2026-03-05', null)])->assertSessionHasErrors('dpr_ids');
        $post([], [$this->dpr('2026-03-05', ' -- ')])->assertSessionHasErrors('dpr_ids');

        // A site of this project only.
        $post(['site_id' => $this->site('Elsewhere', $otherProject)->id])->assertSessionHasErrors('site_id');
        $post(['site_id' => $this->site('Rival', $rivalProject)->id])->assertSessionHasErrors('site_id');
        $post(['site_id' => tap($this->site('Gone'))->delete()->id])->assertSessionHasErrors('site_id');

        $this->assertSame(0, Hindrance::withoutGlobalScopes()->count());
        $this->assertSame(0, HindranceSource::withoutGlobalScopes()->count());
    }

    public function test_pressing_save_twice_makes_one_entry(): void
    {
        $report = $this->dpr('2026-03-04', 'No power at site');
        $form = $this->form([$report]);

        $this->actingAs($this->pm)->post($this->store(), $form)->assertSessionHasNoErrors();
        $this->actingAs($this->engineer)->post($this->store(), [...$form, 'description' => 'Typed again by someone else'])->assertSessionHasNoErrors();

        $entry = Hindrance::sole();
        $this->assertSame($this->pm->id, $entry->confirmed_by);
        $this->assertSame('Podium slab drawings awaited from the architect', $entry->description);
        $this->assertSame(1, HindranceSource::count());

        // The same request id on another project is not answered with this project's entry.
        $other = $this->makeProject($this->org, ['code' => 'SG2']);
        $this->post($this->store($other), [...$form, 'dpr_ids' => [$this->dpr('2026-03-04', 'Other', [], $other)->id]])->assertSessionHasErrors('client_uuid');

        // A dismissed entry still answers for its request id.
        $this->post(route('site-diary.hindrances.dismiss', $entry))->assertSessionHasNoErrors();
        $this->post($this->store(), $form)->assertSessionHasNoErrors();
        $this->assertSame(1, Hindrance::count());
        $this->assertSame(Hindrance::DISMISSED, $entry->refresh()->status);

        // Another company may happen to send the same value.
        [, $rivalOwner, $rivalProject] = $this->rival();
        $this->actingAs($rivalOwner)->post($this->store($rivalProject), [...$form, 'dpr_ids' => [$this->dpr('2026-03-04', 'Theirs', [], $rivalProject)->id]])->assertSessionHasNoErrors();
        $this->assertSame(2, Hindrance::withoutGlobalScopes()->count());
    }

    public function test_who_may_read_the_register_and_who_may_write_in_it(): void
    {
        $report = $this->dpr('2026-03-04', 'No power at site');
        $entry = $this->cite($this->hindrance(), $report);
        $proposed = $this->hindrance(['status' => Hindrance::PROPOSED, 'proposed_by' => Hindrance::BY_AI, 'confirmed_by' => null, 'confirmed_at' => null]);
        $change = ['category' => 'weather', 'description' => 'Changed', 'dpr_ids' => [$report->id]];

        // Project manager and site engineer keep the register.
        foreach ([$this->owner, $this->pm, $this->engineer] as $user) {
            $this->actingAs($user)->get($this->index())->assertOk();
            $this->post($this->store(), $this->form([$report]))->assertSessionHasNoErrors()->assertRedirect();
            $this->patch(route('site-diary.hindrances.update', $entry), $change)->assertSessionHasNoErrors()->assertRedirect();
        }

        // The estimator reads it and changes nothing.
        $this->actingAs($this->qs)->get($this->index())->assertOk();
        $this->post($this->store(), $this->form([$report]))->assertForbidden();
        $this->patch(route('site-diary.hindrances.update', $entry), $change)->assertForbidden();
        $this->post(route('site-diary.hindrances.confirm', $proposed))->assertForbidden();
        $this->post(route('site-diary.hindrances.dismiss', $entry))->assertForbidden();

        // Accounts, the store keeper and a viewer have no part in it.
        foreach (['accounts', 'store', 'viewer'] as $role) {
            $this->actingAs($this->member($this->org, $role))->get($this->index())->assertForbidden();
            $this->post($this->store(), $this->form([$report]))->assertForbidden();
            $this->patch(route('site-diary.hindrances.update', $entry), $change)->assertForbidden();
            $this->post(route('site-diary.hindrances.confirm', $proposed))->assertForbidden();
            $this->post(route('site-diary.hindrances.dismiss', $entry))->assertForbidden();
        }

        $this->assertSame(Hindrance::PROPOSED, $proposed->refresh()->status);
        $this->assertSame(Hindrance::CONFIRMED, $entry->refresh()->status);
        $this->assertSame(5, Hindrance::count());

        // Nobody signed in is sent to sign in.
        $this->app['auth']->forgetGuards();
        $this->get($this->index())->assertRedirect(route('login'));
        $this->post($this->store(), $this->form([$report]))->assertRedirect(route('login'));
    }

    public function test_copying_what_a_report_says_also_takes_the_right_to_open_reports(): void
    {
        $report = $this->dpr('2026-03-04', 'No power at site since morning');
        $entry = $this->hindrance(['status' => Hindrance::PROPOSED, 'confirmed_by' => null, 'confirmed_at' => null]);
        $keeper = $this->holding(['project.view', 'site_diary.view', 'site_diary.manage']);

        $this->actingAs($keeper)->get($this->index())->assertOk()
            ->assertDontSee('Make an entry')
            ->assertDontSee('No power at site since morning')
            ->assertDontSee(route('site-diary.hindrances.index', [$this->project, 'edit' => $entry->id]), false);

        $this->post($this->store(), $this->form([$report]))->assertForbidden();
        $this->patch(route('site-diary.hindrances.update', $entry), ['category' => 'weather', 'description' => 'Changed', 'dpr_ids' => [$report->id]])->assertForbidden();
        $this->assertSame(0, HindranceSource::count());

        // Confirming and dismissing copy nothing, so they stay open to them.
        $this->post(route('site-diary.hindrances.confirm', $entry))->assertSessionHasNoErrors();
        $this->assertSame(Hindrance::CONFIRMED, $entry->refresh()->status);
        $this->post(route('site-diary.hindrances.dismiss', $entry))->assertSessionHasNoErrors();
        $this->assertSame(Hindrance::DISMISSED, $entry->refresh()->status);

        // With the right to manage but not to read the register, nothing is shown.
        $this->actingAs($this->holding(['project.view', 'site_diary.manage', 'dpr.view']))->get($this->index())->assertForbidden();
    }

    public function test_a_plan_without_site_control_has_no_register(): void
    {
        $free = $this->tenant('Small Site Co', 'free');
        $owner = $this->member($free, 'owner');
        $project = $this->makeProject($free, ['code' => 'FR1']);
        $entry = $this->hindrance([], $project);

        $this->actingAs($owner)->get($this->index([], $project))->assertForbidden();
        $this->post($this->store($project), $this->form([$this->dpr('2026-03-04', 'Rain', [], $project)]))->assertForbidden();
        $this->post(route('site-diary.hindrances.dismiss', $entry))->assertForbidden();
        $this->get(route('projects.show', $project))->assertOk()->assertDontSee('Hindrance register');

        $this->assertSame(Hindrance::CONFIRMED, $entry->refresh()->status);
    }

    public function test_another_company_reaches_neither_the_register_nor_an_entry(): void
    {
        $report = $this->dpr('2026-03-04', 'No power at site since morning');
        $entry = $this->cite($this->hindrance(), $report);
        $proposed = $this->hindrance(['status' => Hindrance::PROPOSED, 'confirmed_by' => null, 'confirmed_at' => null]);
        [, $rivalOwner, $rivalProject] = $this->rival();
        $theirReport = $this->dpr('2026-03-05', 'Their own report', [], $rivalProject);

        $this->actingAs($rivalOwner);
        $this->get($this->index())->assertNotFound();
        $this->post($this->store(), $this->form([$report]))->assertNotFound();
        $this->patch(route('site-diary.hindrances.update', $entry), ['category' => 'weather', 'description' => 'Taken over', 'dpr_ids' => [$theirReport->id]])->assertNotFound();
        $this->post(route('site-diary.hindrances.confirm', $proposed))->assertNotFound();
        $this->post(route('site-diary.hindrances.dismiss', $entry))->assertNotFound();

        // Their own register shows nothing of ours, and our entry cannot be opened in their form.
        $this->get($this->index([], $rivalProject))->assertOk()
            ->assertSee('Their own report')
            ->assertDontSee('No power at site since morning')
            ->assertDontSee('Podium slab drawings awaited');
        $this->get($this->index(['edit' => $entry->id], $rivalProject))->assertOk()->assertSee('Make an entry')->assertDontSee('Change the entry');
        // Nor can one of our reports be cited from their project.
        $this->post($this->store($rivalProject), $this->form([$report]))->assertSessionHasErrors('dpr_ids.0');

        $entry->refresh();
        $this->assertSame(['drawings_or_decisions', 'Podium slab drawings awaited', Hindrance::CONFIRMED], [$entry->category, $entry->description, $entry->status]);
        $this->assertSame(Hindrance::PROPOSED, $proposed->refresh()->status);
        $this->assertSame(2, Hindrance::withoutGlobalScopes()->count());
    }

    public function test_someone_limited_to_other_projects_reaches_nothing_here(): void
    {
        $elsewhere = $this->makeProject($this->org, ['code' => 'SG2', 'name' => 'Hill Road Offices']);
        $report = $this->dpr('2026-03-04', 'No power at site since morning');
        $entry = $this->cite($this->hindrance(), $report);
        $limited = $this->limitTo($this->member($this->org, 'pm'), $elsewhere);

        $this->actingAs($limited);
        $this->get($this->index())->assertNotFound();
        $this->post($this->store(), $this->form([$report]))->assertNotFound();
        $this->patch(route('site-diary.hindrances.update', $entry), ['category' => 'weather', 'description' => 'Changed', 'dpr_ids' => [$report->id]])->assertNotFound();
        $this->post(route('site-diary.hindrances.dismiss', $entry))->assertNotFound();

        // In their own project they work as usual, but cannot cite a report or correct an entry of the hidden one.
        $this->get($this->index([], $elsewhere))->assertOk()->assertDontSee('No power at site since morning');
        $this->post($this->store($elsewhere), $this->form([$report]))->assertSessionHasErrors('dpr_ids.0');
        $this->post($this->store($elsewhere), $this->form([$this->dpr('2026-03-05', 'Rain', [], $elsewhere)], ['supersedes_id' => $entry->id]))->assertSessionHasErrors('supersedes_id');
        $this->post($this->store($elsewhere), $this->form([$this->dpr('2026-03-06', 'Rain again', [], $elsewhere)]))->assertSessionHasNoErrors();

        $this->assertSame(Hindrance::CONFIRMED, $entry->refresh()->status);
        $this->assertSame([$elsewhere->id], Hindrance::withoutGlobalScopes()->whereKeyNot($entry->id)->pluck('project_id')->all());
    }

    public function test_changing_an_entry_reads_the_reports_again_and_puts_the_editors_name_on_it(): void
    {
        $first = $this->dpr('2026-03-02', 'Podium slab drawings not received');
        $second = $this->dpr('2026-03-04', 'Drawings still awaited');
        $third = $this->dpr('2026-03-09', 'Revised drawings promised for next week');

        $this->actingAs($this->engineer)->post($this->store(), $this->form([$first, $second]))->assertSessionHasNoErrors();
        $entry = Hindrance::sole();
        $kept = $entry->sources->firstWhere('dpr_id', $first->id);

        // The first report is reworded and approved in the meantime; the entry still holds the old words.
        $first->update(['hindrances' => 'Podium slab drawings not received. Asked again by email.', 'status' => 'APPROVED']);
        $this->assertSame('Podium slab drawings not received', $kept->refresh()->excerpt);

        $this->travel(2)->hours();
        $this->actingAs($this->pm)->patch(route('site-diary.hindrances.update', $entry), [
            'category' => 'drawings_or_decisions', 'description' => 'Podium slab drawings awaited, then revised', 'responsible' => 'Architect',
            'still_going_on' => '1', 'dpr_ids' => [$first->id, $third->id],
            // Sent by a form that was tampered with: neither is taken on a change.
            'client_uuid' => (string) Str::uuid(), 'supersedes_id' => $entry->id, 'status' => 'dismissed', 'locked_at' => '2026-03-01',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $entry->refresh();
        $this->assertSame(['Podium slab drawings awaited, then revised', 'Architect'], [$entry->description, $entry->responsible]);
        $this->assertSame('2026-03-02', $entry->started_on->toDateString());
        $this->assertNull($entry->ended_on);
        $this->assertSame([Hindrance::CONFIRMED, $this->pm->id], [$entry->status, $entry->confirmed_by]);
        $this->assertNull($entry->supersedes_id);
        $this->assertNull($entry->locked_at);

        $sources = $entry->sources()->get();
        $this->assertSame([$first->id, $third->id], $sources->pluck('dpr_id')->all());
        $this->assertSame(['Podium slab drawings not received. Asked again by email.', 'APPROVED'], [$sources[0]->excerpt, $sources[0]->dpr_status]);
        $this->assertSame($kept->id, $sources[0]->id, 'A report that stays cited keeps its row.');
        $this->assertSame(0, HindranceSource::where('dpr_id', $second->id)->count());

        $log = AuditLog::where('action', 'hindrance.reports_cited')->latest('id')->first();
        $this->assertSame([$first->id, $second->id], array_column($log->old_values['reports'], 'dpr_id'));
        $this->assertSame([$first->id, $third->id], array_column($log->new_values['reports'], 'dpr_id'));
        $this->assertNotSame($log->old_values['reports'][0]['words'], $log->new_values['reports'][0]['words']);

        // Saving the same again changes nothing, so the name on it stays.
        $this->actingAs($this->engineer)->patch(route('site-diary.hindrances.update', $entry), [
            'category' => 'drawings_or_decisions', 'description' => 'Podium slab drawings awaited, then revised', 'responsible' => 'Architect',
            'started_on' => '2026-03-02', 'still_going_on' => '1', 'dpr_ids' => [$third->id, $first->id],
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->pm->id, $entry->refresh()->confirmed_by);
        $this->assertSame(2, AuditLog::where('action', 'hindrance.reports_cited')->count());
    }

    public function test_a_change_is_checked_like_a_new_entry(): void
    {
        $report = $this->dpr('2026-03-04', 'No power at site');
        $entry = $this->cite($this->hindrance(), $report);
        $this->actingAs($this->pm);
        $patch = fn (array $overrides) => $this->patch(route('site-diary.hindrances.update', $entry), ['category' => 'weather', 'description' => 'Changed', 'dpr_ids' => [$report->id], ...$overrides]);

        $patch(['category' => 'act_of_god'])->assertSessionHasErrors('category');
        $patch(['description' => ''])->assertSessionHasErrors('description');
        $patch(['dpr_ids' => []])->assertSessionHasErrors('dpr_ids');
        $patch(['dpr_ids' => [$this->dpr('2026-03-05', 'Elsewhere', [], $this->makeProject($this->org, ['code' => 'SG2']))->id]])->assertSessionHasErrors('dpr_ids.0');
        $patch(['started_on' => '2026-03-10', 'ended_on' => '2026-03-09'])->assertSessionHasErrors('ended_on');

        // A cited report that was blanked since has to be left out before the entry can be saved.
        $report->update(['hindrances' => null]);
        $patch([])->assertSessionHasErrors('dpr_ids');

        $entry->refresh();
        $this->assertSame(['drawings_or_decisions', 'Podium slab drawings awaited'], [$entry->category, $entry->description]);
        $this->assertSame('No power at site', $entry->sources->sole()->excerpt);
    }

    public function test_an_entry_put_forward_by_the_app_counts_only_once_someone_confirms_it(): void
    {
        $report = $this->dpr('2026-03-04', 'No power at site');
        $entry = $this->cite($this->hindrance(['status' => Hindrance::PROPOSED, 'proposed_by' => Hindrance::BY_AI, 'confirmed_by' => null, 'confirmed_at' => null]), $report);

        $this->actingAs($this->pm)->get($this->index())->assertOk()
            ->assertSee('Waiting to be confirmed')->assertSee('The AI, from the daily reports')->assertSee('No confirmed entry yet.');

        // Changing it does not confirm it.
        $this->patch(route('site-diary.hindrances.update', $entry), ['category' => 'utilities_or_permissions', 'description' => 'No power', 'dpr_ids' => [$report->id]])->assertSessionHasNoErrors();
        $this->assertSame([Hindrance::PROPOSED, Hindrance::BY_AI, null], [$entry->refresh()->status, $entry->proposed_by, $entry->confirmed_by]);

        $this->actingAs($this->engineer)->post(route('site-diary.hindrances.confirm', $entry))->assertSessionHasNoErrors()->assertSessionHas('success');
        $entry->refresh();
        $this->assertSame([Hindrance::CONFIRMED, Hindrance::BY_AI, $this->engineer->id], [$entry->status, $entry->proposed_by, $entry->confirmed_by]);
        $this->assertNotNull($entry->confirmed_at);

        // Confirming again changes nothing, by whoever.
        $this->actingAs($this->pm)->post(route('site-diary.hindrances.confirm', $entry))->assertSessionHasNoErrors();
        $this->assertSame($this->engineer->id, $entry->refresh()->confirmed_by);

        // Dismissed, it stays in the register without a name on it, and can be brought back.
        $this->post(route('site-diary.hindrances.dismiss', $entry))->assertSessionHasNoErrors()->assertSessionHas('success');
        $entry->refresh();
        $this->assertSame([Hindrance::DISMISSED, null, null], [$entry->status, $entry->confirmed_by, $entry->confirmed_at]);
        $this->assertFalse($entry->trashed());
        $this->get($this->index())->assertOk()->assertSee('Dismissed or corrected: 1')->assertSee('Bring back and confirm');

        $this->post(route('site-diary.hindrances.confirm', $entry))->assertSessionHasNoErrors();
        $this->assertSame([Hindrance::CONFIRMED, $this->pm->id], [$entry->refresh()->status, $entry->confirmed_by]);

        $changes = AuditLog::where('action', 'updated')->where('auditable_type', $entry->getMorphClass())->orderBy('id')->pluck('new_values')->map(fn (array $values) => $values['status'] ?? null)->filter()->values()->all();
        $this->assertSame([Hindrance::CONFIRMED, Hindrance::DISMISSED, Hindrance::CONFIRMED], $changes);
    }

    public function test_an_entry_a_letter_cites_cannot_be_changed_and_is_corrected_by_a_new_entry(): void
    {
        $report = $this->dpr('2026-03-02', 'Podium slab drawings not received');
        $later = $this->dpr('2026-03-12', 'Drawings received today');
        $locked = $this->cite($this->hindrance(['locked_at' => now()]), $report);
        $open = $this->cite($this->hindrance(['category' => 'weather', 'description' => 'Rain']), $later);
        $this->actingAs($this->pm);

        // Every change answers 409 and leaves the entry as the letter read it.
        $this->patch(route('site-diary.hindrances.update', $locked), ['category' => 'weather', 'description' => 'Changed', 'dpr_ids' => [$report->id]])->assertStatus(409);
        $this->post(route('site-diary.hindrances.confirm', $locked))->assertStatus(409);
        $this->post(route('site-diary.hindrances.dismiss', $locked))->assertStatus(409);
        $this->assertSame(['drawings_or_decisions', 'Podium slab drawings awaited', Hindrance::CONFIRMED], [$locked->refresh()->category, $locked->description, $locked->status]);

        // The table row itself refuses, whoever writes it.
        foreach ([fn () => $locked->update(['description' => 'Rewritten']), fn () => $locked->delete()] as $write) {
            try {
                $write();
                $this->fail('A locked entry was changed.');
            } catch (ImmutableRecordException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }

        $this->assertSame('Podium slab drawings awaited', Hindrance::find($locked->id)->description);

        // The page offers a correction for it, and a change for the other.
        $this->get($this->index())->assertOk()
            ->assertSee('Cited in a letter')
            ->assertSee(route('site-diary.hindrances.index', [$this->project, 'correct' => $locked->id]), false)
            ->assertDontSee(route('site-diary.hindrances.index', [$this->project, 'edit' => $locked->id]), false)
            ->assertSee(route('site-diary.hindrances.index', [$this->project, 'edit' => $open->id]), false)
            ->assertDontSee(route('site-diary.hindrances.update', $locked), false);
        $this->get($this->index(['correct' => $locked->id]))->assertOk()
            ->assertSee('Correct an entry that a letter cites')
            ->assertSee('name="supersedes_id" value="'.$locked->id.'"', false);
        // Neither form opens for the wrong kind of entry.
        $this->get($this->index(['edit' => $locked->id]))->assertOk()->assertSee('Make an entry')->assertDontSee('Change the entry');
        $this->get($this->index(['correct' => $open->id]))->assertOk()->assertSee('Make an entry')->assertDontSee('name="supersedes_id"', false);

        // Only a locked entry of this project is corrected this way.
        $this->post($this->store(), $this->form([$report], ['supersedes_id' => $open->id]))->assertSessionHasErrors('supersedes_id');
        $this->post($this->store(), $this->form([$report], ['supersedes_id' => $this->hindrance(['locked_at' => now()], $this->makeProject($this->org, ['code' => 'SG2']))->id]))->assertSessionHasErrors('supersedes_id');
        $this->post($this->store(), $this->form([$report], ['supersedes_id' => 999999]))->assertSessionHasErrors('supersedes_id');

        $this->post($this->store(), $this->form([$report, $later], ['supersedes_id' => $locked->id, 'description' => 'Podium slab drawings awaited until 12 March']))->assertSessionHasNoErrors();
        $correction = Hindrance::where('supersedes_id', $locked->id)->sole();
        $this->assertSame([Hindrance::CONFIRMED, $this->pm->id, '2026-03-02', '2026-03-12'], [$correction->status, $correction->confirmed_by, $correction->started_on->toDateString(), $correction->ended_on->toDateString()]);
        $this->assertNull($correction->locked_at);
        $this->assertSame([$correction->id], $locked->corrections()->pluck('id')->all());
        $this->assertTrue($correction->supersedes->is($locked));
        $this->assertSame('2026-03-06', $locked->refresh()->ended_on->toDateString());

        // One correction at a time.
        $this->post($this->store(), $this->form([$report], ['supersedes_id' => $locked->id]))->assertSessionHasErrors('supersedes_id');

        // The register now counts the correction in place of the locked entry: 2 to 12 March and 2 to 6 March of the other entry.
        $page = $this->get($this->index())->assertOk()
            ->assertSee('Corrected by a later entry')
            ->assertSee('This entry corrects the entry for 02 Mar 2026 to 06 Mar 2026 that a letter cites.')
            ->assertDontSee(route('site-diary.hindrances.index', [$this->project, 'correct' => $locked->id]), false);
        $this->assertSame(1, preg_match('/Days in all<\/p>\s*<p[^>]*>11<\/p>/', $page->getContent()));

        // Dismissing the correction brings the locked entry back into the count, and a second correction may follow.
        $this->post(route('site-diary.hindrances.dismiss', $correction))->assertSessionHasNoErrors();
        $this->assertSame([], $locked->corrections()->pluck('id')->all());
        $this->get($this->index())->assertOk()
            ->assertSee(route('site-diary.hindrances.index', [$this->project, 'correct' => $locked->id]), false)
            ->assertDontSee('This entry corrects the entry for');
        $this->post($this->store(), $this->form([$report], ['supersedes_id' => $locked->id, 'description' => 'Second correction']))->assertSessionHasNoErrors();
        // The dismissed one cannot come back beside it.
        $this->post(route('site-diary.hindrances.confirm', $correction))->assertSessionHasErrors('status');
        $this->assertSame(Hindrance::DISMISSED, $correction->refresh()->status);
    }

    public function test_the_register_shows_the_figures_the_app_worked_out(): void
    {
        $site = $this->site('Wing B');
        // 2 to 6 March, five days. Reports on the 2nd (approved), 3rd (in review) and 6th (draft); none on the 4th and 5th.
        $reports = [
            $this->dpr('2026-03-02', 'Podium slab drawings not received', ['status' => 'APPROVED']),
            $this->dpr('2026-03-03', 'Drawings still awaited', ['status' => 'IN_REVIEW']),
            $this->dpr('2026-03-06', 'Drawings received at 5 pm'),
        ];
        // Outside the period, and returned for correction.
        $outside = $this->dpr('2026-03-09', 'Drawings were late by a week', ['status' => 'RETURNED']);
        $first = $this->cite($this->hindrance(['site_id' => $site->id, 'responsible' => 'Architect of the client']), ...$reports, ...[$outside]);

        // 5 March, still going on: 16 days up to the 20th. Overlaps the first on the 5th and 6th.
        $rain = $this->dpr('2026-03-05', 'Rain all day');
        $second = $this->cite($this->hindrance(['category' => 'weather', 'description' => 'Site flooded', 'started_on' => '2026-03-05', 'ended_on' => null]), $rain);

        // Dismissed and proposed entries are left out of the total.
        $this->hindrance(['status' => Hindrance::DISMISSED, 'description' => 'Entered by mistake', 'started_on' => '2026-01-01', 'ended_on' => '2026-01-31', 'confirmed_by' => null, 'confirmed_at' => null]);
        $this->hindrance(['status' => Hindrance::PROPOSED, 'proposed_by' => Hindrance::BY_CODE, 'description' => 'Put forward by code', 'started_on' => '2026-02-01', 'ended_on' => '2026-02-10', 'confirmed_by' => null, 'confirmed_at' => null]);

        // One cited report is reworded and another removed after it was cited.
        $reports[1]->update(['hindrances' => 'Drawings still awaited. Reminder sent.']);
        $rain->delete();

        $page = $this->actingAs($this->qs)->get($this->index())->assertOk();
        $html = $page->getContent();

        // 2 to 20 March with the shared days counted once is 19 days, from 2 entries, 1 still going on.
        $this->assertSame(1, preg_match('/Entries that count<\/p>\s*<p[^>]*>2<\/p>/', $html));
        $this->assertSame(1, preg_match('/Days in all<\/p>\s*<p[^>]*>19<\/p>/', $html));
        $this->assertSame(1, preg_match('/Still going on<\/p>\s*<p[^>]*>1<\/p>/', $html));
        $page->assertSee('A day two entries share is counted once. Entries still going on are counted up to today.')
            ->assertSee('Days are calendar days, and both the first and the last day are counted.');

        $firstCard = $this->card($html, $first);
        $this->assertStringContainsString('Drawings or decisions awaited', $firstCard);
        $this->assertStringContainsString('02 Mar 2026 to 06 Mar 2026', $firstCard);
        $this->assertStringContainsString('5 days', $firstCard);
        $this->assertStringNotContainsString('up to today', $firstCard);
        $this->assertStringContainsString('Wing B', $firstCard);
        $this->assertStringContainsString('Architect of the client', $firstCard);
        $this->assertStringContainsString('4 cited, 1 approved, 2 can still be edited', $firstCard);
        $this->assertStringContainsString('3 with a cited report, 2 with no daily report at all', $firstCard);
        $this->assertStringContainsString('Reports that say something else now than when they were cited: 1.', $firstCard);
        $this->assertStringContainsString('Cited reports dated outside the period: 1.', $firstCard);
        $this->assertStringContainsString('Drawings still awaited', $firstCard);
        $this->assertStringNotContainsString('Reminder sent', $firstCard, 'The entry keeps the words as they were when the report was cited.');
        $this->assertStringContainsString('Says something else now', $firstCard);
        $this->assertStringContainsString($this->owner->name, $firstCard);

        $secondCard = $this->card($html, $second);
        $this->assertStringContainsString('Weather', $secondCard);
        $this->assertStringContainsString('From 05 Mar 2026, still going on', $secondCard);
        $this->assertStringContainsString('16 days up to today', $secondCard);
        $this->assertStringContainsString('Whole project', $secondCard);
        $this->assertStringContainsString('1 cited, 0 approved, 0 can still be edited', $secondCard);
        // The removed report no longer counts as a report of its day. Of the 16 days only the 6th and the 9th have a report.
        $this->assertStringContainsString('0 with a cited report, 14 with no daily report at all', $secondCard);
        $this->assertStringContainsString('Cited reports that have been removed since: 1.', $secondCard);
        $this->assertStringContainsString('Report removed', $secondCard);
        $this->assertStringContainsString('Rain all day', $secondCard);
        $this->assertStringNotContainsString(route('dprs.show', $rain->id), $secondCard);
        $this->assertStringContainsString(route('dprs.show', $reports[0]->id), $firstCard);

        $page->assertSee('Waiting to be confirmed')->assertSee('The app, from the daily reports')->assertSee('Dismissed or corrected: 1');
    }

    /** The part of the page that belongs to one entry. */
    private function card(string $html, Hindrance $entry): string
    {
        $this->assertSame(1, preg_match('/<article id="hindrance-'.$entry->id.'".*?<\/article>/s', $html, $match), 'The entry is on the page.');

        return html_entity_decode($match[0]);
    }

    public function test_the_form_opens_an_entry_with_what_it_holds_and_says_which_reports_it_will_drop(): void
    {
        $kept = $this->dpr('2026-03-02', 'Podium slab drawings not received');
        $blanked = $this->dpr('2026-03-03', 'Drawings still awaited');
        $other = $this->dpr('2026-03-10', 'Rain all day');
        $elsewhere = $this->cite($this->hindrance(['category' => 'weather', 'description' => 'Rain']), $other);
        $entry = $this->cite($this->hindrance(['responsible' => 'Architect', 'ended_on' => null]), $kept, $blanked);
        $blanked->update(['hindrances' => null]);

        $page = $this->actingAs($this->pm)->get($this->index(['edit' => $entry->id]))->assertOk()
            ->assertSee('Change the entry')
            ->assertSee('action="'.route('site-diary.hindrances.update', $entry).'"', false)
            ->assertSee('name="_method" value="PATCH"', false)
            ->assertDontSee('name="client_uuid"', false)
            ->assertSee('value="Architect"', false)
            ->assertSee('Podium slab drawings awaited</textarea>', false)
            ->assertSee('Reports this entry cites that can no longer be chosen, because they were removed or say nothing under hindrances now: 03 Mar 2026. They are left out when you save.')
            ->assertSee('Already in the register')
            ->assertSee('Saving the entry confirms it in your name.');
        $html = $page->getContent();

        $this->assertSame(1, preg_match('/name="dpr_ids\[\]" value="'.$kept->id.'"[^>]*checked/', $html));
        $this->assertSame(0, preg_match('/name="dpr_ids\[\]" value="'.$other->id.'"[^>]*checked/', $html));
        $this->assertSame(0, preg_match('/name="dpr_ids\[\]" value="'.$blanked->id.'"/', $html));
        $this->assertSame(1, preg_match('/<option value="drawings_or_decisions" selected/', $html));
        $this->assertSame(1, preg_match('/name="started_on" type="date" max="2026-03-20" value="2026-03-02"/', $html));
        $this->assertSame(1, preg_match('/name="still_going_on" value="1"[^>]*checked/', $html));

        // The form filters the reports by date and keeps the entry open while it does.
        $filtered = $this->get($this->index(['edit' => $entry->id, 'from' => '2026-03-05', 'to' => '2026-03-31']))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="dpr_ids\[\]" value="'.$other->id.'"/', $filtered));
        $this->assertSame(1, preg_match('/name="dpr_ids\[\]" value="'.$kept->id.'"[^>]*checked/', $filtered), 'A report the entry cites is always listed.');
        $this->assertStringContainsString('name="edit" value="'.$entry->id.'"', $filtered);
        // Dates that are not dates are ignored.
        $this->get($this->index(['from' => 'last week', 'to' => ['x']]))->assertOk();

        // Only the newest reports are listed at one time.
        config(['modules.site_diary.candidates_shown' => 1]);
        $newest = $this->get($this->index())->assertOk()->assertSee('Only the newest reports are listed. Use the two dates above to see older ones.')->getContent();
        $this->assertSame(1, substr_count($newest, 'name="dpr_ids[]"'));
        $this->assertSame(1, preg_match('/name="dpr_ids\[\]" value="'.$other->id.'"/', $newest));
        $this->assertNotNull($elsewhere->id);
    }

    public function test_a_refused_save_brings_the_form_back_as_it_was_sent_not_as_it_was_saved(): void
    {
        $first = $this->dpr('2026-03-02', 'Podium slab drawings not received');
        $second = $this->dpr('2026-03-03', 'Drawings still awaited');
        $entry = $this->cite($this->hindrance(['started_on' => '2026-03-02', 'ended_on' => null]), $first, $second);
        $edit = $this->index(['edit' => $entry->id]);
        $update = route('site-diary.hindrances.update', $entry);
        $sent = ['category' => 'drawings_or_decisions', 'description' => 'Podium slab drawings awaited', 'started_on' => '2026-03-02', 'ended_on' => '2026-03-09', 'still_going_on' => '0'];

        // The box is sent even when it is not ticked, so the page can tell an unticked box from one that was never sent.
        $this->actingAs($this->engineer)->get($edit)->assertOk()->assertSee('<input type="hidden" name="still_going_on" value="0">', false);

        // "Still going on" unticked, the end typed, and every report unticked: refused for the reports.
        $this->from($edit)->patch($update, $sent)->assertRedirect($edit)->assertSessionHasErrors('dpr_ids');

        $html = $this->get($edit)->assertOk()->getContent();
        $this->assertStringContainsString('x-data="{ ongoing: false }"', $html, 'The end date field is not switched off again.');
        $this->assertSame(0, preg_match('/name="still_going_on" value="1"[^>]*checked/', $html));
        $this->assertSame(1, preg_match('/name="ended_on" type="date" max="2026-03-20" value="2026-03-09"/', $html));
        $this->assertSame(0, preg_match('/name="dpr_ids\[\]"[^>]*checked/', $html), 'The reports the person unticked stay unticked.');

        // Sent again with the reports, the end date that was typed is saved.
        $this->from($edit)->patch($update, [...$sent, 'dpr_ids' => [$first->id, $second->id]])->assertSessionHasNoErrors();
        $this->assertSame('2026-03-09', $entry->refresh()->ended_on->toDateString());

        // Opened afresh, the form shows the entry as saved.
        $html = $this->get($edit)->assertOk()->getContent();
        $this->assertSame(2, preg_match_all('/name="dpr_ids\[\]"[^>]*checked/', $html));
        $this->assertStringContainsString('x-data="{ ongoing: false }"', $html);
    }

    public function test_showing_reports_of_other_dates_saves_nothing_and_keeps_what_was_ticked_and_typed(): void
    {
        $january = $this->dpr('2026-01-10', 'Crane breakdown');
        $march = $this->dpr('2026-03-12', 'Podium slab drawings not received');
        $window = ['from' => '2026-01-01', 'to' => '2026-01-31'];

        // "Show" sends the entry form along, switched to showing reports; without that switch a save is a save.
        $this->actingAs($this->engineer)->get($this->index())->assertOk()
            ->assertSee('x-on:submit="keep($event)"', false)
            ->assertSee('<input type="hidden" name="show_reports" value="1" disabled>', false);

        $typed = $this->form([$march], ['category' => 'weather', 'description' => 'Typed before showing older reports', 'responsible' => 'Client', 'started_on' => '2026-03-12', 'still_going_on' => '1']);
        $this->post($this->store(), [...$typed, 'show_reports' => '1', ...$window])
            ->assertRedirect($this->index($window).'#entry-form')->assertSessionHasNoErrors();
        $this->assertSame(0, Hindrance::withoutGlobalScopes()->count(), 'Nothing is saved.');

        $html = $this->get($this->index($window))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="dpr_ids\[\]" value="'.$january->id.'" class="mt-0.5 size-5 shrink-0" >/', $html), 'The report of those dates is listed, not ticked.');
        $this->assertSame(1, preg_match('/name="dpr_ids\[\]" value="'.$march->id.'"[^>]*checked/', $html), 'The report ticked before stays listed and ticked.');
        $this->assertStringContainsString('Typed before showing older reports</textarea>', $html);
        $this->assertStringContainsString('value="Client"', $html);
        $this->assertSame(1, preg_match('/<option value="weather" selected/', $html));
        $this->assertSame(1, preg_match('/name="started_on" type="date" max="2026-03-20" value="2026-03-12"/', $html));
        $this->assertStringContainsString('x-data="{ ongoing: true }"', $html);
        $this->assertStringContainsString('name="client_uuid" value="'.$typed['client_uuid'].'"', $html);

        // Saved with the January report added, the entry is made once.
        $this->post($this->store(), [...$typed, 'dpr_ids' => [$january->id, $march->id]])->assertSessionHasNoErrors();
        $entry = Hindrance::withoutGlobalScopes()->sole();
        $this->assertSame(['2026-01-10', '2026-03-12'], $entry->sources()->orderBy('report_date')->get()->map(fn ($source) => $source->report_date->toDateString())->all());

        // Changing an entry, the form stays on that entry; correcting a locked one, on the correction.
        $this->patch(route('site-diary.hindrances.update', $entry), ['category' => 'weather', 'description' => 'Changed', 'dpr_ids' => [$march->id], 'show_reports' => '1', 'from' => '2026-01-01'])
            ->assertRedirect($this->index(['edit' => $entry->id, 'from' => '2026-01-01']).'#entry-form');
        $this->assertSame('Typed before showing older reports', $entry->refresh()->description);

        $locked = $this->cite($this->hindrance(['locked_at' => now()]), $march);
        $this->post($this->store(), $this->form([$march], ['supersedes_id' => (string) $locked->id, 'show_reports' => '1', 'to' => 'not a day']))
            ->assertRedirect($this->index(['correct' => $locked->id]).'#entry-form');
        $this->assertSame(0, Hindrance::withoutGlobalScopes()->where('supersedes_id', $locked->id)->count());

        // Only those who keep the register reach it, and only for their own company.
        $this->actingAs($this->qs)->post($this->store(), [...$typed, 'show_reports' => '1'])->assertForbidden();
        [, $rivalOwner] = $this->rival();
        $this->actingAs($rivalOwner)->patch(route('site-diary.hindrances.update', $entry), ['show_reports' => '1'])->assertNotFound();
        $this->actingAs($rivalOwner)->post($this->store(), [...$typed, 'show_reports' => '1'])->assertNotFound();
    }

    public function test_the_menu_offers_the_register_to_those_who_may_open_it(): void
    {
        $link = route('site-diary.hindrances.index', $this->project);

        foreach ([$this->owner, $this->pm, $this->engineer, $this->qs] as $user) {
            $this->actingAs($user)->get(route('projects.show', $this->project))->assertOk()->assertSee($link, false)->assertSee('Hindrance register');
        }

        foreach (['accounts', 'store', 'viewer'] as $role) {
            $this->actingAs($this->member($this->org, $role))->get(route('projects.show', $this->project))->assertOk()->assertDontSee($link, false)->assertDontSee('Hindrance register');
        }
    }
}
