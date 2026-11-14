<?php

namespace App\Services\VoiceDpr;

use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\DprVoiceDraft;
use App\Services\DocumentReading\Contracts\TextCallHandler;
use App\Services\DocumentReading\NothingToSend;
use App\Services\DocumentReading\TextRequest;
use Illuminate\Support\Str;

/**
 * The text call of a spoken report: the words heard, the trade and weather lists and the offered BOQ
 * lines go to the model, and what comes back is checked by VoiceDraftChecks before the person sees it.
 *
 * The words are sent as they were heard: the patterns that take phone numbers and account numbers
 * out of other text calls would eat runs of spoken quantities (the purpose "voice" is marked so in
 * config/modules/document_reading.php). The company's consent notice says so. No rate is ever sent.
 */
class VoiceDraftCall implements TextCallHandler
{
    /** Purposes are named in config/modules/document_reading.php. */
    public const PURPOSE = 'voice';

    public const INSTRUCTIONS = <<<'TEXT'
    A site supervisor spoke the day's report for a construction site, and the phone turned the speech into the text inside <transcript>. Fill the form of a daily progress report from that text.
    - weather: one value from <weather>, written exactly as listed, when the speaker said what the weather was. weather_spoken: the words of the transcript that say it. Both empty when the weather was not mentioned.
    - manpower: one entry for each kind of worker the speaker gave a number for. trade: the matching name from <trades>, written exactly as listed, or empty when none of them fits. trade_spoken: the words of the transcript that name the worker. count: that number in plain digits. count_spoken: the words of the transcript that state the number.
    - work: one entry for each piece of work for which a quantity was said. item: the ref of the matching line from <items>, or empty when no line clearly fits. description: a short description of the work in the speaker's own words. qty: the quantity in plain digits, with a dot before any decimals. qty_spoken: the words of the transcript that state the quantity. unit_spoken: the words of the transcript that give its unit, or empty when no unit was said.
    - hindrances: what stopped or slowed the work, in short, in the language of the transcript. Empty when nothing was said about it.
    - summary: anything else said about the day, in short, in the language of the transcript. Empty when nothing else was said.
    Rules:
    - Every field whose name ends in _spoken is copied from the transcript character for character: the same words, the same script, the same digits. Never translate, correct or reword it, and copy only the words of that one value.
    - Take every number only from the transcript. Do not calculate, add up, round, convert a unit or split a number. A number that was said once fills one field only.
    - A size, a grade or a time of day is not a count or a quantity: 12 mm, M25, Fe 500, 53 grade, 9 o'clock.
    - Leave out what was not said. An empty list or an empty text is a correct answer.
    - Put no number in hindrances or summary that is not in the transcript, and no name or phone number of a person anywhere.
    TEXT;

    public function __construct(private VoiceContext $context) {}

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $text = ['type' => 'string'];
        $row = fn (array $fields) => [
            'type' => 'object',
            'properties' => array_fill_keys($fields, $text),
            'required' => $fields,
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'weather' => $text,
                'weather_spoken' => $text,
                'manpower' => ['type' => 'array', 'items' => $row(['trade', 'trade_spoken', 'count', 'count_spoken'])],
                'work' => ['type' => 'array', 'items' => $row(['item', 'description', 'qty', 'qty_spoken', 'unit_spoken'])],
                'hindrances' => $text,
                'summary' => $text,
            ],
            'required' => ['weather', 'weather_spoken', 'manpower', 'work', 'hindrances', 'summary'],
            'additionalProperties' => false,
        ];
    }

    public function request(AiTextCall $call): array
    {
        $draft = $this->draft($call);
        $language = (string) (config('modules.voice_dpr.locales')[$draft->locale] ?? 'English');
        $chars = (int) config('modules.voice_dpr.context.description_chars');

        $items = [];

        foreach ($this->context->items($draft) as $ref => $item) {
            $items[] = ['ref' => $ref, 'code' => (string) $item->code, 'description' => Str::limit((string) $item->description, $chars, ''), 'unit' => (string) $item->unit];
        }

        return TextRequest::body($call, self::INSTRUCTIONS, self::schema(), [
            TextRequest::data('trades', array_values((array) config('buildos.trades'))),
            TextRequest::data('weather', array_values((array) config('buildos.weather'))),
            TextRequest::data('items', $items),
            TextRequest::data('transcript', (string) $draft->transcript),
            "The speaker used {$language}. Fill the form from the transcript as instructed.",
        ]);
    }

    public function done(AiTextCall $call, array $output): void
    {
        $draft = DprVoiceDraft::withoutGlobalScopes()
            ->where('organization_id', $call->organization_id)
            ->whereKey($call->subject_id)
            ->lockForUpdate()
            ->first();

        // Gone, blanked or already ended while the model was writing: nothing is kept and nothing is charged.
        if ($draft === null || $draft->transcript === null || $draft->status !== DprVoiceDraft::QUEUED) {
            throw new NothingToSend;
        }

        $checked = (new VoiceDraftChecks((string) $draft->transcript, (string) $draft->locale))->proposal($output, $this->context->items($draft));
        $proposal = $checked['proposal'];

        $draft->forceFill([
            'status' => DprVoiceDraft::READY,
            'proposal' => $proposal,
            'checks' => ['refs' => (array) ($draft->checks['refs'] ?? []), 'backing' => $checked['backing']],
            'fail_reason' => null,
        ])->save();

        $notHeard = count(array_filter([...$proposal['manpower'], ...$proposal['work']], fn (array $row) => ! $row['heard']));

        AuditLog::record('voice_dpr.draft_ready', $draft, null, [
            'manpower' => count($proposal['manpower']),
            'work' => count($proposal['work']),
            'not_heard' => $notHeard,
        ], null, (int) $draft->organization_id);
    }

    public function failed(AiTextCall $call, string $reason): void
    {
        $changed = DprVoiceDraft::withoutGlobalScopes()
            ->where('organization_id', $call->organization_id)
            ->whereKey($call->subject_id)
            ->where('status', DprVoiceDraft::QUEUED)
            ->update(['status' => DprVoiceDraft::FAILED, 'fail_reason' => substr($reason, 0, 24)]);

        if ($changed === 1 && ($draft = DprVoiceDraft::withoutGlobalScopes()->find($call->subject_id)) !== null) {
            AuditLog::record('voice_dpr.draft_failed', $draft, null, ['reason' => substr($reason, 0, 24)], null, (int) $draft->organization_id);
        }
    }

    private function draft(AiTextCall $call): DprVoiceDraft
    {
        $draft = DprVoiceDraft::withoutGlobalScopes()
            ->where('organization_id', $call->organization_id)
            ->whereKey($call->subject_id)
            ->first();

        if ($draft === null || $draft->transcript === null || trim((string) $draft->transcript) === '') {
            throw new NothingToSend;
        }

        return $draft;
    }
}
