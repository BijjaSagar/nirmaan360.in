<?php

namespace Tests\Feature\RateHelper;

use App\Models\RateLibraryItem;
use App\Services\RateHelper\Contracts\RateSource;
use App\Services\RateHelper\RateSources;
use App\Services\RateHelper\Sources\OwnBoqSource;
use App\Services\RateHelper\Sources\OwnTenderSource;
use App\Services\RateHelper\Sources\PurchasesSource;
use App\Services\RateHelper\Sources\RateLibrarySource;
use App\Services\RateHelper\Sources\ScheduleSource;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use LogicException;
use stdClass;

/** Where rates may come from, what the screen says about each source, and the schedule that is not there. */
class SourcesTest extends RateHelperTestCase
{
    public function test_the_sources_are_the_companys_own_records_and_a_schedule_that_is_not_connected(): void
    {
        $sources = app(RateSources::class)->open();

        $this->assertSame(
            [RateLibrarySource::KEY, OwnBoqSource::KEY, OwnTenderSource::KEY, PurchasesSource::KEY, ScheduleSource::KEY],
            array_keys($sources),
        );

        foreach ($sources as $key => $source) {
            $this->assertInstanceOf(RateSource::class, $source);
            $this->assertSame($key, $source->key());
            $this->assertLessThanOrEqual(40, strlen($key), 'A key fits the accepted_source column.');
            $this->assertNotSame('', $source->label());
        }

        // Each piece of work gets its own instances, so nothing read for one request is used by the next.
        $this->assertNotSame($sources[RateLibrarySource::KEY], app(RateSources::class)->open()[RateLibrarySource::KEY]);
    }

    public function test_the_schedule_source_holds_nothing_and_says_it_is_not_connected(): void
    {
        $schedule = new ScheduleSource;
        $line = $this->line('RCC M25 in slab');
        $this->actingAs($this->owner);

        $this->assertFalse($schedule->isConnected($this->org));
        $this->assertFalse($schedule->isConnected($this->tenant('Another Buildcon', 'enterprise')));
        $this->assertFalse($schedule->allows($this->owner));
        $this->assertSame([], [...$schedule->candidates($this->owner, $line)]);

        foreach ([0, 1, $line->id, 999999] as $rowId) {
            $this->assertNull($schedule->rateOf($this->owner, $rowId));
        }

        // The class has no state and reads nothing: no property, no model, no table, no file.
        $this->assertSame([], (new \ReflectionClass($schedule))->getProperties());
        $code = File::get(app_path('Services/RateHelper/Sources/ScheduleSource.php'));
        $this->assertStringNotContainsString('DB::', $code);
        $this->assertStringNotContainsString('::query', $code);
        $this->assertStringNotContainsString('config(', $code);

        // The module's migration makes two tables, and neither holds a schedule.
        $migration = File::get(database_path('migrations/2027_01_15_100001_create_rate_helper_tables.php'));
        $this->assertSame(2, substr_count($migration, 'Schema::create('));
        $this->assertStringNotContainsStringIgnoringCase('schedule', $migration);

        foreach (['rate_schedules', 'schedule_rates', 'schedule_items', 'schedules_of_rates', 'government_rates'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $run = $this->startRun($this->owner, $line);
        $this->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Government schedule: none loaded.')
            ->assertSee('Not connected');
    }

    public function test_nothing_in_the_module_names_a_schedule_an_edition_or_an_item_code(): void
    {
        $files = [
            ...File::allFiles(app_path('Services/RateHelper')),
            ...File::allFiles(app_path('Http/Controllers/RateHelper')),
            ...File::allFiles(resource_path('views/modules/rate_helper')),
            ...File::allFiles(lang_path('modules/rate_helper')),
            new \SplFileInfo(config_path('modules/rate_helper.php')),
            new \SplFileInfo(base_path('routes/modules/rate_helper.php')),
            new \SplFileInfo(database_path('migrations/2027_01_15_100001_create_rate_helper_tables.php')),
            new \SplFileInfo(app_path('Models/RateSuggestionRun.php')),
            new \SplFileInfo(app_path('Models/RateSuggestionLine.php')),
        ];

        $this->assertGreaterThan(20, count($files));

        // The short names schedules of rates go by, a year written as an edition, and a numbered schedule item.
        $patterns = [
            'a schedule by its short name' => '/\b(DSR|SSR|SOR|CSR|USOR|CPWD|PWD|MJP|WRD|MORTH|NHAI|MSRDC|CIDCO|PMC|BMC)\b/',
            'a schedule named in words' => '/\b(district|state|delhi|common|unified)\s+schedule\b/i',
            'an edition year' => '/\b(19|20)\d\d\s?[-–\/]\s?\d\d(\d\d)?\b/',
            'a schedule item number' => '/\b(item|sr\.?|s\.?no\.?)\s*(no\.?|code)?\s*[:#]?\s*\d+(\.\d+)+\b/i',
        ];

        foreach ($files as $file) {
            $text = File::get($file->getPathname());

            foreach ($patterns as $what => $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $text, str_replace(base_path().'/', '', $file->getPathname()).' holds '.$what);
            }
        }

        // The check does find what it looks for.
        $this->assertMatchesRegularExpression($patterns['a schedule by its short name'], 'As per DSR');
        $this->assertMatchesRegularExpression($patterns['an edition year'], 'edition 2023-24');
        $this->assertMatchesRegularExpression($patterns['a schedule item number'], 'Item No. 13.4.1');
    }

    public function test_a_class_that_is_not_a_rate_source_cannot_be_listed(): void
    {
        config(['modules.rate_helper.sources' => [RateLibrarySource::class, stdClass::class]]);

        $this->expectException(LogicException::class);
        app(RateSources::class)->open();
    }

    public function test_a_source_taken_off_the_list_stops_offering_and_stops_being_accepted(): void
    {
        $line = $this->line('RCC M25 in slab');
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $run = $this->startRun($this->owner, $line);

        config(['modules.rate_helper.sources' => [OwnBoqSource::class, ScheduleSource::class]]);

        $this->actingAs($this->owner)->get(route('rate-suggestions.show', $run))->assertOk()->assertDontSee('6,200.00')->assertDontSee('Accept this rate');
        $this->accept($this->owner, $run, $line, 'C1')->assertSessionHasErrors('candidate');
        $this->assertSame('0.00', $line->fresh()->rate);
        $this->assertSame([], $this->shortlist($this->startRun($this->owner, $line), $line));
    }

    public function test_showing_a_long_run_reads_each_source_once_not_once_per_line(): void
    {
        $cement = $this->material('Cement', 'bag');
        $this->purchase($cement, '100.000', '38000.00', '2026-10-01');
        $this->libraryRate('Waterproofing coat to terrace', 'sqm', '410.00');
        $this->line('Waterproofing coat to terrace', 'sqm', '395.00', $this->other);
        $this->tenderLine('Waterproofing coat to terrace', 'sqm', '430.00');

        $lines = [];

        foreach (range(1, 30) as $number) {
            $lines[] = $line = $this->line('Waterproofing coat to terrace, bay '.$number, 'sqm');
            $this->norm($line, $cement, '0.200000');
        }

        $few = $this->startRun($this->owner, ...array_slice($lines, 0, 3));
        $many = $this->startRun($this->owner, ...$lines);
        $this->assertCount(4, $this->shortlist($many, $lines[29]));

        $count = function ($run): int {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->actingAs($this->owner)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('₹76.00');
            DB::getEventDispatcher()->forget(QueryExecuted::class);

            return $queries;
        };

        $forFew = $count($few);
        $forMany = $count($many);

        $this->assertGreaterThan(0, $forFew);
        $this->assertSame($forFew, $forMany, 'Ten times the lines must not mean more reads.');
    }

    public function test_every_rate_is_shown_with_its_source_its_date_and_how_it_came_to_be(): void
    {
        $this->travelTo('2026-11-13 10:00:00');

        $line = $this->line('RCC M25 in slab');
        $library = $this->libraryRate('RCC M25 in slab', 'cum', '6200.00', null, ['material_rate' => '4000.00', 'labour_rate' => '1500.00', 'profit_rate' => '700.00']);
        $boq = $this->line('RCC M25 in slab', 'cum', '5800.00', $this->other);
        $tender = $this->tenderLine('RCC M25 in slab', 'cum', '7100.00', 'lost', ['title' => 'Ward office building', 'reference' => 'DC/2026/14']);

        // Each row was last touched on its own day.
        RateLibraryItem::withoutGlobalScopes()->whereKey($library->id)->update(['updated_at' => '2026-10-02 09:00:00']);
        $boq->newQueryWithoutScopes()->whereKey($boq->id)->update(['updated_at' => '2026-04-10 09:00:00']);
        $tender->newQueryWithoutScopes()->whereKey($tender->id)->update(['updated_at' => '2025-12-20 09:00:00']);

        $run = $this->startRun($this->owner, $line);
        $page = $this->actingAs($this->owner)->get(route('rate-suggestions.show', $run))->assertOk();

        $page->assertSeeInOrder(['Rate library', 'as entered', 'last edited 02 Oct 2026', 'Material', '4,000.00', 'Labour', '1,500.00', 'Profit', '700.00', '₹6,200.00'])
            ->assertSee('BOQs of your other projects · GP2 · Maplewood Plaza')
            ->assertSee('last edited 10 Apr 2026')
            ->assertSee('Your past tenders · DC/2026/14 · Ward office building')
            ->assertSee('last edited 20 Dec 2025')
            ->assertSee('From your own records.')
            ->assertSee('Nothing is estimated.');

        // 180 days is the default: the BOQ line (217 days) and the tender line are old, the library rate is not.
        $this->assertSame(180, config('modules.rate_helper.stale_after_days'));
        $this->assertSame(2, substr_count($page->getContent(), '>old</span>'));

        config(['modules.rate_helper.stale_after_days' => 30]);
        $this->assertSame(3, substr_count($this->get(route('rate-suggestions.show', $run))->getContent(), '>old</span>'));

        config(['modules.rate_helper.stale_after_days' => 400]);
        $this->assertSame(0, substr_count($this->get(route('rate-suggestions.show', $run))->getContent(), '>old</span>'));
    }
}
