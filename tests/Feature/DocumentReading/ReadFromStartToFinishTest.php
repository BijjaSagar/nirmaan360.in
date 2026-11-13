<?php

namespace Tests\Feature\DocumentReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ReadLifecycle;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DocumentReading\Support\FakeKind;

/**
 * A whole read on a real queue, the way a start will drive it: a hold, then one call to move it
 * along, and every page after that is sent by the page before it.
 */
class ReadFromStartToFinishTest extends PageReadingTestCase
{
    private function quoted(int $pages): DocumentRead
    {
        $read = $this->makeRead($this->organization, $this->owner, ['consent_version' => AiDocumentConsent::VERSION, 'pages_total' => $pages]);

        foreach (range(1, $pages) as $number) {
            $this->storedPage($read, $number, ['status' => DocumentReadPage::WAITING]);
        }

        return $read;
    }

    public function test_three_pages_with_two_passes_each_are_read_one_after_another_on_the_queue(): void
    {
        // The jobs run as they are handed over, as on a development machine without a worker.
        Queue::swap(Queue::getFacadeRoot()->queue);
        config(['queue.default' => 'sync']);

        $read = $this->quoted(3);

        // First pass: a table on pages 1 and 3, a blank page 2. Second pass on the two tables.
        $this->fakeReader([function (array $body) {
            $second = $body['model'] === 'claude-haiku-5-5';
            $blank = str_contains($body['messages'][0]['content'][1]['text'], 'Page 2 of 3');

            return $this->answer($blank ? 'blank' : 'table', 5956, $second ? 1200 : ($blank ? 40 : 4500));
        }]);

        $this->credits()->hold($read, '60', $this->owner);
        $state = $this->lifecycle()->advance($read);

        // That one call ran the whole read.
        $this->assertSame(ReadLifecycle::DISPATCHED, $state);
        Http::assertSentCount(5);

        $order = array_map(fn (array $body) => [$body['model'], preg_replace('/^Page (\d) of 3.*/', '$1', $body['messages'][0]['content'][1]['text'])], $this->sent);
        $this->assertSame([
            ['claude-sonnet-5-5', '1'], ['claude-haiku-5-5', '1'],
            ['claude-sonnet-5-5', '2'],
            ['claude-sonnet-5-5', '3'], ['claude-haiku-5-5', '3'],
        ], $order);

        $read->refresh();
        $this->assertSame([DocumentRead::READY, 3, 0, '0.0000'], [$read->status, $read->pages_read, $read->pages_failed, $read->credits_held]);

        // Two pages at 6.6588 + 0.1400 each; the blank page costs the company nothing and 1.4406 of the limit.
        $this->assertSame('13.5976', $read->credits_used);
        $this->assertSame('1.4406', $read->credits_burned);
        $this->assertSame('0.128528', $read->cost_usd);
        $this->assertSame('0.012312', $read->absorbed_usd);
        $this->assertSame('486.4024', $this->wallet($this->organization)->available());
        $this->assertSame('486.4024', $this->credits()->free($this->organization));
        $this->assertLedgerMatchesWallet($this->organization);
        $this->assertCount(4, $this->usageLines($this->organization));

        $pages = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->get();
        $this->assertCount(5, $pages);
        $this->assertSame([DocumentReadPage::READ], $pages->pluck('status')->unique()->values()->all());
        $this->assertSame((string) $read->credits_used, (string) $pages->reduce(fn ($sum, $page) => $sum->plus($page->credits), Dec::of(0))->toScale(4));
        $this->assertSame((string) $read->cost_usd, (string) $pages->reduce(fn ($sum, $page) => $sum->plus($page->cost_usd), Dec::of(0))->toScale(6));

        $this->assertCount(5, FakeKind::$staged);
        $this->assertSame([$read->id], FakeKind::$finished);
        $this->assertCount(5, $this->audits('ai.page_sent'));
        $this->assertCount(1, $this->audits('ai.read_finished'));
        $this->assertSame(['The reader has finished: scanned BOQ pages'], $this->noticesOf($this->owner));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_read_that_runs_into_its_limit_stops_between_pages_and_goes_on_after_a_new_hold(): void
    {
        Queue::swap(Queue::getFacadeRoot()->queue);
        config(['queue.default' => 'sync']);

        $read = $this->quoted(3);
        $this->fakeReader([$this->answer('other')]);

        // Enough for one page and part of the second.
        $this->credits()->hold($read, '10', $this->owner);
        $read->forceFill(['credits_cap' => '30'])->save();
        $this->lifecycle()->advance($read);

        $read->refresh();
        $this->assertSame([DocumentRead::PAUSED, DocumentRead::PAUSE_CREDIT_CAP, 2], [$read->status, $read->pause_reason, $read->pages_read]);
        $this->assertSame(['10.0000', '3.3176', '0.0000'], [$read->credits_used, $read->credits_burned, $read->credits_held]);
        $this->assertSame('16.6824', $read->capLeft());
        Http::assertSentCount(2);
        $this->assertSame(['The reader has paused: scanned BOQ pages'], $this->noticesOf($this->owner));

        // The person continues it: a fresh hold of what is left of the limit, and one call to move it along.
        $this->credits()->hold($read, $read->capLeft(), $this->owner);
        $this->lifecycle()->advance($read);

        $read->refresh();
        $this->assertSame([DocumentRead::READY, 3, '16.6588'], [$read->status, $read->pages_read, $read->credits_used]);
        Http::assertSentCount(3);
        $this->assertSame('483.3412', $this->wallet($this->organization)->available());
        $this->assertLedgerMatchesWallet($this->organization);
    }
}
