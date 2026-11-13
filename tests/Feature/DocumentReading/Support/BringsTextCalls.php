<?php

namespace Tests\Feature\DocumentReading\Support;

use App\Models\AiTextCall;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Text call rows in whatever state a test needs, written straight into the table: the tests of
 * credits and limits look at calls that are done, failed or refused without sending anything.
 */
trait BringsTextCalls
{
    /** A text call row with every column the table needs filled in. */
    protected function textCall(Organization $organization, ?User $by = null, array $overrides = []): AiTextCall
    {
        $id = DB::table('ai_text_calls')->insertGetId([
            'organization_id' => $organization->id,
            'user_id' => $by?->id,
            'purpose' => 'wording',
            'model' => 'claude-sonnet-5-5',
            'status' => 'running',
            'attempts' => 1,
            'max_credits' => '3.0000',
            'credits' => '0',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'cost_usd' => '0',
            'cost_estimated' => false,
            'latency_ms' => 0,
            'input_chars' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);

        return AiTextCall::withoutGlobalScopes()->findOrFail($id);
    }
}
