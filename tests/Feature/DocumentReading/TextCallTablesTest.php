<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AgentCreditLedger;
use App\Models\AiTextCall;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** The table behind every text call: who can see a row, what may exist only once, and a migration that goes both ways. */
class TextCallTablesTest extends ReadingTestCase
{
    private const MIGRATION = 'database/migrations/2027_01_10_100002_create_ai_text_calls_table.php';

    private function makeCall(Organization $organization, array $overrides = []): AiTextCall
    {
        return AiTextCall::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'purpose' => 'wording',
            'model' => 'claude-sonnet-5-5',
            'max_credits' => '3',
            ...$overrides,
        ])->refresh();
    }

    public function test_the_migration_rolls_back_and_runs_again(): void
    {
        $organization = $this->tenant();
        $wallet = $this->wallet($organization);
        $line = fn (array $more = []) => DB::table('credit_ledger')->insert(['organization_id' => $organization->id, 'credit_wallet_id' => $wallet->id, 'type' => 'usage', 'credits' => '-1', 'balance_after' => '499', ...$more]);

        $call = $this->makeCall($organization);
        $line(['ai_text_call_id' => $call->id, 'reference' => "tc:{$call->id}:1"]);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('ai_text_calls'));
        $this->assertFalse(Schema::hasColumn('credit_ledger', 'ai_text_call_id'));

        // The ledger itself came through with what the reader tables gave it, and still takes new lines.
        $this->assertTrue(Schema::hasColumn('credit_ledger', 'reference'));
        $this->assertTrue(Schema::hasColumn('credit_ledger', 'document_read_id'));
        $this->assertSame("tc:{$call->id}:1", DB::table('credit_ledger')->where('organization_id', $organization->id)->where('type', 'usage')->value('reference'));
        $line();

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('ai_text_calls'));
        $this->assertTrue(Schema::hasColumn('credit_ledger', 'ai_text_call_id'));
        $this->assertSame(2, DB::table('credit_ledger')->where('organization_id', $organization->id)->where('type', 'usage')->whereNull('ai_text_call_id')->count());

        $again = $this->makeCall($organization);
        $line(['ai_text_call_id' => $again->id]);
        $this->assertSame($again->id, AgentCreditLedger::withoutGlobalScopes()->whereNotNull('ai_text_call_id')->sole()->ai_text_call_id);
    }

    public function test_a_new_call_starts_waiting_with_nothing_spent(): void
    {
        $call = $this->makeCall($this->tenant());

        $this->assertSame(AiTextCall::QUEUED, $call->status);
        $this->assertTrue($call->isOpen());
        $this->assertFalse($call->isCorrective());
        $this->assertSame(0, $call->attempts);
        $this->assertSame('3.0000', $call->max_credits);
        $this->assertSame('0.0000', $call->credits);
        $this->assertSame('0.000000', $call->cost_usd);
        $this->assertFalse($call->cost_estimated);
        $this->assertSame(0, $call->input_chars);
        $this->assertNull($call->correction);
        $this->assertNull($call->fail_reason);
        $this->assertNull($call->project_id);
        $this->assertNull($call->user_id);
    }

    public function test_the_row_has_no_place_for_what_was_sent_or_what_came_back(): void
    {
        $columns = Schema::getColumnListing('ai_text_calls');

        foreach (['prompt', 'request', 'body', 'input', 'answer', 'output', 'result', 'response', 'text', 'content'] as $name) {
            $this->assertNotContains($name, $columns);
        }

        // Every column that can hold words is a short one. The only long one is the list of rule codes of a refused answer.
        $long = array_filter(Schema::getColumns('ai_text_calls'), fn (array $column) => in_array($column['type_name'], ['text', 'longtext', 'mediumtext', 'json', 'jsonb'], true));

        $this->assertSame(['correction'], array_values(array_column($long, 'name')));
    }

    public function test_another_company_cannot_see_a_call(): void
    {
        $mine = $this->tenant('Sahyadri Builders');
        $theirs = $this->tenant('Konkan Infra');
        $call = $this->makeCall($mine);

        $this->actingAs($this->member($theirs, 'owner'));

        $this->assertNull(AiTextCall::find($call->id));
        $this->assertSame(0, AiTextCall::count());

        $this->actingAs($this->member($mine, 'owner'));

        $this->assertNotNull(AiTextCall::find($call->id));
    }

    public function test_someone_limited_to_other_projects_does_not_see_a_projects_call(): void
    {
        $organization = $this->tenant();
        $mine = $this->makeProject($organization, ['name' => 'Visible Tower']);
        $hidden = $this->makeProject($organization, ['name' => 'Secret Plaza']);

        $visible = $this->makeCall($organization, ['project_id' => $mine->id]);
        $secret = $this->makeCall($organization, ['project_id' => $hidden->id]);
        $companyLevel = $this->makeCall($organization);

        $engineer = $this->member($organization, 'engineer');
        $engineer->forceFill(['limited_to_projects' => true])->save();
        DB::table('user_project_access')->insert(['organization_id' => $organization->id, 'user_id' => $engineer->id, 'project_id' => $mine->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($engineer);

        $this->assertSame([$visible->id, $companyLevel->id], AiTextCall::orderBy('id')->pluck('id')->all());
        $this->assertNull(AiTextCall::find($secret->id));
    }

    public function test_a_client_uuid_names_one_call_per_company(): void
    {
        $first = $this->tenant('Sahyadri Builders');
        $second = $this->tenant('Konkan Infra');
        $uuid = (string) Str::uuid();

        $this->makeCall($first, ['client_uuid' => $uuid]);
        $this->makeCall($second, ['client_uuid' => $uuid]);
        $this->makeCall($first);
        $this->makeCall($first);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeCall($first, ['client_uuid' => $uuid]);
    }

    public function test_deleting_a_company_removes_its_calls_and_a_deleted_person_or_project_leaves_the_call(): void
    {
        $organization = $this->tenant('Sahyadri Builders');
        $kept = $this->tenant('Konkan Infra');
        $starter = $this->member($organization, 'engineer');
        $project = $this->makeProject($organization);
        $call = $this->makeCall($organization, ['user_id' => $starter->id, 'project_id' => $project->id]);
        $keptCall = $this->makeCall($kept);

        User::whereKey($starter->id)->delete();
        $this->assertNull($call->refresh()->user_id);

        DB::table('projects')->where('id', $project->id)->delete();
        $this->assertNull($call->refresh()->project_id);

        Organization::whereKey($organization->id)->delete();

        $this->assertSame([$keptCall->id], AiTextCall::withoutGlobalScopes()->pluck('id')->all());
    }
}
