<?php

namespace Tests\Feature\TenderReading;

use App\Services\DocumentReading\ReaderRequest;
use App\Services\DocumentReading\ReadIntake;
use App\Services\DocumentReading\ReadQuote;
use App\Services\DocumentReading\ReadTending;
use App\Services\TenderReading\TenderReadKind;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\DocumentReading\ReadingTestCase;

/**
 * The fixed part of the tender reader's prompt, counted for each of its two passes with what that pass
 * is really sent: the text instructions for runs of text pages, the picture instructions for a page
 * picture. Counted the other way, a text read would be priced with the shorter picture prompt.
 */
class TenderPromptCountTest extends ReadingTestCase
{
    /** @var list<string> The fixed instructions of every count asked for, in order. */
    private array $counted = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutShippedReaders();
        config(['modules.tender_reading.read_kinds' => [TenderReadKind::class]]);
        $this->release();
        $this->connect();

        // A count that depends on the words, so each pass's figure says which instructions were counted.
        Http::fake(['api.anthropic.com/v1/messages/count_tokens' => function (Request $request) {
            $system = (string) $request->data()['system'][0]['text'];
            $this->counted[] = $system;

            return Http::response(['input_tokens' => match (true) {
                str_contains($system, trim(TenderReadKind::TEXT_INSTRUCTIONS)) => 2100,
                str_contains($system, trim(TenderReadKind::IMAGE_INSTRUCTIONS)) => 900,
                default => 1,
            }]);
        }]);
    }

    public function test_the_text_pass_is_counted_with_the_text_prompt_and_the_picture_pass_with_the_picture_prompt(): void
    {
        // The kind's own figures: a picture is read with "image", and nothing follows it on the page.
        $this->assertSame(['image'], ReadIntake::picturePasses(TenderReadKind::KEY));

        $this->assertSame(2, app(ReadTending::class)->measurePrompts());

        $this->assertCount(2, $this->counted);
        $this->assertSame(
            [ReaderRequest::RULES."\n\n".trim(TenderReadKind::TEXT_INSTRUCTIONS), ReaderRequest::RULES."\n\n".trim(TenderReadKind::IMAGE_INSTRUCTIONS)],
            $this->counted,
        );

        // The quotes use each figure: a text read is no longer priced with the shorter picture prompt.
        $this->assertSame(2100, app(ReadQuote::class)->promptTokens(TenderReadKind::KEY, TenderReadKind::TEXT));
        $this->assertSame(900, app(ReadQuote::class)->promptTokens(TenderReadKind::KEY, TenderReadKind::IMAGE));

        // Nothing but the counts was asked, and no picture went with them.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/messages/count_tokens')
            && ! str_contains((string) json_encode($request->data()), '"image"'));
    }

    public function test_every_pass_that_follows_the_picture_pass_reads_the_same_picture(): void
    {
        // The scanned BOQ's amount column is read from the picture its rows were read from; the slip has one picture pass.
        $this->assertSame(['rows', 'amounts'], ReadIntake::picturePasses('boq'));
        $this->assertSame(['main'], ReadIntake::picturePasses('slip'));
        $this->assertSame(['main'], ReadIntake::picturePasses('bill'));

        // A kind without a picture pass reads text on every pass, and a pass without figures is not one.
        config(['modules.document_reading.kinds.tender.picture_pass' => null]);
        $this->assertSame([], ReadIntake::picturePasses(TenderReadKind::KEY));
        config(['modules.document_reading.kinds.boq.passes.rows.then' => ['amounts', 'nothing_like_it', 'rows']]);
        $this->assertSame(['rows', 'amounts'], ReadIntake::picturePasses('boq'));
    }
}
