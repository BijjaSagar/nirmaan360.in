<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AgentCreditLedger;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentReading\InsufficientCredits;
use App\Services\DocumentReading\SpendRefused;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Holds, charges and burns for document reads, with exact decimals on every balance.
 * A Business company starts each test with the 500 credits its plan includes.
 */
class ReadCreditsTest extends ReadingTestCase
{
    private Organization $organization;

    private User $owner;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->release();
        $this->organization = $this->tenant('Sahyadri Builders');
        $this->owner = $this->member($this->organization, 'owner');
        $this->engineer = $this->member($this->organization, 'engineer');
    }

    private function heldRead(string $credits, ?User $by = null): DocumentRead
    {
        $read = $this->makeRead($this->organization, $by ?? $this->owner);
        $this->credits()->hold($read, $credits, $by ?? $this->owner);

        return $read;
    }

    public function test_nothing_is_on_hold_until_a_read_runs(): void
    {
        $this->makeRead($this->organization, $this->owner, ['credits_held' => '40']);
        $this->makeRead($this->organization, $this->owner, ['status' => DocumentRead::PAUSED, 'credits_held' => '40']);

        $this->assertSame('0.0000', $this->credits()->held($this->organization));
        $this->assertSame('500.0000', $this->credits()->free($this->organization));
        $this->assertTrue($this->credits()->hasCredits($this->organization));
    }

    public function test_a_hold_marks_the_read_running_and_keeps_its_credits_from_everything_else(): void
    {
        $read = $this->makeRead($this->organization, $this->owner, ['status' => DocumentRead::PAUSED, 'pause_reason' => 'infra']);

        $this->credits()->hold($read, '120', $this->owner);

        // The caller's own copy shows it, and so does the row.
        foreach ([$read, $read->fresh()] as $copy) {
            $this->assertSame(DocumentRead::RUNNING, $copy->status);
            $this->assertSame('120.0000', $copy->credits_held);
            $this->assertNull($copy->pause_reason);
        }

        $this->assertSame('120.0000', $this->credits()->held($this->organization));
        $this->assertSame('380.0000', $this->credits()->free($this->organization));

        // Held credits have not left the wallet, and nothing was written to the ledger.
        $this->assertSame('500.0000', $this->wallet($this->organization)->available());
        $this->assertSame([], $this->usageLines($this->organization));

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'ai.read_hold')->sole();
        $this->assertSame($this->organization->id, $audit->organization_id);
        $this->assertSame($this->owner->id, $audit->user_id);
        $this->assertSame($read->id, $audit->auditable_id);
        $this->assertSame(['status' => 'running', 'credits_held' => '120.0000'], $audit->new_values);
    }

    public function test_a_hold_larger_than_the_free_balance_is_refused_and_changes_nothing(): void
    {
        $this->heldRead('200');
        $read = $this->makeRead($this->organization, $this->owner);

        try {
            $this->credits()->hold($read, '300.0001', $this->owner);
            $this->fail('The hold went through.');
        } catch (InsufficientCredits $refused) {
            $this->assertSame(402, $refused->status);
            $this->assertSame(['code' => 'no_credits', 'limit' => 'wallet'], $refused->toArray());
            $this->assertInstanceOf(SpendRefused::class, $refused);
        }

        $this->assertSame(DocumentRead::QUOTED, $read->fresh()->status);
        $this->assertSame('0.0000', $read->fresh()->credits_held);
        $this->assertSame('200.0000', $this->credits()->held($this->organization));

        // Exactly what is free can be held.
        $this->credits()->hold($read, '300', $this->owner);
        $this->assertSame('0.0000', $this->credits()->free($this->organization));
        $this->assertFalse($this->credits()->hasCredits($this->organization));
    }

    public function test_only_an_owner_or_admin_may_put_more_than_half_the_wallet_on_hold(): void
    {
        $this->assertSame('250.0000', $this->credits()->room($this->organization, $this->engineer));
        $this->assertSame('500.0000', $this->credits()->room($this->organization, $this->owner));

        $first = $this->makeRead($this->organization, $this->engineer);

        try {
            $this->credits()->hold($first, '250.0001', $this->engineer);
            $this->fail('The hold went through.');
        } catch (InsufficientCredits $refused) {
            $this->assertSame(['code' => 'no_credits', 'limit' => 'hold_share'], $refused->toArray());
        }

        $this->credits()->hold($first, '100', $this->engineer);

        // The share covers every open hold of the company, whoever took it.
        $pm = $this->member($this->organization, 'pm');
        $second = $this->makeRead($this->organization, $pm);
        $this->assertSame('150.0000', $this->credits()->room($this->organization, $pm));

        try {
            $this->credits()->hold($second, '150.0001', $pm);
            $this->fail('The hold went through.');
        } catch (InsufficientCredits $refused) {
            $this->assertSame('hold_share', $refused->limit);
        }

        $this->credits()->hold($second, '150', $pm);

        // An admin is not bound by the share, only by what is free.
        $admin = $this->member($this->organization, 'admin');
        $third = $this->makeRead($this->organization, $admin);
        $this->assertSame('250.0000', $this->credits()->room($this->organization, $admin));
        $this->credits()->hold($third, '250', $admin);

        $this->assertSame('500.0000', $this->credits()->held($this->organization));
    }

    public function test_holding_again_replaces_the_earlier_hold_instead_of_adding_to_it(): void
    {
        $read = $this->heldRead('100');

        $this->credits()->hold($read, '60', $this->owner);
        $this->assertSame('60.0000', $this->credits()->held($this->organization));

        // Its own hold is not in its way: the whole wallet can be held by this one read.
        $this->assertSame('500.0000', $this->credits()->room($this->organization, $this->owner, $read));
        $this->credits()->hold($read, '500', $this->owner);
        $this->assertSame('500.0000', $read->fresh()->credits_held);
    }

    public function test_a_closed_read_holds_nothing_and_nobody_holds_for_another_company(): void
    {
        foreach (DocumentRead::CLOSED as $status) {
            $read = $this->makeRead($this->organization, $this->owner, ['status' => $status]);

            try {
                $this->credits()->hold($read, '10', $this->owner);
                $this->fail("A {$status} read took a hold.");
            } catch (LogicException) {
                $this->assertSame($status, $read->fresh()->status);
            }
        }

        $outsider = $this->member($this->tenant('Konkan Infra'), 'owner');

        try {
            $this->credits()->hold($this->makeRead($this->organization, $this->owner), '10', $outsider);
            $this->fail('Someone of another company took a hold.');
        } catch (LogicException) {
            $this->assertSame('0.0000', $this->credits()->held($this->organization));
        }

        $this->expectException(InvalidArgumentException::class);
        $this->credits()->hold($this->makeRead($this->organization, $this->owner), '0', $this->owner);
    }

    public function test_a_page_that_was_read_is_charged_and_the_ledger_names_the_read(): void
    {
        $read = $this->heldRead('100');
        $page = $this->answeredPage($read, 1, '0.056912');

        $charged = $this->credits()->chargeRead($read, $page, '6.6588');

        $this->assertSame('6.6588', $charged);
        $this->assertSame('493.3412', $this->wallet($this->organization)->included_balance);

        foreach ([$read, $read->fresh()] as $copy) {
            $this->assertSame(DocumentRead::RUNNING, $copy->status);
            $this->assertSame('93.3412', $copy->credits_held);
            $this->assertSame('6.6588', $copy->credits_used);
            $this->assertSame('0.0000', $copy->credits_burned);
            $this->assertSame('0.000000', $copy->absorbed_usd);
            $this->assertNotNull($copy->last_progress_at);
        }

        $this->assertSame('6.6588', $page->credits);
        $this->assertSame('6.6588', $page->fresh()->credits);

        $line = $this->usageLines($this->organization)[0];
        $this->assertCount(1, $this->usageLines($this->organization));
        $this->assertSame('-6.6588', $line->credits);
        $this->assertSame('493.3412', $line->balance_after);
        $this->assertSame($read->id, $line->document_read_id);
        $this->assertSame("dr:{$read->id}:{$page->id}:1", $line->reference);
        $this->assertNull($line->agent_run_id);

        // What is free is the wallet less what the read still holds.
        $this->assertSame('400.0000', $this->credits()->free($this->organization));
        $this->assertLedgerMatchesWallet($this->organization);
    }

    public function test_included_credits_are_used_before_bought_ones(): void
    {
        $this->setCredits($this->organization, '2.0000', '50.0000');
        $read = $this->heldRead('20');

        $this->assertSame('5.0000', $this->credits()->chargeRead($read, $this->answeredPage($read), '5'));

        $wallet = $this->wallet($this->organization);
        $this->assertSame('0.0000', $wallet->included_balance);
        $this->assertSame('47.0000', $wallet->purchased_balance);
    }

    public function test_a_fraction_of_a_credit_is_rounded_up_never_given_away(): void
    {
        $read = $this->heldRead('20');

        $this->assertSame('0.1400', $this->credits()->chargeRead($read, $this->answeredPage($read), '0.13993'));
        $this->assertSame('499.8600', $this->wallet($this->organization)->available());
    }

    public function test_an_attempt_delivered_twice_is_charged_once(): void
    {
        $read = $this->heldRead('100');
        $page = $this->answeredPage($read, 1, '0.056912');

        $first = $this->credits()->chargeRead($read, $page, '6.6588');
        // A second worker with its own copies of the rows, and a different figure, reports the same attempt.
        $second = $this->credits()->chargeRead($read->fresh(), $page->fresh(), '9.9999');

        $this->assertSame('6.6588', $first);
        $this->assertSame('6.6588', $second);
        $this->assertCount(1, $this->usageLines($this->organization));
        $this->assertSame('493.3412', $this->wallet($this->organization)->available());
        $this->assertSame('6.6588', $read->fresh()->credits_used);
        $this->assertSame('93.3412', $read->fresh()->credits_held);
        $this->assertSame('6.6588', $page->fresh()->credits);

        // Reading the page again is a new attempt and a new charge, and the page adds up both.
        $page->update(['attempts' => 2]);
        $this->assertSame('3.0000', $this->credits()->chargeRead($read, $page, '3'));
        $this->assertCount(2, $this->usageLines($this->organization));
        $this->assertSame("dr:{$read->id}:{$page->id}:2", $this->usageLines($this->organization)[1]->reference);
        $this->assertSame('9.6588', $page->fresh()->credits);
        $this->assertSame('9.6588', $read->fresh()->credits_used);
        $this->assertLedgerMatchesWallet($this->organization);
    }

    public function test_a_charge_written_by_another_worker_a_moment_earlier_stands(): void
    {
        $read = $this->heldRead('100');
        $page = $this->answeredPage($read);
        $reference = "dr:{$read->id}:{$page->id}:1";
        $walletId = $this->wallet($this->organization)->id;
        $slipped = false;

        // The other worker's line lands after this one has looked for it and before it writes its own.
        DB::listen(function (QueryExecuted $query) use (&$slipped, $reference, $walletId, $read) {
            if ($slipped || ! str_starts_with($query->sql, 'select') || ! in_array($reference, $query->bindings, true)) {
                return;
            }

            $slipped = true;

            DB::table('credit_ledger')->insert([
                'organization_id' => $this->organization->id, 'credit_wallet_id' => $walletId, 'type' => 'usage',
                'credits' => '-4.2500', 'balance_after' => '495.7500', 'reference' => $reference, 'document_read_id' => $read->id,
            ]);
        });

        $charged = $this->credits()->chargeRead($read, $page, '6.6588');

        $this->assertTrue($slipped);
        $this->assertSame('4.2500', $charged);
        $this->assertSame(1, AgentCreditLedger::withoutGlobalScopes()->where('reference', $reference)->count());

        // This worker took nothing: the wallet, the read and the page are as the other one left them.
        $this->assertSame('500.0000', $this->wallet($this->organization)->available());
        $this->assertSame('0.0000', $read->fresh()->credits_used);
        $this->assertSame('100.0000', $read->fresh()->credits_held);
        $this->assertSame('0.0000', $page->fresh()->credits);

        // The transaction was not broken by the clash: more work in it still succeeds.
        $this->assertSame('1.0000', $this->credits()->chargeRead($read, $this->answeredPage($read, 2), '1'));
    }

    public function test_a_charge_is_never_more_than_the_read_still_holds(): void
    {
        $read = $this->heldRead('5');
        $page = $this->answeredPage($read, 1, '0.056912');

        $this->assertSame('5.0000', $this->credits()->chargeRead($read, $page, '6.6587'));

        $this->assertSame('495.0000', $this->wallet($this->organization)->available());

        foreach ([$read, $read->fresh()] as $copy) {
            $this->assertSame(DocumentRead::PAUSED, $copy->status);
            $this->assertSame('credit_cap', $copy->pause_reason);
            $this->assertSame('0.0000', $copy->credits_held);
            $this->assertSame('5.0000', $copy->credits_used);
            // What could not be charged counts against the read's limit, and its share of the cost is the platform's.
            $this->assertSame('1.6587', $copy->credits_burned);
            $this->assertSame('0.014177', $copy->absorbed_usd);
        }

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'ai.read_paused')->sole();
        $this->assertSame($this->organization->id, $audit->organization_id);
        $this->assertNull($audit->user_id);
        $this->assertSame(['status' => 'paused', 'pause_reason' => 'credit_cap'], $audit->new_values);
        $this->assertLedgerMatchesWallet($this->organization);
    }

    public function test_a_charge_is_never_more_than_the_wallet_has_and_the_balance_stops_at_zero(): void
    {
        // The hold was taken while the wallet was full; since then the month's included credits have gone.
        $read = $this->runningRead($this->organization, $this->owner, '100');
        $this->setCredits($this->organization, '1.5000', '1.0000');
        $page = $this->answeredPage($read, 1, '0.060000');

        $this->assertSame('2.5000', $this->credits()->chargeRead($read, $page, '6'));

        $wallet = $this->wallet($this->organization);
        $this->assertSame('0.0000', $wallet->included_balance);
        $this->assertSame('0.0000', $wallet->purchased_balance);

        $read->refresh();
        $this->assertSame(DocumentRead::PAUSED, $read->status);
        $this->assertSame('wallet', $read->pause_reason);
        $this->assertSame('0.0000', $read->credits_held);
        $this->assertSame('2.5000', $read->credits_used);
        $this->assertSame('3.5000', $read->credits_burned);
        $this->assertSame('0.035000', $read->absorbed_usd);
        $this->assertSame('-2.5000', $this->usageLines($this->organization)[0]->credits);
        $this->assertSame('0.0000', $this->usageLines($this->organization)[0]->balance_after);

        // With the wallet empty nothing more can be put on hold.
        $this->expectException(InsufficientCredits::class);
        $this->credits()->hold($this->makeRead($this->organization, $this->owner), '0.0001', $this->owner);
    }

    public function test_an_empty_wallet_charges_nothing_and_writes_no_line(): void
    {
        $read = $this->runningRead($this->organization, $this->owner, '100');
        $this->setCredits($this->organization, '0', '0');

        $this->assertSame('0.0000', $this->credits()->chargeRead($read, $this->answeredPage($read, 1, '0.030000'), '3'));

        $this->assertSame([], $this->usageLines($this->organization));
        $this->assertSame('3.0000', $read->fresh()->credits_burned);
        $this->assertSame('0.030000', $read->fresh()->absorbed_usd);
        $this->assertSame('wallet', $read->fresh()->pause_reason);
        $this->assertSame('0.0000', $this->wallet($this->organization)->available());
    }

    public function test_a_hold_used_up_to_the_last_credit_pauses_the_read(): void
    {
        $read = $this->heldRead('5');

        $this->assertSame('5.0000', $this->credits()->chargeRead($read, $this->answeredPage($read), '5'));

        $this->assertSame(DocumentRead::PAUSED, $read->status);
        $this->assertSame('credit_cap', $read->pause_reason);
        $this->assertSame('0.0000', $read->fresh()->credits_burned);
    }

    public function test_a_failed_page_is_not_charged_but_counts_against_the_reads_limit(): void
    {
        $read = $this->heldRead('100');
        $page = $this->makePage($read, 1, ['status' => 'failed', 'fail_reason' => 'cut_off', 'attempts' => 1]);

        $this->credits()->burnRead($read, $page, '11.9238', '0.101912');

        $this->assertSame('500.0000', $this->wallet($this->organization)->available());
        $this->assertSame([], $this->usageLines($this->organization));

        foreach ([$read, $read->fresh()] as $copy) {
            $this->assertSame(DocumentRead::RUNNING, $copy->status);
            $this->assertSame('88.0762', $copy->credits_held);
            $this->assertSame('0.0000', $copy->credits_used);
            $this->assertSame('11.9238', $copy->credits_burned);
            $this->assertSame('0.101912', $copy->absorbed_usd);
        }

        $this->assertSame('0.0000', $page->fresh()->credits);

        // Failures alone can use the limit up. Then the read stops, having cost the company nothing.
        $this->credits()->burnRead($read, $page, '90', '0.769231');

        $read->refresh();
        $this->assertSame(DocumentRead::PAUSED, $read->status);
        $this->assertSame('credit_cap', $read->pause_reason);
        $this->assertSame('0.0000', $read->credits_held);
        $this->assertSame('101.9238', $read->credits_burned);
        $this->assertSame('0.871143', $read->absorbed_usd);
        $this->assertSame('500.0000', $this->credits()->free($this->organization));
    }

    public function test_releasing_gives_back_what_a_read_still_holds_and_leaves_its_status_to_the_caller(): void
    {
        $read = $this->heldRead('100');
        $this->credits()->chargeRead($read, $this->answeredPage($read), '12.5');

        $this->assertSame('87.5000', $this->credits()->releaseRead($read));

        $this->assertSame('0.0000', $read->credits_held);
        $this->assertSame(DocumentRead::RUNNING, $read->fresh()->status);
        $this->assertSame('487.5000', $this->credits()->free($this->organization));
        $this->assertSame('0.0000', $this->credits()->releaseRead($read));
    }

    public function test_a_page_that_comes_back_after_its_read_stopped_is_not_charged(): void
    {
        $read = $this->heldRead('100');
        $page = $this->answeredPage($read, 1, '0.040000');

        $read->update(['status' => DocumentRead::CANCELLED]);
        $this->credits()->releaseRead($read);

        $this->assertSame('0.0000', $this->credits()->chargeRead($read, $page, '4'));

        $this->assertSame('500.0000', $this->wallet($this->organization)->available());
        $this->assertSame(DocumentRead::CANCELLED, $read->fresh()->status);
        $this->assertNull($read->fresh()->pause_reason);
        $this->assertSame('4.0000', $read->fresh()->credits_burned);
        $this->assertSame('0.040000', $read->fresh()->absorbed_usd);

        $this->credits()->burnRead($read, $page, '2', '0.020000');
        $this->assertSame(DocumentRead::CANCELLED, $read->fresh()->status);
        $this->assertSame('6.0000', $read->fresh()->credits_burned);
    }

    public function test_an_agent_cannot_spend_what_a_read_has_on_hold(): void
    {
        $this->heldRead('499.9900');
        $run = (new AgentRun)->forceFill(['organization_id' => $this->organization->id, 'max_credits' => '10', 'credits_used' => '0']);

        $this->assertTrue($this->credits()->hasCredits($this->organization));
        $this->assertSame('0.0100', $this->credits()->charge($run, '0.5000'));
        $this->assertFalse($this->credits()->hasCredits($this->organization));
        $this->assertSame('0.0000', $this->credits()->charge($run, '0.5000'));

        $this->assertSame('499.9900', $this->wallet($this->organization)->available());
        $this->assertLedgerMatchesWallet($this->organization);
    }

    public function test_holds_and_charges_stay_inside_their_own_company(): void
    {
        $other = $this->tenant('Konkan Infra');
        $otherOwner = $this->member($other, 'owner');
        $theirs = $this->makeRead($other, $otherOwner);
        $this->credits()->hold($theirs, '450', $otherOwner);

        $this->assertSame('0.0000', $this->credits()->held($this->organization));
        $this->assertSame('500.0000', $this->credits()->free($this->organization));
        $this->assertSame('50.0000', $this->credits()->free($other));

        $mine = $this->heldRead('100');
        $this->credits()->chargeRead($mine, $this->answeredPage($mine), '7');

        $this->assertSame('500.0000', $this->wallet($other)->available());
        $this->assertSame([], $this->usageLines($other));
        $this->assertSame('450.0000', $theirs->fresh()->credits_held);

        // A page is charged to its own read and to no other.
        $this->expectException(LogicException::class);
        $this->credits()->chargeRead($mine, $this->answeredPage($theirs), '7');
    }

    public function test_the_callers_copy_keeps_what_it_had_not_saved_yet(): void
    {
        $read = $this->heldRead('100');
        $page = $this->answeredPage($read);
        $read->pages_read = 7;
        $page->result = ['rows' => []];

        $this->credits()->chargeRead($read, $page, '2');

        $this->assertSame(['pages_read'], array_keys($read->getDirty()));
        $this->assertSame(['result'], array_keys($page->getDirty()));

        $read->save();
        $this->assertSame(7, $read->fresh()->pages_read);
        $this->assertSame('98.0000', $read->fresh()->credits_held);
        $this->assertSame('2.0000', $read->fresh()->credits_used);
    }
}
