<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AgentCreditLedger;
use App\Models\AiDocumentSettings;
use App\Models\DocumentRead;
use App\Models\DocumentReadAttempt;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

/** The tables behind every reader: who can see a row, what may exist only once, and a migration that goes both ways. */
class ReadingTablesTest extends ReadingTestCase
{
    private const MIGRATION = 'database/migrations/2027_01_10_100001_create_document_reading_tables.php';

    private function ledgerLine(Organization $organization, ?string $reference): AgentCreditLedger
    {
        return AgentCreditLedger::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'credit_wallet_id' => $this->wallet($organization)->id,
            'type' => 'usage',
            'credits' => '-1.0000',
            'balance_after' => '499.0000',
            'reference' => $reference,
        ]);
    }

    public function test_the_migration_rolls_back_and_runs_again(): void
    {
        $organization = $this->tenant();
        $this->ledgerLine($organization, null);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();

        foreach (['document_reads', 'document_read_pages', 'document_read_attempts', 'ai_document_settings'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $this->assertFalse(Schema::hasColumn('credit_ledger', 'reference'));
        $this->assertFalse(Schema::hasColumn('credit_ledger', 'document_read_id'));

        // The ledger itself came through: its rows are still there and it still takes new ones.
        $this->assertSame(2, DB::table('credit_ledger')->where('organization_id', $organization->id)->count());
        DB::table('credit_ledger')->insert(['organization_id' => $organization->id, 'credit_wallet_id' => $this->wallet($organization)->id, 'type' => 'usage', 'credits' => '-1', 'balance_after' => '498']);

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        foreach (['document_reads', 'document_read_pages', 'document_read_attempts', 'ai_document_settings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumn('credit_ledger', 'reference'));
        $this->assertSame(3, DB::table('credit_ledger')->where('organization_id', $organization->id)->whereNull('reference')->count());

        // And the rule that a reference is written once is back.
        $this->ledgerLine($organization, 'dr:1:1:1');
        $this->expectException(UniqueConstraintViolationException::class);
        $this->ledgerLine($organization, 'dr:1:1:1');
    }

    public function test_a_ledger_reference_is_unique_within_a_company_only(): void
    {
        $first = $this->tenant('Sahyadri Builders');
        $second = $this->tenant('Konkan Infra');

        $this->ledgerLine($first, 'dr:7:9:1');
        $this->ledgerLine($second, 'dr:7:9:1');
        $this->ledgerLine($first, null);
        $this->ledgerLine($first, null);

        $this->assertSame(2, AgentCreditLedger::withoutGlobalScopes()->where('reference', 'dr:7:9:1')->count());
    }

    public function test_another_company_cannot_see_a_read_its_pages_or_its_settings(): void
    {
        $mine = $this->tenant('Sahyadri Builders');
        $theirs = $this->tenant('Konkan Infra');
        $owner = $this->member($mine, 'owner');
        $read = $this->makeRead($mine, $owner);
        $page = $this->makePage($read);
        $settings = AiDocumentSettings::for($mine);

        $this->actingAs($this->member($theirs, 'owner'));

        $this->assertNull(DocumentRead::find($read->id));
        $this->assertNull(DocumentReadPage::find($page->id));
        $this->assertNull(AiDocumentSettings::find($settings->id));
        $this->assertSame(0, DocumentRead::count() + DocumentReadPage::count() + AiDocumentSettings::count());

        $this->actingAs($owner);

        $this->assertNotNull(DocumentRead::find($read->id));
        $this->assertNotNull(DocumentReadPage::find($page->id));
        $this->assertNotNull(AiDocumentSettings::find($settings->id));
    }

    public function test_someone_limited_to_other_projects_sees_neither_the_read_nor_its_pages(): void
    {
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $mine = $this->makeProject($organization, ['name' => 'Visible Tower']);
        $hidden = $this->makeProject($organization, ['name' => 'Secret Plaza']);

        $visible = $this->makeRead($organization, $owner, ['project_id' => $mine->id]);
        $secret = $this->makeRead($organization, $owner, ['project_id' => $hidden->id]);
        $companyLevel = $this->makeRead($organization, $owner, ['kind' => 'tender']);
        $visiblePage = $this->makePage($visible);
        $secretPage = $this->makePage($secret);
        $companyPage = $this->makePage($companyLevel, 1, ['pass' => 'image']);

        $engineer = $this->member($organization, 'engineer');
        $engineer->forceFill(['limited_to_projects' => true])->save();
        DB::table('user_project_access')->insert(['organization_id' => $organization->id, 'user_id' => $engineer->id, 'project_id' => $mine->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($engineer);

        $this->assertNotNull(DocumentRead::find($visible->id));
        $this->assertNull(DocumentRead::find($secret->id));
        $this->assertNotNull(DocumentRead::find($companyLevel->id));
        $this->assertNotNull(DocumentReadPage::find($visiblePage->id));
        $this->assertNull(DocumentReadPage::find($secretPage->id));
        $this->assertNotNull(DocumentReadPage::find($companyPage->id));
        $this->assertSame([$visiblePage->id, $companyPage->id], DocumentReadPage::orderBy('id')->pluck('id')->all());
    }

    public function test_a_page_always_takes_its_company_and_project_from_its_read(): void
    {
        $organization = $this->tenant('Sahyadri Builders');
        $other = $this->tenant('Konkan Infra');
        $project = $this->makeProject($organization);
        $elsewhere = $this->makeProject($organization, ['name' => 'Another Site']);
        $read = $this->makeRead($organization, null, ['project_id' => $project->id]);

        // Left out, and passed wrongly: the read's own values win both times.
        $plain = $this->makePage($read, 1);
        $wrong = $this->makePage($read, 2, ['organization_id' => $other->id, 'project_id' => $elsewhere->id]);

        // Made while someone of another company is signed in, as a mistaken call would be.
        $this->actingAs($this->member($other, 'owner'));
        $signedIn = $this->makePage($read, 3);

        foreach ([$plain, $wrong, $signedIn] as $page) {
            $this->assertSame($organization->id, $page->organization_id);
            $this->assertSame($project->id, $page->project_id);
        }

        $companyLevel = $this->makeRead($organization, null, ['kind' => 'tender']);
        $this->assertNull($this->makePage($companyLevel, 1, ['pass' => 'image', 'project_id' => $project->id])->project_id);
    }

    public function test_a_call_takes_its_company_and_read_from_its_page_and_is_seen_only_where_its_read_is(): void
    {
        $organization = $this->tenant('Sahyadri Builders');
        $other = $this->tenant('Konkan Infra');
        $owner = $this->member($organization, 'owner');
        $mine = $this->makeProject($organization, ['name' => 'Visible Tower']);
        $hidden = $this->makeProject($organization, ['name' => 'Secret Plaza']);
        $visible = $this->makePage($this->makeRead($organization, $owner, ['project_id' => $mine->id]));
        $secret = $this->makePage($this->makeRead($organization, $owner, ['project_id' => $hidden->id]));
        $elsewhere = $this->makeRead($other);

        // Passed wrongly, and made while someone of another company is signed in: the page's own values win.
        $this->actingAs($this->member($other, 'owner'));
        $seen = $this->settledCall($visible, ['organization_id' => $other->id, 'document_read_id' => $elsewhere->id, 'cost_usd' => '0.05']);
        $unseen = $this->settledCall($secret);

        $this->assertSame([$organization->id, $visible->document_read_id], [$seen->organization_id, $seen->document_read_id]);
        $this->assertSame(['0.050000', '0.0000', '0.000000', false], [$seen->cost_usd, $seen->credits, $seen->absorbed_usd, $seen->behaviour_failure]);
        $this->assertSame(0, DocumentReadAttempt::count());

        $engineer = $this->member($organization, 'engineer');
        $engineer->forceFill(['limited_to_projects' => true])->save();
        DB::table('user_project_access')->insert(['organization_id' => $organization->id, 'user_id' => $engineer->id, 'project_id' => $mine->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($engineer);

        $this->assertSame([$seen->id], DocumentReadAttempt::pluck('id')->all());
        $this->assertNull(DocumentReadAttempt::find($unseen->id));

        // A call is on record once.
        $this->expectException(UniqueConstraintViolationException::class);
        DocumentReadAttempt::withoutGlobalScopes()->create(['document_read_page_id' => $visible->id, 'attempt' => 1, 'kind' => 'boq', 'finished_at' => now()]);
    }

    public function test_the_daily_and_monthly_sums_each_have_an_index(): void
    {
        $indexed = fn (string $table) => collect(Schema::getIndexes($table))->pluck('columns')->all();

        // A company's day, and every company's day or month.
        $this->assertContains(['organization_id', 'finished_at'], $indexed('document_read_attempts'));
        $this->assertContains(['finished_at'], $indexed('document_read_attempts'));
        $this->assertContains(['created_at'], $indexed('ai_text_calls'));
        // The month's agent steps of every company, which the readers' monthly limit adds up.
        $this->assertContains(['created_at'], $indexed('agent_steps'));
    }

    public function test_the_agent_steps_index_rolls_back_and_runs_again(): void
    {
        $migration = 'database/migrations/2027_01_10_100009_add_created_at_index_to_agent_steps.php';
        $indexed = fn () => collect(Schema::getIndexes('agent_steps'))->pluck('columns')->contains(['created_at']);

        $this->artisan('migrate:rollback', ['--path' => $migration])->assertSuccessful();
        $this->assertFalse($indexed());
        // The index the agents already had is untouched.
        $this->assertTrue(collect(Schema::getIndexes('agent_steps'))->pluck('columns')->contains(['organization_id', 'created_at']));

        $this->artisan('migrate', ['--path' => $migration])->assertSuccessful();
        $this->assertTrue($indexed());
    }

    public function test_a_page_cannot_be_made_for_a_read_that_does_not_exist(): void
    {
        $this->expectException(LogicException::class);

        DocumentReadPage::withoutGlobalScopes()->create(['document_read_id' => 987654, 'page_no' => 1, 'model' => 'claude-sonnet-5-5']);
    }

    public function test_a_client_uuid_names_one_read_per_company(): void
    {
        $first = $this->tenant('Sahyadri Builders');
        $second = $this->tenant('Konkan Infra');
        $uuid = (string) Str::uuid();

        $this->makeRead($first, null, ['client_uuid' => $uuid]);
        $this->makeRead($second, null, ['client_uuid' => $uuid]);
        $this->makeRead($first);
        $this->makeRead($first);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeRead($first, null, ['client_uuid' => $uuid]);
    }

    public function test_a_page_number_pass_and_part_exists_once_in_a_read(): void
    {
        $read = $this->makeRead($this->tenant());

        $this->makePage($read, 1);
        $this->makePage($read, 1, ['pass' => 'amounts']);
        $this->makePage($read, 1, ['part' => 'top']);
        $this->makePage($read, 1, ['part' => 'bottom']);
        $this->makePage($read, 2);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makePage($read, 1);
    }

    public function test_new_rows_start_from_safe_defaults(): void
    {
        $organization = $this->tenant();
        $read = DocumentRead::withoutGlobalScopes()->create(['organization_id' => $organization->id, 'kind' => 'slip'])->refresh();
        $page = DocumentReadPage::withoutGlobalScopes()->create(['document_read_id' => $read->id, 'page_no' => 1, 'model' => 'claude-haiku-5-5'])->refresh();

        $this->assertSame(DocumentRead::DRAFT, $read->status);
        $this->assertSame('web', $read->channel);
        $this->assertSame('0.0000', $read->credits_held);
        $this->assertSame('0.0000', $read->credits_cap);
        $this->assertSame('0.000000', $read->absorbed_usd);
        $this->assertNull($read->project_id);

        $this->assertSame(DocumentReadPage::WAITING, $page->status);
        $this->assertSame('main', $page->pass);
        $this->assertSame('full', $page->part);
        $this->assertSame(0, $page->attempts);
        $this->assertSame('0.0000', $page->credits);
        $this->assertFalse($page->needs_picture);
        $this->assertFalse($page->cost_estimated);
    }

    public function test_a_company_has_one_settings_row_that_starts_switched_off(): void
    {
        $organization = $this->tenant('Sahyadri Builders');
        $other = $this->tenant('Konkan Infra');

        $settings = AiDocumentSettings::for($organization);
        $again = AiDocumentSettings::for($organization->id);

        $this->assertSame($settings->id, $again->id);
        $this->assertNotSame($settings->id, AiDocumentSettings::for($other)->id);
        $this->assertSame(2, AiDocumentSettings::withoutGlobalScopes()->count());

        $this->assertFalse($settings->enabled);
        $this->assertFalse($settings->platform_block);
        $this->assertNull($settings->consent_version);
        $this->assertNull($settings->budgets);
        $this->assertNull($settings->max_credits_per_read);
        $this->assertNull($settings->quota_lifted_at);

        $this->expectException(UniqueConstraintViolationException::class);
        AiDocumentSettings::withoutGlobalScopes()->create(['organization_id' => $organization->id]);
    }

    public function test_what_is_left_of_a_reads_limit_never_goes_below_nothing(): void
    {
        $organization = $this->tenant();

        $this->assertSame('58.5000', $this->makeRead($organization, null, ['credits_cap' => '100', 'credits_used' => '30.25', 'credits_burned' => '11.25'])->capLeft());
        $this->assertSame('0.0000', $this->makeRead($organization, null, ['credits_cap' => '10', 'credits_used' => '8', 'credits_burned' => '5'])->capLeft());
    }

    public function test_deleting_a_company_removes_its_reads_pages_and_settings_and_a_deleted_person_leaves_the_read(): void
    {
        $organization = $this->tenant('Sahyadri Builders');
        $kept = $this->tenant('Konkan Infra');
        $starter = $this->member($organization, 'engineer');
        $read = $this->makeRead($organization, $starter);
        $this->makePage($read);
        AiDocumentSettings::for($organization)->update(['consented_by' => $starter->id, 'quota_lifted_by' => $starter->id]);
        $keptRead = $this->makeRead($kept);
        $this->makePage($keptRead);

        User::whereKey($starter->id)->delete();

        $this->assertNull($read->refresh()->user_id);
        $this->assertNull(AiDocumentSettings::for($organization)->consented_by);
        $this->assertNull(AiDocumentSettings::for($organization)->quota_lifted_by);

        Organization::whereKey($organization->id)->delete();

        $this->assertSame([$keptRead->id], DocumentRead::withoutGlobalScopes()->pluck('id')->all());
        $this->assertSame(1, DocumentReadPage::withoutGlobalScopes()->count());
        $this->assertSame(0, AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $organization->id)->count());
    }
}
