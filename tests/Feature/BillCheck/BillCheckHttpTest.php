<?php

namespace Tests\Feature\BillCheck;

use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\BillCheck;
use App\Models\BillCheckAck;
use App\Models\BillCheckPolicy;
use App\Models\RaBill;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use App\Services\ApprovalService;
use App\Services\BillCheck\BillChecks;
use App\Services\BillCheck\CheckPresenter;
use App\Services\BillCheck\RaBillPreCheck;
use App\Services\RaBillService;
use Illuminate\Support\Facades\URL;

/**
 * The four routes: who may use each, that a company and a project never see another's, and that money
 * never reaches a reader who may not see it.
 */
class BillCheckHttpTest extends BillCheckTestCase
{
    private RaBill $bill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->measure('20');
        $this->bill = $this->clientBill();
    }

    /** A project manager limited to a project other than the bill's. */
    private function limitedElsewhere(): User
    {
        $other = $this->makeProject($this->org, ['code' => 'MB9']);
        $user = $this->member($this->org, 'pm');
        app(TeamAccessService::class)->applyProjects($user, true, [$other->id]);

        return $user->refresh();
    }

    public function test_checking_a_bill_needs_the_permission_to_make_bills(): void
    {
        foreach ([$this->accounts, $this->engineer, $this->viewer] as $user) {
            $this->actingAs($user)->post(route('bill-checks.run', $this->bill))->assertForbidden();
        }

        $this->assertSame(0, BillCheck::withoutGlobalScopes()->count());

        foreach ([$this->pm, $this->qs, $this->owner] as $user) {
            $this->actingAs($user)->from(route('ra-bills.show', $this->bill))->post(route('bill-checks.run', $this->bill))
                ->assertRedirect(route('ra-bills.show', $this->bill))->assertSessionHasNoErrors();
        }

        // Nothing changed between the three, so one check stands, made by the first of them.
        $check = BillCheck::withoutGlobalScopes()->sole();
        $this->assertSame($this->pm->id, $check->run_by);
        $this->assertSame($this->bill->id, $check->ra_bill_id);
        $this->assertSame($this->project->id, $check->project_id);
        $this->assertSame($this->org->id, $check->organization_id);
    }

    public function test_checking_says_whether_anything_is_new(): void
    {
        $this->actingAs($this->pm);

        $this->post(route('bill-checks.run', $this->bill))->assertSessionHas('success', 'Bill checked. The findings are listed under Bill pre-check.');
        $this->post(route('bill-checks.run', $this->bill))->assertSessionHas('success', 'Checked again. Nothing has changed since the last check.');
        $this->postJson(route('bill-checks.run', $this->bill))->assertOk()->assertJsonPath('check.summary.tick', 4);

        // Something the bill depends on changes: a new check is written and the old one is kept as it was.
        $this->term();
        $this->postJson(route('bill-checks.run', $this->bill))->assertCreated()->assertJsonPath('check.summary.tick', 3);

        $this->assertSame([4, 3], BillCheck::withoutGlobalScopes()->orderBy('id')->get()->map(fn (BillCheck $check) => $check->summary['tick'])->all());
    }

    public function test_a_bill_that_was_already_submitted_cannot_be_checked_again(): void
    {
        $check = $this->check($this->bill, $this->pm);
        app(ApprovalService::class)->submit($this->bill, $this->pm);

        $this->actingAs($this->pm)->post(route('bill-checks.run', $this->bill))
            ->assertSessionHasErrors(['check' => 'Only a draft or returned bill can be checked. This one has already been submitted.']);
        $this->actingAs($this->pm)->postJson(route('bill-checks.run', $this->bill))->assertUnprocessable()->assertJsonValidationErrors('check');

        // What was checked before submitting stays as the record.
        $this->assertSame($check->id, BillCheck::withoutGlobalScopes()->sole()->id);
        $this->actingAs($this->pm)->getJson(route('bill-checks.show', $this->bill))->assertOk()
            ->assertJsonPath('check.id', $check->id)->assertJsonPath('can.run', false)->assertJsonPath('can.acknowledge', false);
    }

    public function test_reading_a_check_needs_the_permission_to_see_bills(): void
    {
        $this->actingAs($this->engineer)->getJson(route('bill-checks.show', $this->bill))->assertForbidden();

        // Before anyone has checked the bill the answer is an empty one, not an error.
        $this->actingAs($this->viewer)->getJson(route('bill-checks.show', $this->bill))->assertOk()
            ->assertExactJson(['check' => null, 'required' => false, 'can' => ['run' => false, 'acknowledge' => false, 'set_policy' => false]]);

        $check = $this->check($this->bill, $this->pm);
        app(BillChecks::class)->acknowledge($check, 'T1:tds', 'As on the form', $this->qs);

        $response = $this->actingAs($this->accounts)->getJson(route('bill-checks.show', $this->bill))->assertOk()
            ->assertJsonPath('check.id', $check->id)
            ->assertJsonPath('check.ra_bill_id', $this->bill->id)
            ->assertJsonPath('check.run_by', ['id' => $this->pm->id, 'name' => $this->pm->name])
            ->assertJsonPath('check.flags_hash', $check->flags_hash)
            ->assertJsonPath('check.summary', ['blocking' => 0, 'tick' => 4, 'note' => 0, 'to_acknowledge' => 3])
            ->assertJsonPath('check.expected', null)
            ->assertJsonPath('required', false)
            ->assertJsonPath('can', ['run' => false, 'acknowledge' => false, 'set_policy' => false]);

        $flags = collect($response->json('check.flags'))->keyBy('key');
        $this->assertSame(['T1:retention', 'T1:tds', 'T1:gst', 'T1:advance_recovery'], $flags->keys()->all());
        $this->assertSame([
            'key' => 'T1:retention', 'code' => 'T1', 'level' => 'tick',
            'text' => 'Retention 6.5%: this percentage is the one typed on the contract form. Nobody has confirmed it against the agreement.',
            'params' => ['head' => 'retention', 'pct' => '6.5'], 'needs_ack' => true, 'ack' => null,
        ], $flags['T1:retention']);
        $this->assertSame('As on the form', $flags['T1:tds']['ack']['remark']);
        $this->assertSame(['id' => $this->qs->id, 'name' => $this->qs->name], $flags['T1:tds']['ack']['user']);

        $this->actingAs($this->pm)->getJson(route('bill-checks.show', $this->bill))->assertOk()
            ->assertJsonPath('can', ['run' => true, 'acknowledge' => true, 'set_policy' => false]);
        $this->actingAs($this->owner)->getJson(route('bill-checks.show', $this->bill))->assertOk()->assertJsonPath('can.set_policy', true);
    }

    public function test_money_percentages_and_words_from_contract_terms_are_null_for_a_viewer(): void
    {
        // A bill with rupee findings, a contract percentage, a term's own words and a sheet of other deductions.
        $this->measure('5', ['measured_on' => '2026-11-12']);
        app(RaBillService::class)->cancel($this->bill);
        $first = $this->clientBill('2026-11-01', '2026-11-15');
        $this->measure('3', ['measured_on' => '2026-11-20']);
        $bill = $this->clientBill('2026-11-16', '2026-11-30');
        app(RaBillService::class)->cancel($first);
        $term = $this->term(['pct' => '7.25', 'source_ref' => 'Sample agreement, part C, page 41']);
        $this->term(['code' => 'other', 'label' => 'Site office rent', 'method' => 'fixed_per_bill', 'pct' => null, 'base' => null, 'amount' => '750.00']);
        app(RaBillPreCheck::class)->run($bill, $this->pm);

        $forAccounts = $this->actingAs($this->accounts)->getJson(route('bill-checks.show', $bill))->assertOk();
        $accountsFlags = collect($forAccounts->json('check.flags'))->keyBy('key');

        $this->assertSame('The advance still to be recovered is now ₹40,000.00, but this bill was worked out with ₹35,200.00.', $accountsFlags['B2']['text']);
        $this->assertSame(['stored' => '35200.00', 'now' => '40000.00'], $accountsFlags['B2']['params']);
        $this->assertSame('Sample agreement, part C, page 41', $accountsFlags["T2:retention:{$term->id}"]['params']['source']);
        $this->assertSame('Site office rent', $forAccounts->json('check.expected.lines.0.name'));
        $this->assertSame('750.00', $forAccounts->json('check.expected.total'));

        $forViewer = $this->actingAs($this->viewer)->getJson(route('bill-checks.show', $bill))->assertOk();
        $viewerFlags = collect($forViewer->json('check.flags'))->keyBy('key');

        // The same findings, with the same keys and levels.
        $this->assertSame($accountsFlags->keys()->all(), $viewerFlags->keys()->all());
        $this->assertSame($accountsFlags->pluck('level')->all(), $viewerFlags->pluck('level')->all());

        $this->assertSame('The advance still to be recovered has changed since this bill was worked out.', $viewerFlags['B2']['text']);
        $this->assertSame(['stored' => null, 'now' => null], $viewerFlags['B2']['params']);
        $this->assertSame(
            ['head' => 'retention', 'bill' => null, 'term' => null, 'source' => null, 'from' => '2026-04-01'],
            $viewerFlags["T2:retention:{$term->id}"]['params'],
        );
        $this->assertSame('Retention on this bill is not the percentage of the term confirmed for this contract, in force from 01 Apr 2026.', $viewerFlags["T2:retention:{$term->id}"]['text']);
        $this->assertSame(['head' => 'tds', 'pct' => null], $viewerFlags['T1:tds']['params']);
        $this->assertSame(['count' => 1, 'total' => null, 'receipt' => null], $viewerFlags['T3']['params']);

        // Quantities are not money, and stay.
        $previous = $viewerFlags->first(fn (array $flag) => $flag['code'] === 'B1');
        $this->assertSame('25.000', $previous['params']['stored_qty']);
        $this->assertNull($previous['params']['stored_amount']);
        $this->assertNull($previous['params']['now_amount']);

        $this->assertNull($forViewer->json('check.flags_hash'));
        $this->assertSame(['party' => 'client', 'count' => 1, 'lines' => null, 'unpriced' => null, 'total' => null, 'net_payable' => null, 'expected_receipt' => null], $forViewer->json('check.expected'));

        $body = $forViewer->getContent();

        foreach (['₹', '35200', '35,200', '40000', '7.25', '6.5', '750', 'page 41', 'Site office rent', '₹'] as $private) {
            $this->assertStringNotContainsString($private, $body, "A viewer received {$private}.");
        }
    }

    public function test_acknowledging_a_finding(): void
    {
        $check = $this->check($this->bill, $this->pm);
        $url = route('bill-checks.acks.store', $check);

        foreach ([$this->accounts, $this->engineer, $this->viewer] as $user) {
            $this->actingAs($user)->post($url, ['flag_key' => 'T1:tds', 'remark' => 'Seen by me'])->assertForbidden();
        }

        $this->actingAs($this->qs);

        // A remark is required, and four characters are not one.
        $this->post($url, ['flag_key' => 'T1:tds'])->assertSessionHasErrors('remark');
        $this->post($url, ['flag_key' => 'T1:tds', 'remark' => 'ok  '])->assertSessionHasErrors(['remark' => 'Write a remark of at least 5 characters.']);
        $this->post($url, ['remark' => 'Seen by me'])->assertSessionHasErrors('flag_key');
        $this->post($url, ['flag_key' => 'T1:tds', 'remark' => str_repeat('x', 501)])->assertSessionHasErrors('remark');
        $this->assertSame(0, BillCheckAck::withoutGlobalScopes()->count());

        $this->from(route('ra-bills.show', $this->bill))->post($url, ['flag_key' => 'T1:tds', 'remark' => 'As per the contract form'])
            ->assertRedirect(route('ra-bills.show', $this->bill))->assertSessionHasNoErrors()->assertSessionHas('success', 'Finding acknowledged.');

        $ack = BillCheckAck::withoutGlobalScopes()->sole();
        $this->assertSame([$this->org->id, $this->project->id, $check->id, 'T1:tds', 'As per the contract form', $this->qs->id], [$ack->organization_id, $ack->project_id, $ack->bill_check_id, $ack->flag_key, $ack->remark, $ack->user_id]);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'bill_check.acknowledged')->count());

        // Pressing twice, or a second person acknowledging the same finding, keeps the first acknowledgement.
        $this->actingAs($this->pm)->postJson($url, ['flag_key' => 'T1:tds', 'remark' => 'Another remark'])->assertOk()->assertJsonPath('remark', 'As per the contract form')->assertJsonPath('user_id', $this->qs->id);
        $this->assertSame(1, BillCheckAck::withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'bill_check.acknowledged')->count());

        $this->actingAs($this->pm)->postJson($url, ['flag_key' => 'T1:gst', 'remark' => 'Agreed on site'])->assertCreated()->assertJsonPath('flag_key', 'T1:gst');
    }

    public function test_someone_who_may_not_see_money_gets_back_no_remark_of_another_person(): void
    {
        $check = $this->check($this->bill, $this->pm);
        $url = route('bill-checks.acks.store', $check);
        // Made up for the test: a remark with an amount in it, as people write them.
        $remark = 'Client agreed, Rs 4,12,500 held back';

        $this->actingAs($this->qs)->postJson($url, ['flag_key' => 'T1:tds', 'remark' => $remark])->assertCreated()->assertJsonPath('remark', $remark);

        // A custom role that makes bills but sees no money: the card leaves the remark out, and so does acknowledging again.
        $clerk = $this->holding(['project.view', 'ra_bill.view', 'ra_bill.generate']);
        $this->actingAs($clerk);
        $card = $this->getJson(route('bill-checks.show', $this->bill))->assertOk();
        $this->assertNull(collect($card->json('check.flags'))->firstWhere('key', 'T1:tds')['ack']['remark']);

        $again = $this->postJson($url, ['flag_key' => 'T1:tds', 'remark' => 'Anything at all'])->assertOk()
            ->assertJsonPath('flag_key', 'T1:tds')->assertJsonPath('remark', null)->assertJsonPath('user_id', $this->qs->id);
        $this->assertStringNotContainsString('4,12,500', $again->getContent());
        $this->assertSame(1, BillCheckAck::withoutGlobalScopes()->where('flag_key', 'T1:tds')->count());

        // What they write themselves comes back to them.
        $this->postJson($url, ['flag_key' => 'T1:gst', 'remark' => 'Seen on site'])->assertCreated()->assertJsonPath('remark', 'Seen on site');
        $this->postJson($url, ['flag_key' => 'T1:gst', 'remark' => 'Pressed twice'])->assertOk()->assertJsonPath('remark', 'Seen on site');

        // Someone who may see money reads every remark.
        $this->actingAs($this->pm)->postJson($url, ['flag_key' => 'T1:tds', 'remark' => 'Another remark'])->assertOk()->assertJsonPath('remark', $remark);
    }

    public function test_only_a_finding_of_that_check_that_needs_it_can_be_acknowledged(): void
    {
        // Three measurements with no photo give a note beside the four findings to tick.
        app(RaBillService::class)->cancel($this->bill);
        $this->measure('4', ['location' => 'Lift pit'], photo: false);
        $bill = $this->clientBill();
        $check = $this->check($bill, $this->pm);
        $this->assertContains('D6', array_column($check->flags, 'key'));

        [, , , $rivalCheck] = $this->rival();
        $rivalCheck->update(['flags' => [['key' => 'A2', 'code' => 'A2', 'level' => 'blocking', 'params' => ['stored' => '1.00', 'expected' => '2.00']]]]);

        $this->actingAs($this->pm);
        $url = route('bill-checks.acks.store', $check);

        $this->post($url, ['flag_key' => 'B3:999', 'remark' => 'Not in this check'])->assertSessionHasErrors(['flag_key' => 'This finding is not part of this check.']);
        $this->postJson($url, ['flag_key' => 'A2', 'remark' => 'A key of another check'])->assertUnprocessable()->assertJsonValidationErrors('flag_key');
        $this->post($url, ['flag_key' => 'D6', 'remark' => 'No photos taken'])->assertSessionHasErrors(['flag_key' => 'This finding is for information and needs no acknowledgement.']);

        $this->assertSame(0, BillCheckAck::withoutGlobalScopes()->count());
    }

    public function test_only_the_newest_check_of_a_bill_that_can_still_be_submitted_takes_acknowledgements(): void
    {
        $old = $this->check($this->bill, $this->pm);
        $this->term();
        $new = $this->check($this->bill, $this->pm);
        $this->assertNotSame($old->id, $new->id);
        $this->actingAs($this->pm);

        $this->post(route('bill-checks.acks.store', $old), ['flag_key' => 'T1:tds', 'remark' => 'On the old check'])
            ->assertSessionHasErrors(['flag_key' => 'This bill has been checked again since. Acknowledge the findings of the newest check.']);

        $this->post(route('bill-checks.acks.store', $new), ['flag_key' => 'T1:tds', 'remark' => 'On the new check'])->assertSessionHasNoErrors();

        app(ApprovalService::class)->submit($this->bill, $this->pm);

        $this->post(route('bill-checks.acks.store', $new), ['flag_key' => 'T1:gst', 'remark' => 'After submitting'])
            ->assertSessionHasErrors(['flag_key' => 'This bill has already been submitted, so its check can no longer be acknowledged.']);

        $this->assertSame(['T1:tds'], BillCheckAck::withoutGlobalScopes()->pluck('flag_key')->all());
    }

    public function test_another_company_cannot_reach_a_bill_or_a_check(): void
    {
        $check = $this->check($this->bill, $this->pm);
        [, $rivalOwner, $rivalBill, $rivalCheck] = $this->rival();

        $this->actingAs($rivalOwner);
        $this->post(route('bill-checks.run', $this->bill))->assertNotFound();
        $this->getJson(route('bill-checks.show', $this->bill))->assertNotFound();
        $this->post(route('bill-checks.acks.store', $check), ['flag_key' => 'T1:tds', 'remark' => 'From outside'])->assertNotFound();

        $this->actingAs($this->owner);
        $this->post(route('bill-checks.run', $rivalBill))->assertNotFound();
        $this->getJson(route('bill-checks.show', $rivalBill))->assertNotFound();
        $this->post(route('bill-checks.acks.store', $rivalCheck), ['flag_key' => 'T1:tds', 'remark' => 'From outside'])->assertNotFound();

        $this->assertSame(0, BillCheckAck::withoutGlobalScopes()->count());
        $this->assertSame(2, BillCheck::withoutGlobalScopes()->count());

        // Each company reads its own.
        $this->getJson(route('bill-checks.show', $this->bill))->assertOk()->assertJsonPath('check.id', $check->id);
        $this->actingAs($rivalOwner)->getJson(route('bill-checks.show', $rivalBill))->assertOk()->assertJsonPath('check.id', $rivalCheck->id);
    }

    public function test_a_person_limited_to_other_projects_cannot_reach_a_bill_or_a_check(): void
    {
        $check = $this->check($this->bill, $this->pm);
        $limited = $this->limitedElsewhere();

        $this->actingAs($limited);
        $this->post(route('bill-checks.run', $this->bill))->assertNotFound();
        $this->getJson(route('bill-checks.show', $this->bill))->assertNotFound();
        $this->post(route('bill-checks.acks.store', $check), ['flag_key' => 'T1:tds', 'remark' => 'From another project'])->assertNotFound();

        $this->assertSame(0, BillCheck::count(), 'The check is out of reach in every list as well.');
        $this->assertSame(0, BillCheckAck::withoutGlobalScopes()->count());

        // The same person, given the project, reaches both.
        app(TeamAccessService::class)->applyProjects($limited, true, [$this->project->id]);
        $this->actingAs($limited->refresh())->getJson(route('bill-checks.show', $this->bill))->assertOk()->assertJsonPath('check.id', $check->id);
        $this->post(route('bill-checks.acks.store', $check), ['flag_key' => 'T1:tds', 'remark' => 'Now on this project'])->assertSessionHasNoErrors();
    }

    public function test_the_routes_need_the_billing_plan(): void
    {
        $check = $this->check($this->bill, $this->pm);
        $this->org->update(['plan' => 'free']);

        $this->actingAs($this->owner);
        $this->post(route('bill-checks.run', $this->bill))->assertForbidden();
        $this->getJson(route('bill-checks.show', $this->bill))->assertForbidden();
        $this->post(route('bill-checks.acks.store', $check), ['flag_key' => 'T1:tds', 'remark' => 'On the free plan'])->assertForbidden();
        $this->patch(route('bill-checks.policy'), ['required' => 1])->assertForbidden();
    }

    public function test_only_an_owner_or_admin_decides_whether_the_check_is_required(): void
    {
        foreach ([$this->pm, $this->qs, $this->accounts, $this->engineer, $this->viewer] as $user) {
            $this->actingAs($user)->patch(route('bill-checks.policy'), ['required' => 1])->assertForbidden();
        }

        // settings.manage reaches across the company, so a person limited to chosen projects never holds it.
        $limited = $this->holding(['settings.manage', 'ra_bill.view', 'ra_bill.generate']);
        app(TeamAccessService::class)->applyProjects($limited, true, [$this->project->id]);
        $this->actingAs($limited->refresh())->patch(route('bill-checks.policy'), ['required' => 1])->assertForbidden();

        $this->assertSame(0, BillCheckPolicy::withoutGlobalScopes()->count());

        $this->actingAs($this->owner);
        $this->patch(route('bill-checks.policy'), [])->assertSessionHasErrors('required');
        $this->patch(route('bill-checks.policy'), ['required' => 'perhaps'])->assertSessionHasErrors('required');

        $this->from(route('ra-bills.show', $this->bill))->patch(route('bill-checks.policy'), ['required' => 1])
            ->assertRedirect(route('ra-bills.show', $this->bill))
            ->assertSessionHas('success', 'From now on a bill can be submitted only after every finding of its check is acknowledged.');
        $this->assertTrue(app(BillChecks::class)->isRequired($this->org->id));

        $admin = $this->member($this->org, 'admin');
        $this->actingAs($admin)->patchJson(route('bill-checks.policy'), ['required' => false])->assertOk()->assertExactJson(['required' => false]);
        $this->assertFalse(app(BillChecks::class)->isRequired($this->org->id));

        $policy = BillCheckPolicy::withoutGlobalScopes()->sole();
        $this->assertSame([$this->org->id, false, $admin->id], [$policy->organization_id, $policy->required, $policy->updated_by]);

        // Created by the owner, then changed by the admin: both are in the audit log.
        $this->assertSame(['created', 'updated'], AuditLog::withoutGlobalScopes()->where('auditable_type', $policy->getMorphClass())->orderBy('id')->pluck('action')->all());
    }

    public function test_one_company_s_choice_is_not_another_s(): void
    {
        [$rivalOrg, $rivalOwner] = $this->rival();

        $this->actingAs($rivalOwner)->patch(route('bill-checks.policy'), ['required' => 1])->assertSessionHasNoErrors();

        $this->assertTrue(app(BillChecks::class)->isRequired($rivalOrg->id));
        $this->assertFalse(app(BillChecks::class)->isRequired($this->org->id));
        $this->actingAs($this->owner)->getJson(route('bill-checks.show', $this->bill))->assertJsonPath('required', false);
        $this->assertSame(0, BillCheckPolicy::count(), 'Signed in as the first company, the other company\'s row is not visible.');
    }

    public function test_the_card_on_the_bill_page(): void
    {
        $page = route('ra-bills.show', $this->bill);

        // Not checked yet: the button for those who may check, the two plain statements for everyone.
        $this->actingAs($this->pm)->get($page)->assertOk()
            ->assertSee('Bill pre-check')->assertSee('This bill has not been checked yet.')->assertSee('Check this bill')
            ->assertSee(route('bill-checks.run', $this->bill), false)
            ->assertSee('An empty list means these checks found nothing. It does not mean the bill will be passed.')
            ->assertSee('The percentages are the ones entered for this contract on the dates shown.')
            ->assertSee('Every rupee in the check is worked out by the app from your records and the percentages entered for this contract.')
            ->assertDontSee('Require it before submitting');

        $check = $this->check($this->bill, $this->pm);
        app(BillChecks::class)->acknowledge($check, 'T1:tds', 'As per the contract form', $this->qs);

        $this->actingAs($this->pm)->get($page)->assertOk()
            ->assertSee('Check again')
            ->assertSee('Checked on 20 Dec 2026, 11:00 by '.$this->pm->name)
            ->assertSee('Findings still to be acknowledged: 3')
            ->assertSee('Retention 6.5%: this percentage is the one typed on the contract form. Nobody has confirmed it against the agreement.')
            ->assertSee('Acknowledged by '.$this->qs->name.' on 20 Dec 2026')->assertSee('As per the contract form')
            ->assertSee(route('bill-checks.acks.store', $check), false)->assertSee('Your remark on this finding')
            ->assertSee('No other deduction heads are entered for this contract.');

        // Accounts reads the same card with the figures, and has nothing to press.
        $this->actingAs($this->accounts)->get($page)->assertOk()
            ->assertSee('Retention 6.5%: this percentage')->assertSee('Not acknowledged')
            ->assertDontSee('Check again')->assertDontSee(route('bill-checks.acks.store', $check), false)->assertDontSee('Your remark on this finding');

        // A viewer reads it without any percentage.
        $this->actingAs($this->viewer)->get($page)->assertOk()
            ->assertSee('Retention: this percentage is the one typed on the contract form. Nobody has confirmed it against the agreement.')
            ->assertDontSee('6.5%')->assertDontSee('Every rupee in the check')->assertDontSee('Check again');

        // The owner also decides whether the check is required.
        $this->actingAs($this->owner)->get($page)->assertOk()
            ->assertSee('This check is optional in your company. A bill can be submitted without it.')->assertSee('Require it before submitting')
            ->assertSee(route('bill-checks.policy'), false);

        $this->requireCheck();
        $this->actingAs($this->owner)->get($page)->assertOk()->assertSee('Make it optional');
        $this->actingAs($this->pm)->get($page)->assertOk()
            ->assertSee('Your company requires this check: a bill can be submitted only after every finding is acknowledged.')->assertDontSee('Make it optional');
    }

    public function test_a_bill_that_can_no_longer_be_checked_and_never_was_has_no_card_and_no_switch(): void
    {
        $page = route('ra-bills.show', $this->bill);
        $card = ['Bill pre-check', 'This bill has not been checked yet.', 'Check this bill', 'Require it before submitting', 'Make it optional', route('bill-checks.policy')];
        $seesCard = fn (string $content) => array_values(array_filter($card, fn (string $text) => str_contains($content, $text)));

        // A draft can still be checked: the card and, for the owner, the company's switch are there.
        $this->assertNotSame([], $seesCard((string) $this->actingAs($this->owner)->get($page)->assertOk()->getContent()));
        $this->assertSame(0, BillCheck::withoutGlobalScopes()->count(), 'Opening the page checks nothing.');

        // Submitted, approved and paid without a check: no card at all, so no "not checked yet" with nothing to press, and no switch.
        $approval = app(ApprovalService::class)->submit($this->bill, $this->owner);
        $this->assertSame('IN_REVIEW', $this->bill->refresh()->status);

        $approve = function () use (&$approval) {
            while ($approval && $approval->status === 'IN_REVIEW') {
                $approval = app(ApprovalService::class)->act($approval, $this->owner, 'approve');
            }

            $this->assertSame('APPROVED', $this->bill->refresh()->status);
        };

        foreach (['submitted' => fn () => null, 'approved' => $approve, 'paid' => fn () => app(RaBillService::class)->markPaid($this->bill->refresh())] as $state => $step) {
            $step();

            foreach ([$this->owner, $this->pm, $this->accounts, $this->viewer] as $reader) {
                $this->assertSame([], $seesCard((string) $this->actingAs($reader)->get($page)->assertOk()->getContent()), "{$state}, read by {$reader->role}");
            }
        }

        $this->assertSame('PAID', $this->bill->refresh()->status);
        $this->assertNull(app(CheckPresenter::class)->card($this->bill, $this->owner));

        // The switch stays off by default and changes nothing while off: the bill went through its chain unchecked.
        $this->assertFalse(app(BillChecks::class)->isRequired($this->org->id));
        $this->assertSame(0, BillCheck::withoutGlobalScopes()->count());

        // The JSON for the card is as it was.
        $this->actingAs($this->owner)->getJson(route('bill-checks.show', $this->bill))->assertOk()
            ->assertExactJson(['check' => null, 'required' => false, 'can' => ['run' => false, 'acknowledge' => false, 'set_policy' => true]]);
    }

    public function test_a_bill_checked_as_a_draft_keeps_its_card_after_it_is_submitted(): void
    {
        $this->check($this->bill, $this->pm);
        app(ApprovalService::class)->submit($this->bill, $this->owner);
        $this->assertSame('IN_REVIEW', $this->bill->refresh()->status);

        // The findings stay readable, with nothing to press but the owner's switch.
        $this->actingAs($this->owner)->get(route('ra-bills.show', $this->bill))->assertOk()
            ->assertSee('Bill pre-check')->assertSee('Checked on')->assertDontSee('This bill has not been checked yet.')
            ->assertDontSee('Check again')->assertDontSee('Check this bill')
            ->assertSee('Require it before submitting');
        $this->actingAs($this->viewer)->get(route('ra-bills.show', $this->bill))->assertOk()->assertSee('Bill pre-check')->assertDontSee('Require it before submitting');
        $this->assertNotNull(app(CheckPresenter::class)->card($this->bill, $this->pm));
    }

    public function test_the_card_shows_the_sheet_of_other_deductions_only_with_money(): void
    {
        $this->confirmFormPercentages();
        $this->term(['code' => 'labour_cess', 'label' => 'Labour cess', 'pct' => '1.2', 'base' => 'gross', 'cap_amount' => '5000', 'source_ref' => 'Sample agreement, part D']);
        $this->term(['code' => 'insurance', 'label' => 'Insurance', 'method' => 'typed_each_bill', 'pct' => null, 'base' => null]);
        $this->check($this->bill, $this->pm);
        $page = route('ra-bills.show', $this->bill);

        $this->actingAs($this->accounts)->get($page)->assertOk()
            ->assertSee('Deductions your client is expected to make that are not on this bill')
            ->assertSee('Labour cess')->assertSee('1.2% of ₹48,000.00')->assertSee('₹576.00')
            ->assertSee('Not more than ₹5,000.00 in all')->assertSee('Counted on earlier bills: ₹0.00')
            ->assertSee('Sample agreement, part D')->assertSee('From 01 Apr 2026')
            ->assertSee('Insurance')->assertSee('No figure')->assertSee('Worked out on each bill')
            ->assertSee('Total of these')->assertSee('Expected receipt')->assertSee('₹45,624.00')
            ->assertSee('Without a figure here, because the amount is worked out on each bill: 1.')
            ->assertSee('Other deductions entered for this contract that are not on this bill: 2. Those with a figure come to ₹576.00, which leaves an expected receipt of ₹45,624.00.');

        $this->actingAs($this->viewer)->get($page)->assertOk()
            ->assertSee('Other deductions entered for this contract that are not on this bill: 2.')
            ->assertDontSee('Deductions your client is expected to make')->assertDontSee('Labour cess')->assertDontSee('Sample agreement')
            ->assertDontSee('576')->assertDontSee('45,624')->assertDontSee('Expected receipt');
    }

    public function test_the_card_is_not_on_the_client_s_sign_off_page(): void
    {
        $this->check($this->bill, $this->pm);
        ApprovalRule::withoutGlobalScopes()->updateOrCreate(['organization_id' => $this->org->id, 'doc_type' => 'ra_bill'], ['levels' => ['client'], 'active' => true]);
        app(ApprovalService::class)->submit($this->bill, $this->pm);
        auth()->logout();

        $this->get(URL::temporarySignedRoute('client.bill', now()->addDay(), ['bill' => $this->bill->id]))->assertOk()
            ->assertSee($this->bill->number)
            ->assertDontSee('Bill pre-check')->assertDontSee('this percentage is the one typed')->assertDontSee('Nobody has confirmed');
    }
}
