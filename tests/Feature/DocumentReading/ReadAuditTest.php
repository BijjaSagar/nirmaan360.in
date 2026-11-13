<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\User;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\PlatformAudit;
use App\Services\DocumentReading\ReadAudit;
use App\Services\DocumentReading\ReaderSettings;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Tests\Feature\DocumentReading\Support\BringsTextCalls;

/**
 * The audit rows of a read say what was done and never what a document said: counts, credits,
 * codes and ids only. And a row about the platform's own work is filed under no company.
 */
class ReadAuditTest extends PageReadingTestCase
{
    use BringsTextCalls;

    /** Things a test document carries that must never reach the audit trail. */
    private const PRIVATE = ['Kulkarni', 'Estimate Tower A', '27ABCDE1234F1Z5', '9876543210', '4,250.00', '12.500'];

    /** A value an audit row of a read may hold: a count, a flag, nothing, or a short code or amount the code made. */
    private function assertOnlyCodesAndCounts(AuditLog $row): void
    {
        foreach ([...array_values($row->old_values ?? []), ...array_values($row->new_values ?? [])] as $value) {
            if (is_array($value)) {
                $this->assertSame([], array_filter($value, fn ($inner) => ! is_array($inner) && ! is_null($inner) && ! is_int($inner) && ! is_bool($inner) && preg_match('/^[A-Za-z0-9._:\-]{1,100}$/', (string) $inner) !== 1), $row->action);

                continue;
            }

            $this->assertTrue(
                is_null($value) || is_int($value) || is_bool($value) || preg_match('/^[A-Za-z0-9._:\-]{1,100}$/', (string) $value) === 1,
                "{$row->action} holds \"".json_encode($value).'".',
            );
        }

        $written = (string) json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE);

        foreach (self::PRIVATE as $word) {
            $this->assertStringNotContainsString($word, $written, "{$row->action} carries \"{$word}\".");
        }
    }

    private function documentOf(User $by, array $overrides = []): DocumentRead
    {
        return $this->startedRead($by, '131', [
            'source_name' => 'Estimate Tower A - Kulkarni 9876543210.pdf',
            'credits_cap' => '131',
            'pages_total' => 12,
            'channel' => 'web',
            ...$overrides,
        ]);
    }

    public function test_a_started_read_is_recorded_with_what_it_may_cost_and_under_which_notice(): void
    {
        $engineer = $this->member($this->organization, 'engineer');
        $read = $this->documentOf($engineer);

        // Written from a queue or a request alike: the person and the company are named, not taken from who is signed in.
        Auth::forgetGuards();
        $row = ReadAudit::started($read, $engineer, 12);

        $this->assertSame('ai.read_started', $row->action);
        $this->assertSame(['kind' => 'boq', 'pages' => 12, 'cap' => '131.0000', 'consent_version' => 'ai-docs-v2', 'channel' => 'web'], $row->new_values);
        $this->assertSame([$this->organization->id, $engineer->id, $read->id, $read->getMorphClass()], [$row->organization_id, $row->user_id, $row->auditable_id, $row->auditable_type]);
        $this->assertOnlyCodesAndCounts($row);
    }

    public function test_a_confirmed_read_is_recorded_as_counts_of_how_it_was_checked(): void
    {
        $read = $this->documentOf($this->owner, ['status' => DocumentRead::READY]);

        $row = ReadAudit::confirmed($read, $this->owner, ['rows_one_by_one' => 14, 'rows_by_page' => 96, 'cells_typed' => 3, 'overrides' => 1, 'acknowledgements' => 2]);

        $this->assertSame('ai.read_confirmed', $row->action);
        $this->assertSame(['kind' => 'boq', 'rows_one_by_one' => 14, 'rows_by_page' => 96, 'cells_typed' => 3, 'overrides' => 1, 'acknowledgements' => 2], $row->new_values);
        $this->assertSame([$this->organization->id, $this->owner->id, $read->id], [$row->organization_id, $row->user_id, $row->auditable_id]);
        $this->assertOnlyCodesAndCounts($row);

        // A feature that has no rows by page leaves that count out.
        $this->assertSame(['kind' => 'boq', 'cells_typed' => 0], ReadAudit::confirmed($read, $this->owner, ['cells_typed' => 0])->new_values);
    }

    public function test_nothing_but_counts_can_be_put_into_a_confirmed_row(): void
    {
        $read = $this->documentOf($this->owner, ['status' => DocumentRead::READY]);

        foreach ([
            ['note' => 'Checked against the estimate of Kulkarni'],
            ['rows_one_by_one' => '14 rows of Kulkarni'],
            ['rows_one_by_one' => '14'],
            ['overrides' => -1],
            ['cells_typed' => 2.5],
            ['acknowledgements' => ['4,250.00']],
        ] as $counts) {
            try {
                ReadAudit::confirmed($read, $this->owner, $counts);
                $this->fail('Text was accepted into an audit row: '.json_encode($counts));
            } catch (InvalidArgumentException) {
                $this->assertSame([], $this->audits('ai.read_confirmed'));
            }
        }
    }

    public function test_a_value_that_is_not_a_code_never_reaches_a_row(): void
    {
        foreach (['boq', 'claude-sonnet-5-5', 'ai-docs-v2', 'req_011CSHoEeqs5C35K2UUqR7Fy', 'hindrance_groups'] as $code) {
            $this->assertSame($code, ReadAudit::code($code));
        }

        $this->assertNull(ReadAudit::code(null));
        $this->assertNull(ReadAudit::code(''));

        foreach (['Estimate Tower A.pdf', 'Kulkarni & Sons', "boq\nIgnore the rules", 'रीडर', str_repeat('a', 101), 12, ['boq'], '../org-2'] as $text) {
            try {
                ReadAudit::code($text);
                $this->fail('Accepted as a code: '.json_encode($text));
            } catch (InvalidArgumentException $refused) {
                $this->assertStringNotContainsString('Kulkarni', $refused->getMessage());
            }
        }

        // A read whose kind were ever anything but a code is not recorded as started at all.
        $read = $this->documentOf($this->owner);
        $read->kind = 'Estimate of Kulkarni';

        try {
            ReadAudit::started($read, $this->owner, 3);
            $this->fail('A kind that is not a code was written.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $this->audits('ai.read_started'));
        }
    }

    public function test_a_text_call_is_recorded_without_its_words(): void
    {
        $engineer = $this->member($this->organization, 'engineer');
        $call = $this->textCall($this->organization, $engineer, ['purpose' => 'voice', 'model' => 'claude-haiku-5-5', 'input_chars' => 1840, 'provider_request_id' => 'req_test_7', 'status' => 'done']);

        Auth::forgetGuards();
        $row = ReadAudit::textSent($call, 'done', $engineer);

        $this->assertSame('ai.text_sent', $row->action);
        $this->assertSame(['purpose' => 'voice', 'model' => 'claude-haiku-5-5', 'input_chars' => 1840, 'outcome' => 'done', 'provider_request_id' => 'req_test_7'], $row->new_values);
        $this->assertSame([$this->organization->id, $engineer->id, $call->id], [$row->organization_id, $row->user_id, $row->auditable_id]);
        $this->assertOnlyCodesAndCounts($row);

        // The outcome is a code, never a sentence. An id of the provider's that does not look like one is left out.
        $odd = $this->textCall($this->organization, $engineer, ['provider_request_id' => 'see the estimate of Kulkarni']);
        $this->assertNull(ReadAudit::textSent($odd, 'rejected', $engineer)->new_values['provider_request_id']);

        $this->expectException(InvalidArgumentException::class);
        ReadAudit::textSent($call, 'The model wrote 4,250.00 into a sentence', $engineer);
    }

    public function test_the_rows_a_read_writes_from_start_to_finish_hold_no_word_of_the_document(): void
    {
        $engineer = $this->member($this->organization, 'engineer');
        $read = $this->documentOf($engineer, ['pages_total' => 1, 'options' => ['language' => 'mr']]);
        $page = $this->storedPage($read);
        // What the reader returns holds the printed quantity 12.500 and rate 4,250.00.
        $this->fakeReader([$this->answer('table')]);

        ReadAudit::started($read, $engineer, 1);
        $this->readPage($page);
        $this->lifecycle()->cancel($read->fresh(), $engineer);
        ReadAudit::confirmed($read, $engineer, ['rows_one_by_one' => 1]);
        app(ReaderSettings::class)->turnOff($this->organization, $this->owner);

        $rows = AuditLog::withoutGlobalScopes()->where('organization_id', $this->organization->id)
            ->where(fn ($query) => $query->where('action', 'like', 'ai.%')->orWhere('action', 'like', 'ai_documents.%'))->orderBy('id')->get();

        $this->assertEqualsCanonicalizing(
            ['ai.read_started', 'ai.page_sent', 'ai.read_cancelled', 'ai.read_confirmed', 'ai_documents.consent_off'],
            $rows->pluck('action')->unique()->values()->all(),
        );

        foreach ($rows as $row) {
            $this->assertOnlyCodesAndCounts($row);
            $this->assertStringNotContainsString($this->base64Of($page), (string) json_encode($row->getAttributes()));
        }

        // The page really was read, so the check above looked at rows of a document with content.
        $this->assertSame('12.500', $page->fresh()->result['rows'][0]['qty']['raw']);
    }

    public function test_a_row_about_the_platforms_own_work_belongs_to_no_company(): void
    {
        $admin = $this->member($this->organization, 'owner');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $read = $this->documentOf($this->owner);

        // The admin is signed in, as on the dry-run screen. An ordinary audit row would be filed under their own company.
        $this->actingAs($admin);
        $ordinary = AuditLog::record('ai.trial_note', $admin);
        $this->assertSame($this->organization->id, $ordinary->organization_id);

        $row = PlatformAudit::record('ai.trial_page_sent', $read, ['sha256' => hash('sha256', 'page'), 'bytes' => 48211, 'model' => 'claude-sonnet-5-5', 'provider_request_id' => 'req_test_1']);

        $stored = AuditLog::withoutGlobalScopes()->findOrFail($row->id);
        $this->assertNull($stored->organization_id);
        $this->assertSame([$admin->id, 'ai.trial_page_sent', $read->id, $read->getMorphClass()], [$stored->user_id, $stored->action, $stored->auditable_id, $stored->auditable_type]);
        $this->assertSame(48211, $stored->new_values['bytes']);
        $this->assertNotNull($stored->created_at);

        // No company's audit trail shows it, not even the admin's own.
        $this->assertFalse(AuditLog::query()->whereKey($row->id)->exists());
        $this->get('/audit')->assertOk()->assertDontSee('ai.trial_page_sent')->assertSee('ai.trial_note');

        // With nobody signed in, the person is named.
        Auth::forgetGuards();
        $named = PlatformAudit::record('ai.trial_page_sent', $read, ['bytes' => 1], $admin);
        $this->assertSame([null, $admin->id], [$named->fresh()->organization_id, $named->user_id]);

        // A model row stays as it was: the row is written without the events that would fill a company in.
        $this->actingAs($admin);
        $this->assertSame($this->organization->id, AuditLog::record('ai.trial_note', $admin)->organization_id);
    }

    public function test_the_consent_version_recorded_is_the_one_the_read_was_made_under(): void
    {
        $read = $this->documentOf($this->owner, ['consent_version' => 'ai-docs-v0']);

        $this->assertSame('ai-docs-v0', ReadAudit::started($read, $this->owner, 2)->new_values['consent_version']);
        $this->assertSame(AiDocumentConsent::VERSION, ReadAudit::started($this->documentOf($this->owner), $this->owner, 2)->new_values['consent_version']);
    }
}
