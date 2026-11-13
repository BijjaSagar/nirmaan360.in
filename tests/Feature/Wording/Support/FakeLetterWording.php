<?php

namespace Tests\Feature\Wording\Support;

use App\Models\AiTextCall;
use App\Models\DocumentRead;
use App\Services\Wording\DocumentType;
use App\Services\Wording\Fact;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use App\Services\Wording\Slot;
use App\Services\Wording\WordingCall;
use App\Services\Wording\WordingDocument;
use App\Services\Wording\WordingDraft;

/**
 * A stand-in for a feature's letter: an invented payment follow-up of an invented contractor, kept
 * on a document read because every test database has that table. The read's "options" say which
 * language the letter is in and what is outstanding; its "review" is where a draft is kept.
 *
 * It is what a feature writes to have a model draft its free sentences: which document a record
 * is, and where the draft goes.
 */
class FakeLetterWording extends WordingCall
{
    public const OPENING = [
        'en' => 'We write to you about our running bills.',
        'hi' => 'हम अपने चालू बिलों के बारे में आपको लिख रहे हैं।',
        'mr' => 'आमच्या चालू बिलांबाबत आम्ही आपल्याला लिहीत आहोत.',
    ];

    public const BILL_LINE = [
        'en' => 'The amount outstanding on bill {{number}} is {{outstanding}} as on {{as_on}}.',
        'hi' => 'बिल {{number}} पर {{as_on}} को बकाया राशि {{outstanding}} है।',
        'mr' => 'बिल {{number}} वर {{as_on}} रोजी थकबाकी {{outstanding}} आहे.',
    ];

    public const CLOSING = [
        'en' => 'We look forward to your reply.',
        'hi' => 'हम आपके उत्तर की प्रतीक्षा में हैं।',
        'mr' => 'आपल्या उत्तराची आम्ही वाट पाहत आहोत.',
    ];

    /** @var list<string> Lines a feature would add to describe the letter in general words. */
    public static array $notes = [];

    public static function reset(): void
    {
        self::$notes = [];
    }

    public static function type(): DocumentType
    {
        return new DocumentType('payment_followup', [
            Slot::free('opening', 'Open the letter politely and say it is about unpaid bills.', self::OPENING),
            Slot::fixed('bill_line', self::BILL_LINE, ['number' => 'bill_number', 'outstanding' => 'bill_outstanding', 'as_on' => 'as_on']),
            Slot::free('closing', 'Close the letter with a courteous line that asks for a reply.', self::CLOSING),
        ]);
    }

    public static function sheet(DocumentRead $letter): FactSheet
    {
        return FactSheet::of(
            new Fact('client_name', Fact::TEXT, 'Acme Buildcon', 'Client', FactSource::record('party', 31, 'name')),
            new Fact('bill_number', Fact::REFERENCE, 'RA-P101-003', 'Bill number', FactSource::record('ra_bill', 7, 'number')),
            new Fact('bill_outstanding', Fact::MONEY, (string) ($letter->options['outstanding'] ?? '150000.00'), 'Outstanding amount', FactSource::record('ra_bill', 7, 'outstanding')),
            new Fact('as_on', Fact::DATE, '2026-11-05', 'As on date', FactSource::computed('as_on')),
        );
    }

    public static function documentOf(DocumentRead $letter): WordingDocument
    {
        return new WordingDocument(self::type(), self::sheet($letter), (string) ($letter->options['locale'] ?? 'en'), self::$notes);
    }

    protected function document(AiTextCall $call): ?WordingDocument
    {
        // Nobody is signed in on the queue, so the letter is found by its company as well as its id.
        $letter = DocumentRead::withoutGlobalScopes()->where('organization_id', $call->organization_id)->find($call->subject_id);

        return $letter === null || $letter->status !== DocumentRead::READY ? null : self::documentOf($letter);
    }

    protected function drafted(AiTextCall $call, WordingDraft $draft): void
    {
        DocumentRead::withoutGlobalScopes()->where('organization_id', $call->organization_id)->whereKey($call->subject_id)->update(['review' => json_encode([
            'source' => $draft->source,
            'slots' => $draft->slots(),
            'unwritten' => $draft->unwritten,
            'reason' => $draft->reason,
            'fingerprint' => $draft->fingerprint(),
            'locale' => $draft->document->locale,
        ], JSON_UNESCAPED_UNICODE)]);
    }
}
