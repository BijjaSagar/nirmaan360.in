<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AgentCreditLedger;
use App\Models\AgentRun;
use App\Models\Organization;
use App\Models\User;
use Tests\Feature\DocumentReading\Support\BringsTextCalls;

/** What a text call holds back while it waits, and what it is charged when it is done. */
class TextCallCreditsTest extends ReadingTestCase
{
    use BringsTextCalls;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bringTextCalls();
        $this->release();
        $this->organization = $this->tenant('Sahyadri Builders');
        $this->owner = $this->member($this->organization, 'owner');
    }

    public function test_a_waiting_or_running_text_call_holds_its_maximum(): void
    {
        $this->textCall($this->organization, $this->owner, ['status' => 'queued', 'max_credits' => '3']);
        $this->textCall($this->organization, $this->owner, ['status' => 'running', 'max_credits' => '2.5']);

        // Finished ones hold nothing, however they ended. Nor does another company's call.
        foreach (['done', 'failed', 'rejected'] as $status) {
            $this->textCall($this->organization, $this->owner, ['status' => $status, 'max_credits' => '9']);
        }

        $other = $this->tenant('Konkan Infra');
        $this->textCall($other, null, ['status' => 'queued', 'max_credits' => '7']);

        $this->assertSame('5.5000', $this->credits()->held($this->organization));
        $this->assertSame('494.5000', $this->credits()->free($this->organization));
        $this->assertSame('7.0000', $this->credits()->held($other));

        // A read's hold and a text call's maximum add up.
        $read = $this->makeRead($this->organization, $this->owner);
        $this->credits()->hold($read, '494.5', $this->owner);

        $this->assertSame('500.0000', $this->credits()->held($this->organization));
        $this->assertFalse($this->credits()->hasCredits($this->organization));

        // An agent step is charged nothing while everything is spoken for.
        $run = (new AgentRun)->forceFill(['organization_id' => $this->organization->id, 'max_credits' => '10', 'credits_used' => '0']);
        $this->assertSame('0.0000', $this->credits()->charge($run, '1'));
    }

    public function test_a_finished_text_call_is_charged_and_the_ledger_names_it(): void
    {
        $call = $this->textCall($this->organization, $this->owner, ['max_credits' => '3']);

        $this->assertSame('0.4212', $this->credits()->chargeCall($call, '0.42116'));

        $this->assertSame('499.5788', $this->wallet($this->organization)->available());
        $this->assertSame('0.4212', $call->credits);
        $this->assertSame('0.4212', $call->fresh()->credits);

        $line = AgentCreditLedger::withoutGlobalScopes()->where('type', 'usage')->sole();
        $this->assertSame('-0.4212', $line->credits);
        $this->assertSame('499.5788', $line->balance_after);
        $this->assertSame($call->id, $line->ai_text_call_id);
        $this->assertSame("tc:{$call->id}:1", $line->reference);
        $this->assertNull($line->document_read_id);
        $this->assertLedgerMatchesWallet($this->organization);
    }

    public function test_an_attempt_delivered_twice_is_charged_once_and_a_second_attempt_stays_inside_the_maximum(): void
    {
        $call = $this->textCall($this->organization, $this->owner, ['max_credits' => '3']);

        $this->assertSame('2.0000', $this->credits()->chargeCall($call, '2'));
        $this->assertSame('2.0000', $this->credits()->chargeCall($call->fresh(), '2.9'));
        $this->assertSame(1, AgentCreditLedger::withoutGlobalScopes()->where('type', 'usage')->count());
        $this->assertSame('498.0000', $this->wallet($this->organization)->available());

        // The corrective call is a second attempt on the same row: together they never pass the maximum.
        $call->newQueryWithoutScopes()->whereKey($call->id)->update(['attempts' => 2]);
        $this->assertSame('1.0000', $this->credits()->chargeCall($call->fresh(), '2'));
        $this->assertSame('3.0000', $call->fresh()->credits);
        $this->assertSame('497.0000', $this->wallet($this->organization)->available());

        $call->newQueryWithoutScopes()->whereKey($call->id)->update(['attempts' => 3]);
        $this->assertSame('0.0000', $this->credits()->chargeCall($call->fresh(), '2'));
        $this->assertSame(2, AgentCreditLedger::withoutGlobalScopes()->where('type', 'usage')->count());
        $this->assertLedgerMatchesWallet($this->organization);
    }

    public function test_a_text_call_is_never_charged_more_than_the_wallet_has(): void
    {
        $call = $this->textCall($this->organization, $this->owner, ['max_credits' => '3']);
        $this->setCredits($this->organization, '0.2500', '0.1000');

        $this->assertSame('0.3500', $this->credits()->chargeCall($call, '2'));

        $wallet = $this->wallet($this->organization);
        $this->assertSame('0.0000', $wallet->included_balance);
        $this->assertSame('0.0000', $wallet->purchased_balance);
        $this->assertSame('0.3500', $call->fresh()->credits);
    }

    public function test_a_text_call_is_charged_to_its_own_company(): void
    {
        $other = $this->tenant('Konkan Infra');
        $call = $this->textCall($other, null, ['max_credits' => '3']);

        $this->credits()->chargeCall($call, '1.5');

        $this->assertSame('500.0000', $this->wallet($this->organization)->available());
        $this->assertSame('498.5000', $this->wallet($other)->available());
        $this->assertSame($other->id, AgentCreditLedger::withoutGlobalScopes()->where('type', 'usage')->sole()->organization_id);
    }
}
