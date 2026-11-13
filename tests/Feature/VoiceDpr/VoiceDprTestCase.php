<?php

namespace Tests\Feature\VoiceDpr;

use App\Models\AiTextCall;
use App\Models\BoqItem;
use App\Models\DprVoiceDraft;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Website\AiOffer;
use App\Support\PlatformSettings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\DocumentReading\TextCallTestCase;

/**
 * A company that may send voice reports: the readers released and connected, its consent on, voice
 * accepted by the platform admin, a project with three BOQ lines, and the fake provider and queue of
 * the text call tests. The companies, lines and figures are invented.
 */
abstract class VoiceDprTestCase extends TextCallTestCase
{
    /** A Marathi report as a phone would hear it. */
    protected const TRANSCRIPT = 'आज ऊन होतं. सहा गवंडी, बारा हेल्पर. फूटिंगमध्ये PCC साडेबारा घनमीटर झालं. 12 mm सळई 40 नग आली. सिमेंट उशिरा आलं म्हणून काम थांबलं.';

    protected Project $project;

    protected BoqItem $pcc;

    protected BoqItem $brickwork;

    protected BoqItem $plaster;

    protected function setUp(): void
    {
        parent::setUp();

        // Voice is the only reader unless a test deploys a stand-in beside it.
        $this->withoutShippedReaders();
        $this->consent($this->organization);
        app(PlatformSettings::class)->set([AiOffer::VOICE_ACCEPTED => '1']);

        $this->project = $this->makeProject($this->organization, ['name' => 'Shivneri Heights']);
        $this->pcc = $this->line($this->project, '7', 'PCC 1:4:8 in foundation', 'cum', '5250.00');
        $this->brickwork = $this->line($this->project, '12', 'Brick masonry in superstructure', 'cum', '6475.00');
        $this->plaster = $this->line($this->project, '15', 'Internal plaster 12 mm thick', 'sqm', '345.00');
    }

    protected function line(Project $project, string $code, string $description, string $unit, string $rate): BoqItem
    {
        return BoqItem::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'code' => $code,
            'description' => $description,
            'unit' => $unit,
            'qty' => '100.000',
            'rate' => $rate,
        ]);
    }

    /** @return array<string, mixed> What the phone sends. */
    protected function payload(array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'transcript' => self::TRANSCRIPT,
            'locale' => 'mr-IN',
            'report_date' => now('Asia/Kolkata')->toDateString(),
            'site_id' => null,
            'recorded_at' => now()->toIso8601String(),
            'speech' => ['engine' => 'android_on_device', 'os' => 'android 14 (sdk 34)', 'offline_checked' => true],
            'platform' => 'android',
            'app_version' => '1.0.1+20',
            ...$overrides,
        ];
    }

    protected function send(User $user, array $payload = [], ?Project $project = null): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/projects/'.($project ?? $this->project)->id.'/dpr-voice-drafts', $payload === [] ? $this->payload() : $payload);
    }

    protected function look(User $user, int $draft): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson("/api/v1/dpr-voice-drafts/{$draft}");
    }

    protected function link(User $user, int $draft, array $data): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson("/api/v1/dpr-voice-drafts/{$draft}/link", $data);
    }

    /** @return array<string, mixed> The model's answer to TRANSCRIPT, with the PCC line as B1. */
    protected function form(array $overrides = []): array
    {
        return [
            'weather' => 'Clear',
            'weather_spoken' => 'ऊन',
            'manpower' => [
                ['trade' => 'Mason', 'trade_spoken' => 'गवंडी', 'count' => '6', 'count_spoken' => 'सहा'],
                ['trade' => 'Helper', 'trade_spoken' => 'हेल्पर', 'count' => '12', 'count_spoken' => 'बारा'],
            ],
            'work' => [['item' => 'B1', 'description' => 'फूटिंगमध्ये PCC', 'qty' => '12.5', 'qty_spoken' => 'साडेबारा', 'unit_spoken' => 'घनमीटर']],
            'hindrances' => 'सिमेंट उशिरा आलं म्हणून काम थांबलं',
            'summary' => '',
            ...$overrides,
        ];
    }

    protected function draft(int $id): DprVoiceDraft
    {
        return DprVoiceDraft::withoutGlobalScopes()->findOrFail($id);
    }

    /** A text call of the person's for voice, finished today, as if a report had been read. */
    protected function spentOnVoice(Organization $organization, User $by, string $credits): AiTextCall
    {
        return AiTextCall::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'user_id' => $by->id,
            'purpose' => 'voice',
            'model' => 'claude-haiku-5-5',
            'status' => AiTextCall::DONE,
            'max_credits' => $credits,
            'credits' => $credits,
        ]);
    }
}
