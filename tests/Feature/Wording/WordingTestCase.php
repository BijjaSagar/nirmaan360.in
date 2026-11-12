<?php

namespace Tests\Feature\Wording;

use App\Services\Wording\DocumentType;
use App\Services\Wording\Fact;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use App\Services\Wording\Renderer;
use App\Services\Wording\Slot;
use App\Services\Wording\SlotGuard;
use Tests\TestCase;

/** An invented payment letter of an invented contractor, used by every wording test. */
abstract class WordingTestCase extends TestCase
{
    protected const OPENING = [
        'en' => 'We write to you about our running bills.',
        'hi' => 'हम अपने चालू बिलों के बारे में आपको लिख रहे हैं।',
        'mr' => 'आमच्या चालू बिलांबाबत आम्ही आपल्याला लिहीत आहोत.',
    ];

    protected const BILL_LINE = [
        'en' => 'The amount outstanding on bill {{number}} is {{outstanding}} as on {{as_on}}.',
        'hi' => 'बिल {{number}} पर {{as_on}} को बकाया राशि {{outstanding}} है।',
        'mr' => 'बिल {{number}} वर {{as_on}} रोजी थकबाकी {{outstanding}} आहे.',
    ];

    protected const CLOSING = [
        'en' => 'We look forward to your reply.',
        'hi' => 'हम आपके उत्तर की प्रतीक्षा में हैं।',
        'mr' => 'आपल्या उत्तराची आम्ही वाट पाहत आहोत.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Hindi and Marathi for the templates these tests declare as one English string.
        app('translator')->addJsonPath(__DIR__.'/lang');
    }

    /** @param  array<string, mixed>  $values  Other values for facts, by key. */
    protected function sheet(array $values = []): FactSheet
    {
        $value = fn (string $key, mixed $default) => $values[$key] ?? $default;

        return FactSheet::of(
            new Fact('client_name', Fact::TEXT, $value('client_name', 'Acme Buildcon'), 'Client', FactSource::record('party', 31, 'name')),
            new Fact('bill_1_number', Fact::REFERENCE, $value('bill_1_number', 'RA-P101-003'), 'Bill number', FactSource::record('ra_bill', 7, 'number')),
            new Fact('bill_1_outstanding', Fact::MONEY, $value('bill_1_outstanding', '150000.00'), 'Outstanding amount', FactSource::record('ra_bill', 7, 'outstanding')),
            new Fact('as_on', Fact::DATE, $value('as_on', '2026-11-05'), 'As on date', FactSource::computed('as_on')),
            new Fact('days_open', Fact::DAYS, $value('days_open', '14'), 'Days since the bill date', FactSource::computed('days_open')),
        );
    }

    protected function letter(): DocumentType
    {
        return new DocumentType('payment_followup', [
            Slot::free('opening', 'Open the letter politely and say it is about unpaid bills.', self::OPENING),
            Slot::fixed('bill_1', self::BILL_LINE, ['number' => 'bill_1_number', 'outstanding' => 'bill_1_outstanding', 'as_on' => 'as_on']),
            Slot::free('closing', 'Close the letter with a courteous line.', self::CLOSING),
        ]);
    }

    protected function renderer(): Renderer
    {
        return app(Renderer::class);
    }

    protected function guard(): SlotGuard
    {
        return app(SlotGuard::class);
    }
}
