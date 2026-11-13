<?php

namespace Tests\Feature\DocumentReading;

use App\Models\ReaderTrial;
use App\Models\ReaderTrialPage;
use App\Services\BoqScan\BoqReadKind;
use App\Services\DocumentReading\ReadKinds;
use App\Services\DocumentReading\ReaderControl;
use App\Services\SlipReading\BillReadKind;
use App\Services\SlipReading\SlipReadKind;
use App\Services\TenderReading\TenderReadKind;

/**
 * The readers the modules really deploy, not the stand-ins: the platform admin can make a dry run of
 * each pass of each one, which the release switch asks for, while no company is offered any of them.
 */
class ReaderTrialShippedKindsTest extends ReaderTrialTestCase
{
    private const SHIPPED = [
        'modules.boq_scan.read_kinds' => [BoqReadKind::class],
        'modules.slip_reading.read_kinds' => [SlipReadKind::class, BillReadKind::class],
        'modules.tender_reading.read_kinds' => [TenderReadKind::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // As the module config files deploy them, with no stand-in beside them.
        config(['modules.reader_stand_ins.read_kinds' => [], ...self::SHIPPED]);
    }

    public function test_the_module_config_files_deploy_these_readers(): void
    {
        foreach (self::SHIPPED as $key => $kinds) {
            $file = str_replace('.', '/', substr($key, strlen('modules.'), -strlen('.read_kinds')));
            $this->assertSame($kinds, (require config_path("modules/{$file}.php"))['read_kinds'], $file);
        }

        $this->assertEqualsCanonicalizing(['boq', 'slip', 'bill', 'tender'], array_keys(app(ReadKinds::class)->all()));
    }

    public function test_a_dry_run_is_made_of_every_pass_of_every_deployed_reader_and_the_release_asks_for_each(): void
    {
        $this->fakeReader([$this->answer('other', 3000, 400)]);
        $kinds = app(ReadKinds::class)->all();

        foreach ($kinds as $key => $kind) {
            foreach ($kind->passes() as $pass) {
                $trial = $this->makeTrial(['kind' => $key, 'pass' => $pass, 'pages_total' => 1]);
                $this->trials()->storePicture($trial, 1, $this->pagePicture(), $this->admin);
                $this->startTrial($trial->refresh());
                $this->workTheQueue();

                $trial->refresh();
                $page = $this->pageOf($trial, 1, $pass);
                $this->assertSame(ReaderTrial::DONE, $trial->status, "{$key} {$pass}");
                $this->assertContains($page->status, [ReaderTrialPage::READ, ReaderTrialPage::FAILED], "{$key} {$pass}");
                $this->assertGreaterThan(0, (int) $page->attempts, "{$key} {$pass} was sent");
            }
        }

        $this->assertNotEmpty($this->sent);

        // Each deployed reader must be ticked as tried before the readers can be offered to companies.
        $this->assertEmpty(array_diff(array_keys($kinds), app(ReaderControl::class)->features()));
    }
}
