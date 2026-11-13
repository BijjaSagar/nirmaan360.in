<?php

namespace Tests\Feature\VoiceDpr;

use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\Dpr;
use App\Models\DprManpower;
use App\Models\DprVoiceDraft;
use App\Models\DprWorkItem;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ShapeCheck;
use App\Services\DocumentReading\TextRequest;
use App\Services\VoiceDpr\VoiceContext;
use App\Services\VoiceDpr\VoiceDraftCall;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;

/**
 * A spoken report from the phone to the proposal and back: what is stored, what is sent to the model,
 * what the phone reads, and that nothing anywhere becomes a DPR.
 */
class VoiceDraftFlowTest extends VoiceDprTestCase
{
    public function test_the_words_are_taken_and_a_call_is_queued_and_no_dpr_is_written(): void
    {
        $payload = $this->payload();
        $response = $this->send($this->engineer, $payload)->assertStatus(202);

        $draft = $this->draft($response->json('id'));
        $response->assertExactJson([
            'id' => $draft->id,
            'status' => 'queued',
            'client_uuid' => $payload['client_uuid'],
            'project_id' => $this->project->id,
            'site_id' => null,
            'report_date' => $payload['report_date'],
            'locale' => 'mr-IN',
            'transcript' => self::TRANSCRIPT,
            'proposal' => null,
            'credits' => '0.0000',
            'fail_reason' => null,
            'message' => null,
            'linked' => false,
            'text_purged' => false,
        ]);

        $this->assertSame([$this->engineer->id, DprVoiceDraft::QUEUED, 'android', '1.0.1+20'], [$draft->user_id, $draft->status, $draft->platform, $draft->app_version]);
        $this->assertSame(['engine' => 'android_on_device', 'os' => 'android 14 (sdk 34)', 'offline_checked' => true], $draft->speech);

        // The PCC line shares "PCC" with what was said; the others share nothing and were not used lately.
        $this->assertSame(['refs' => [$this->pcc->id]], $draft->checks);

        // One text call for the draft, holding its most, on the queue.
        $call = AiTextCall::withoutGlobalScopes()->findOrFail($draft->ai_text_call_id);
        $this->assertSame(['voice', DprVoiceDraft::class, $draft->id, $this->project->id, 'claude-haiku-5-5'], [$call->purpose, $call->subject_type, $call->subject_id, $call->project_id, $call->model]);
        $this->assertSame([$call->id], $this->queuedCalls());
        $this->assertSame($call->max_credits, $this->credits()->held($this->organization));
        Http::assertNothingSent();

        $this->assertSame(0, Dpr::withoutGlobalScopes()->count());

        // The audit trail says what happened, never what was said.
        $audit = AuditLog::withoutGlobalScopes()->where('action', 'voice_dpr.draft_created')->sole();
        $this->assertSame(['locale' => 'mr-IN', 'engine' => 'android_on_device', 'platform' => 'android', 'chars' => mb_strlen(self::TRANSCRIPT)], $audit->new_values);
        $this->assertSame($this->engineer->id, $audit->user_id);
    }

    public function test_the_proposal_is_ready_once_the_model_has_answered_and_every_figure_is_checked(): void
    {
        $id = $this->send($this->engineer)->assertStatus(202)->json('id');
        $this->fakeModel([$this->answer($this->form())]);
        $this->runCall($this->draft($id)->ai_text_call_id);

        $response = $this->look($this->engineer, $id)->assertOk()->assertJson(['status' => 'ready', 'fail_reason' => null]);

        $this->assertSame([
            'weather' => 'Clear',
            'manpower' => [
                ['trade' => 'Mason', 'trade_spoken' => 'गवंडी', 'count' => '6', 'count_spoken' => 'सहा', 'heard' => true, 'anchored' => true],
                ['trade' => 'Helper', 'trade_spoken' => 'हेल्पर', 'count' => '12', 'count_spoken' => 'बारा', 'heard' => true, 'anchored' => true],
            ],
            'work' => [[
                'boq_item_id' => $this->pcc->id, 'code' => '7', 'description' => 'PCC 1:4:8 in foundation', 'qty' => '12.500', 'qty_spoken' => 'साडेबारा',
                'unit_spoken' => 'घनमीटर', 'unit_item' => 'cum', 'unit_ok' => true, 'heard' => true, 'anchored' => true,
            ]],
            'hindrances' => 'सिमेंट उशिरा आलं म्हणून काम थांबलं',
            'summary' => '',
            'unused_numbers' => ['40'],
            'text_has_unheard_number' => false,
        ], $response->json('proposal'));

        // The call was charged, and the phone is told what it cost in credits.
        $call = AiTextCall::withoutGlobalScopes()->findOrFail($this->draft($id)->ai_text_call_id);
        $this->assertSame(AiTextCall::DONE, $call->status);
        $this->assertTrue($call->credits > '0.0000');
        $this->assertSame($call->credits, $response->json('credits'));

        // Still nothing that a DPR or the BOQ ledger counts.
        $this->assertSame([0, 0, 0], [Dpr::withoutGlobalScopes()->count(), DprWorkItem::withoutGlobalScopes()->count(), DprManpower::withoutGlobalScopes()->count()]);
        $this->assertNull(DB::table('boq_items')->where('id', $this->pcc->id)->value('deleted_at'));
    }

    public function test_the_request_carries_the_words_as_heard_the_lists_and_the_lines_and_nothing_else(): void
    {
        // Three groups of four digits look like an Aadhaar number to the filter other text calls use.
        $transcript = 'सहा गवंडी. PCC साडेबारा घनमीटर. गाडी नंबर 2345 6789 0123 आली.';
        $this->assertNotSame($transcript, TextRequest::withoutPersonalDetails($transcript));

        $id = $this->send($this->engineer, $this->payload(['transcript' => $transcript]))->assertStatus(202)->json('id');
        $this->fakeModel([$this->answer($this->form())]);
        $this->runCall($this->draft($id)->ai_text_call_id);

        $body = $this->sent[0];
        $message = $this->messageOf($body);

        $this->assertSame(TextRequest::KEYS, array_keys($body));
        $this->assertSame([], TextRequest::problems($body, 'voice'));
        $this->assertSame('claude-haiku-5-5', $body['model']);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertSame(VoiceDraftCall::schema(), $body['output_config']['format']['schema']);
        $this->assertStringContainsString(VoiceDraftCall::INSTRUCTIONS, $body['system'][0]['text']);

        // The words go as they were heard: not redacted.
        $this->assertStringContainsString("<transcript>\n{$transcript}\n</transcript>", $message);
        $this->assertStringNotContainsString('[redacted]', $message);

        // The lists the code holds, and the offered line by its ref, code, description and unit. No rate, no names.
        $this->assertStringContainsString('<trades>', $message);
        $this->assertStringContainsString('"Bar bender"', $message);
        $this->assertStringContainsString('"Rain"', $message);
        $this->assertStringContainsString('{"ref":"B1","code":"7","description":"PCC 1:4:8 in foundation","unit":"cum"}', $message);
        $this->assertStringContainsString('The speaker used Marathi.', $message);

        foreach (['5250', '5,250', 'rate', 'Shivneri Heights', 'Sahyadri Builders', $this->engineer->name, 'Brick masonry'] as $absent) {
            $this->assertStringNotContainsString($absent, json_encode($body, JSON_UNESCAPED_UNICODE), $absent);
        }
    }

    public function test_lines_used_in_recent_reports_are_offered_and_a_line_deleted_since_keeps_its_ref_free(): void
    {
        $report = Dpr::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'report_date' => now()->subDays(3)->toDateString(), 'status' => 'DRAFT', 'created_by' => $this->engineer->id]);
        DprWorkItem::create(['organization_id' => $this->organization->id, 'dpr_id' => $report->id, 'boq_item_id' => $this->plaster->id, 'qty' => '20.000']);
        $old = Dpr::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'report_date' => now()->subDays(40)->toDateString(), 'status' => 'DRAFT', 'created_by' => $this->engineer->id]);
        DprWorkItem::create(['organization_id' => $this->organization->id, 'dpr_id' => $old->id, 'boq_item_id' => $this->brickwork->id, 'qty' => '5.000']);
        $elsewhere = $this->line($this->makeProject($this->organization), '7', 'PCC 1:4:8 in foundation', 'cum', '5100.00');

        $id = $this->send($this->engineer)->assertStatus(202)->json('id');

        // The line that matched a word first, then the one used this fortnight. Not the one from long ago, nor another project's.
        $this->assertSame(['refs' => [$this->pcc->id, $this->plaster->id]], $this->draft($id)->checks);
        $this->assertNotContains($elsewhere->id, $this->draft($id)->checks['refs']);

        $this->pcc->delete();
        $this->fakeModel([$this->answer($this->form(['work' => [['item' => 'B2', 'description' => 'प्लास्टर', 'qty' => '40', 'qty_spoken' => '40', 'unit_spoken' => 'नग']]]))]);
        $this->runCall($this->draft($id)->ai_text_call_id);

        $message = $this->messageOf($this->sent[0]);
        $this->assertStringNotContainsString('"B1"', $message);
        $this->assertStringContainsString('{"ref":"B2","code":"15","description":"Internal plaster 12 mm thick","unit":"sqm"}', $message);
        $this->assertSame($this->plaster->id, $this->draft($id)->proposal['work'][0]['boq_item_id']);
    }

    public function test_a_line_named_by_short_marathi_or_hindi_work_words_is_offered_and_one_sharing_only_a_little_word_is_not(): void
    {
        $wall = $this->line($this->project, 'BW-01', 'वीट भिंत 230 मिमी', 'cum', '6100.00');
        $digging = $this->line($this->project, 'EX-02', 'खोदकाम आणि भराव', 'cum', '410.00');
        $slab = $this->line($this->project, 'RC-03', 'छत ढलाई M20', 'cum', '7300.00');
        $brick = $this->line($this->project, 'BW-04', 'ईंट की दीवार', 'sqm', '980.00');
        $sand = $this->line($this->project, 'FL-05', 'रेत भराई', 'cum', '520.00');

        // Two-syllable nouns such as वीट, भिंत, छत, ईंट and रेत have two letters by \p{L}, and they name the work.
        $marathi = $this->send($this->engineer, $this->payload(['transcript' => 'आज वीट भिंत तीन घनमीटर झाली आणि काम थांबलं.']))->assertStatus(202)->json('id');
        $this->assertSame([$wall->id], $this->draft($marathi)->checks['refs'], 'आणि and काम name no work, so the digging line is not offered.');

        $hindi = $this->send($this->engineer, $this->payload(['locale' => 'hi-IN', 'transcript' => 'आज छत की ढलाई हुई और ईंट का काम भी, रेत नहीं आई.']))->assertStatus(202)->json('id');
        $this->assertSame([$slab->id, $brick->id, $sand->id], $this->draft($hindi)->checks['refs'], 'की, का and भी are single syllables and pick no line.');
        $this->assertNotContains($digging->id, $this->draft($hindi)->checks['refs']);
    }

    public function test_a_little_word_of_a_line_is_never_read_inside_a_longer_word_while_a_case_ending_still_names_it(): void
    {
        $withLabour = $this->line($this->project, 'BW-06', 'वीट बांधकाम साहित्य व मजुरी सह', 'cum', '6200.00');
        $groundFloor = $this->line($this->project, 'PT-07', 'तळमजला वर रंगकाम', 'sqm', '180.00');
        $roof = $this->line($this->project, 'WP-08', 'छत पर वॉटरप्रूफिंग', 'sqm', '650.00');
        $wallPlaster = $this->line($this->project, 'PL-09', 'दीवार पर प्लास्टर करना', 'sqm', '330.00');
        $bars = $this->line($this->project, 'RF-10', 'बार बेंडिंग', 'kg', '75.00');
        $tiles = $this->line($this->project, 'FL-11', 'टाइल 600 मिमी', 'sqm', '1150.00');
        $wall = $this->line($this->project, 'BW-12', 'भिंत 230 मिमी', 'cum', '6100.00');
        $brick = $this->line($this->project, 'BW-13', 'ईंट की दीवार', 'sqm', '980.00');

        // सह is not सहा, वर not वरच्या, पर not परवा, बार not बारा, and मिमी names no line as mm does not;
        // भिंत with its ending, as in भिंतीचं, is still the wall.
        $marathi = $this->send($this->engineer, $this->payload(['transcript' => 'सहा गवंडी, बारा हेल्पर. वरच्या मजल्यावर परवा स्लॅब झाला. भिंतीचं काम 230 मिमी झालं.']))->assertStatus(202)->json('id');
        $this->assertSame([$wall->id], $this->draft($marathi)->checks['refs']);

        // कर is not करना, and ईंटों is ईंट.
        $hindi = $this->send($this->engineer, $this->payload(['locale' => 'hi-IN', 'transcript' => 'मिस्त्री काम कर रहे थे. ईंटों की चिनाई हुई.']))->assertStatus(202)->json('id');
        $this->assertSame([$brick->id], $this->draft($hindi)->checks['refs']);

        foreach ([$withLabour, $groundFloor, $roof, $wallPlaster, $bars, $tiles] as $unnamed) {
            $this->assertNotContains($unnamed->id, [...$this->draft($marathi)->checks['refs'], ...$this->draft($hindi)->checks['refs']], $unnamed->description);
        }
    }

    public function test_a_two_letter_word_names_its_compounds_and_inflections_on_either_side_and_no_other_longer_word(): void
    {
        $brickwork = $this->line($this->project, 'BW-14', 'वीटकाम 230 मिमी जाडीचे', 'cum', '6150.00');
        $painting = $this->line($this->project, 'PT-15', 'रंगकाम', 'sqm', '95.00');
        $roof = $this->line($this->project, 'RC-16', 'छत M20', 'cum', '7300.00');
        $bricks = $this->line($this->project, 'BW-17', 'वीट पुरवठा', 'nos', '9.00');
        $mesh = $this->line($this->project, 'FN-18', 'तार जाळी', 'sqm', '240.00');

        // The short word is said, the line has the compound: वीट names वीटकाम, रंग names रंगकाम. तारीख is not तार:
        // neither a stop word, a unit nor a number, so only the rule for a short word inside a longer one keeps it out.
        $said = $this->send($this->engineer, $this->payload(['transcript' => 'आज वीट तीन घनमीटर, भिंतीला रंग दिला. तारीख पाच.']))->assertStatus(202)->json('id');
        $this->assertEqualsCanonicalizing([$brickwork->id, $painting->id, $bricks->id], $this->draft($said)->checks['refs']);

        // The line has the short word, the longer one is said: छतावरील names छत, वीटकामाचे names वीट; तारखेला is not तार.
        $written = $this->send($this->engineer, $this->payload(['transcript' => 'छतावरील पाणी काढले. वीटकामाचे मोजमाप तारखेला होईल.']))->assertStatus(202)->json('id');
        $this->assertEqualsCanonicalizing([$roof->id, $bricks->id, $brickwork->id], $this->draft($written)->checks['refs']);

        $this->assertNotContains($mesh->id, [...$this->draft($said)->checks['refs'], ...$this->draft($written)->checks['refs']]);

        // The rule itself, in both orders: what may follow a short word is vowel signs, one head of work and one case ending.
        $same = new ReflectionMethod(VoiceContext::class, 'same');
        $context = app(VoiceContext::class);

        foreach ([['वीट', 'वीटकाम'], ['रंग', 'रंगकाम'], ['माती', 'मातीकाम'], ['खडी', 'खडीकरण'], ['छत', 'छतावरील'], ['भिंत', 'भिंतीवरील'], ['भिंत', 'भिंतीचं'], ['ईंट', 'ईंटों'], ['वीट', 'वीटकामाचे'], ['भिंत', 'भिंतीमधील']] as [$short, $long]) {
            $this->assertTrue($same->invoke($context, $short, $long), "{$short} / {$long}");
            $this->assertTrue($same->invoke($context, $long, $short), "{$long} / {$short}");
        }

        foreach ([['तार', 'तारीख'], ['तार', 'तारखेला'], ['पर', 'परवा'], ['बार', 'बारीक'], ['छत', 'छत्री']] as [$short, $long]) {
            $this->assertFalse($same->invoke($context, $short, $long), "{$short} / {$long}");
            $this->assertFalse($same->invoke($context, $long, $short), "{$long} / {$short}");
        }
    }

    public function test_a_failed_call_leaves_the_words_and_says_so(): void
    {
        $id = $this->send($this->engineer)->assertStatus(202)->json('id');
        $this->fakeModel([$this->failure(400, ['type' => 'invalid_request_error', 'message' => 'Bad request.'])]);
        $this->runCall($this->draft($id)->ai_text_call_id);

        $this->look($this->engineer, $id)->assertOk()->assertJson([
            'status' => 'failed',
            'transcript' => self::TRANSCRIPT,
            'proposal' => null,
            'credits' => '0.0000',
            'message' => 'No draft could be made from these words. Type the report, or use the words as notes.',
        ])->assertJsonPath('fail_reason', fn ($reason) => is_string($reason) && $reason !== '');

        $this->assertNotCharged();
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'voice_dpr.draft_failed')->count());
    }

    public function test_asking_for_the_status_ends_a_call_nobody_picked_up(): void
    {
        $id = $this->send($this->engineer)->assertStatus(202)->json('id');
        $this->look($this->engineer, $id)->assertOk()->assertJson(['status' => 'queued']);

        $this->travel(16)->minutes();

        $this->look($this->engineer, $id)->assertOk()->assertJson(['status' => 'failed', 'fail_reason' => 'not_started']);
        $this->assertSame('0.0000', $this->credits()->held($this->organization));
        Http::assertNothingSent();
    }

    public function test_the_person_links_the_dpr_they_sent_and_says_what_they_changed(): void
    {
        $id = $this->send($this->engineer)->assertStatus(202)->json('id');

        // Before the proposal there is nothing to link.
        $dpr = (string) Str::uuid();
        $this->link($this->engineer, $id, ['dpr_client_uuid' => $dpr, 'changed' => []])->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => 'queued']);

        $this->fakeModel([$this->answer($this->form())]);
        $this->runCall($this->draft($id)->ai_text_call_id);

        $this->link($this->engineer, $id, ['dpr_client_uuid' => $dpr, 'changed' => ['work.0.qty', 'manpower.1.count', 'work.0.qty']])
            ->assertOk()->assertJson(['status' => 'ready', 'linked' => true]);
        $this->assertSame([$dpr, ['work.0.qty', 'manpower.1.count']], [$this->draft($id)->dpr_client_uuid, $this->draft($id)->changed]);

        // Sending it again changes nothing; another DPR is refused.
        $this->link($this->engineer, $id, ['dpr_client_uuid' => $dpr, 'changed' => []])->assertOk();
        $this->assertSame(['work.0.qty', 'manpower.1.count'], $this->draft($id)->changed);
        $this->link($this->engineer, $id, ['dpr_client_uuid' => (string) Str::uuid(), 'changed' => []])->assertStatus(409)->assertJson(['code' => 'already_linked']);

        $this->link($this->engineer, $id, ['dpr_client_uuid' => 'not-a-uuid', 'changed' => ['<b>']])->assertStatus(422)->assertJsonValidationErrors(['dpr_client_uuid', 'changed.0']);

        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'voice_dpr.draft_linked')->count());
        $this->assertSame(0, Dpr::withoutGlobalScopes()->count());
    }

    public function test_a_repeat_by_the_same_person_returns_the_draft_and_by_another_is_refused(): void
    {
        $payload = $this->payload();
        $id = $this->send($this->engineer, $payload)->assertStatus(202)->json('id');

        $this->send($this->engineer, $payload)->assertOk()->assertJson(['id' => $id, 'status' => 'queued']);
        $this->send($this->engineer, [...$payload, 'transcript' => 'दुसरं काही'])->assertOk()->assertJson(['id' => $id, 'transcript' => self::TRANSCRIPT]);

        $other = $this->member($this->organization, 'engineer');
        $this->send($other, $payload)->assertStatus(422)->assertJsonValidationErrors('client_uuid');

        $this->assertSame(1, DprVoiceDraft::withoutGlobalScopes()->count());
        $this->assertCount(1, $this->queuedCalls());
    }

    public function test_what_the_phone_sends_is_checked(): void
    {
        $otherSite = DB::table('sites')->insertGetId(['organization_id' => $this->organization->id, 'project_id' => $this->makeProject($this->organization)->id, 'name' => 'Wing C', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([
            'transcript' => ['transcript' => str_repeat('अ', 6001)],
            'locale' => ['locale' => 'gu-IN'],
            'report_date' => ['report_date' => now('Asia/Kolkata')->addDays(2)->toDateString()],
            'site_id' => ['site_id' => $otherSite],
            'platform' => ['platform' => 'windows'],
            'client_uuid' => ['client_uuid' => 'abc'],
        ] as $field => $change) {
            $this->send($this->engineer, $this->payload($change))->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $this->send($this->engineer, $this->payload(['transcript' => ' . , ']))->assertStatus(422)->assertJsonValidationErrors('transcript');

        $this->assertSame(0, DprVoiceDraft::withoutGlobalScopes()->count());
        $this->assertSame([], $this->queuedCalls());
    }

    public function test_the_form_asked_of_the_model_follows_the_reader_rules(): void
    {
        $this->assertSame([], ShapeCheck::schemaProblems(VoiceDraftCall::schema()));
        $this->assertFalse(TextRequest::redacts('voice'));
        $this->assertTrue(AiDocumentConsent::covers('voice'));
    }

    public function test_a_file_sent_with_the_words_is_neither_read_nor_kept(): void
    {
        Storage::fake();
        Sanctum::actingAs($this->engineer);

        $this->post('/api/v1/projects/'.$this->project->id.'/dpr-voice-drafts', [...$this->payload(), 'audio' => UploadedFile::fake()->create('note.m4a', 200, 'audio/mp4')], ['Accept' => 'application/json'])
            ->assertStatus(202);

        $this->assertSame([], Storage::allFiles());
        $this->assertStringNotContainsString('note.m4a', json_encode(DprVoiceDraft::withoutGlobalScopes()->sole()->toArray()));
    }
}
