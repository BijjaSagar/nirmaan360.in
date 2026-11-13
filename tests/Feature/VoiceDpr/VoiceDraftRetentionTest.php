<?php

namespace Tests\Feature\VoiceDpr;

use App\Models\DprVoiceDraft;
use App\Models\User;
use App\Services\Access\AccountDeletionService;
use App\Services\DocumentReading\ReadTending;
use App\Services\VoiceDpr\VoiceDraftRetention;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** How long the words of a spoken report are kept, and that they go with the person who spoke them. */
class VoiceDraftRetentionTest extends VoiceDprTestCase
{
    private function stored(User $by, int $daysAgo, bool $linked): DprVoiceDraft
    {
        $draft = DprVoiceDraft::withoutGlobalScopes()->create([
            'organization_id' => $by->organization_id,
            'project_id' => $this->project->id,
            'user_id' => $by->id,
            'client_uuid' => (string) Str::uuid(),
            'report_date' => now()->subDays($daysAgo)->toDateString(),
            'locale' => 'mr-IN',
            'transcript' => self::TRANSCRIPT,
            'speech' => ['engine' => 'android_on_device'],
            'platform' => 'android',
            'app_version' => '1.0.1+20',
            'status' => DprVoiceDraft::READY,
            'proposal' => ['manpower' => [['trade' => 'Mason', 'count' => '6', 'count_spoken' => 'सहा']]],
            'checks' => ['refs' => [$this->pcc->id]],
            'dpr_client_uuid' => $linked ? (string) Str::uuid() : null,
            'changed' => $linked ? ['work.0.qty'] : null,
        ]);

        DB::table('dpr_voice_drafts')->where('id', $draft->id)->update(['created_at' => now()->subDays($daysAgo)]);

        return $draft;
    }

    private function blanked(DprVoiceDraft $draft): bool
    {
        $draft = $draft->fresh();

        return $draft->transcript === null && $draft->proposal === null && $draft->checks === null && $draft->text_purged_at !== null;
    }

    public function test_words_are_blanked_thirty_days_after_unless_a_dpr_was_made_then_ninety(): void
    {
        $recent = $this->stored($this->engineer, 29, false);
        $unlinked = $this->stored($this->engineer, 31, false);
        $linkedRecent = $this->stored($this->engineer, 60, true);
        $linkedOld = $this->stored($this->engineer, 91, true);

        $this->assertSame(2, app(VoiceDraftRetention::class)->purgeOld());

        $this->assertFalse($this->blanked($recent));
        $this->assertTrue($this->blanked($unlinked));
        $this->assertFalse($this->blanked($linkedRecent));
        $this->assertTrue($this->blanked($linkedOld));

        // The row stays, with what is not words: the link and the fields changed by hand.
        $this->assertSame(['work.0.qty'], $linkedOld->fresh()->changed);
        $this->assertSame(0, app(VoiceDraftRetention::class)->purgeOld());

        // The phone is told the words are gone.
        $this->look($this->engineer, $unlinked->id)->assertOk()->assertJson(['transcript' => null, 'proposal' => null, 'text_purged' => true]);
    }

    public function test_the_tending_command_runs_the_retention(): void
    {
        $old = $this->stored($this->engineer, 45, false);

        $this->assertContains(VoiceDraftRetention::class, config('modules.voice_dpr.retention'));
        app(ReadTending::class)->runRetention();

        $this->assertTrue($this->blanked($old));
    }

    public function test_deleting_a_member_blanks_the_words_they_spoke_and_nobody_elses(): void
    {
        $theirs = $this->stored($this->engineer, 1, true);
        $colleague = $this->stored($this->owner, 1, false);

        app(AccountDeletionService::class)->deleteBySupport($this->engineer);

        $this->assertTrue($this->blanked($theirs));
        $this->assertFalse($this->blanked($colleague));

        // An answer that arrives after the deletion is blanked again when the late writes are cleared.
        DB::table('dpr_voice_drafts')->where('id', $theirs->id)->update(['proposal' => json_encode(['summary' => 'late'])]);
        app(AccountDeletionService::class)->scrubLateWrites($this->organization->id, $this->engineer->id);
        $this->assertTrue($this->blanked($theirs));
    }

    public function test_a_queued_report_of_a_deleted_member_is_not_sent(): void
    {
        $id = $this->send($this->engineer)->assertStatus(202)->json('id');
        app(AccountDeletionService::class)->deleteBySupport($this->engineer);

        $this->fakeModel([$this->answer($this->form())]);
        $this->runCall($this->draft($id)->ai_text_call_id);

        $this->assertSame([], $this->sent);
        $this->assertSame(DprVoiceDraft::FAILED, $this->draft($id)->status);
        $this->assertTrue($this->blanked($this->draft($id)));
    }

    public function test_the_migration_rolls_back_and_runs_again(): void
    {
        $migration = require database_path('migrations/2027_01_18_100001_create_dpr_voice_drafts_table.php');
        $this->stored($this->engineer, 1, false);
        $lines = DB::table('boq_items')->count();

        $migration->down();
        $this->assertFalse(Schema::hasTable('dpr_voice_drafts'));
        $this->assertSame($lines, DB::table('boq_items')->count());

        $migration->up();
        $this->assertTrue(Schema::hasColumns('dpr_voice_drafts', [
            'organization_id', 'project_id', 'site_id', 'user_id', 'client_uuid', 'report_date', 'locale', 'transcript', 'speech', 'platform',
            'app_version', 'recorded_at', 'status', 'proposal', 'checks', 'fail_reason', 'ai_text_call_id', 'dpr_client_uuid', 'changed', 'text_purged_at',
        ]));
        $this->send($this->engineer)->assertStatus(202);
    }

    public function test_a_client_uuid_is_kept_once_per_company(): void
    {
        $draft = $this->stored($this->engineer, 1, false);

        // In its own savepoint: on PostgreSQL a failed statement would otherwise abort the test's transaction.
        try {
            DB::transaction(fn () => DprVoiceDraft::withoutGlobalScopes()->create([...$draft->only(['organization_id', 'project_id', 'user_id', 'client_uuid', 'locale', 'platform', 'app_version']), 'report_date' => '2026-11-01']));
            $this->fail('The same client_uuid was saved twice in one company.');
        } catch (UniqueConstraintViolationException) {
            // Kept once.
        }

        $other = $this->tenant('Godavari Infra');
        $otherProject = $this->makeProject($other);
        DprVoiceDraft::withoutGlobalScopes()->create([...$draft->only(['client_uuid', 'locale', 'platform', 'app_version']), 'organization_id' => $other->id, 'project_id' => $otherProject->id, 'report_date' => '2026-11-01']);
        $this->assertSame(2, DprVoiceDraft::withoutGlobalScopes()->where('client_uuid', $draft->client_uuid)->count());
    }
}
