<?php

namespace Tests\Feature\SiteControl;

use App\Models\BoqSection;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The hook that lets a module add its records, and the routes of its own that serve a file, to the
 * route walks from one file in MoneyWorld/, without an edit to BuildsMoneyWorld or to either walk.
 *
 * The module files here are written by each test into a folder of its own, so the walks never run them.
 */
class MoneyWorldHookTest extends TestCase
{
    use BuildsMoneyWorld {
        moneyWorldFolder as theRealFolder;
    }
    use RefreshDatabase;

    private string $folder;

    /** @var list<mixed> What the module files of a test reported, in the order they ran. */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir().'/money-world-'.Str::lower(Str::random(12));
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        if (isset($this->folder) && is_dir($this->folder)) {
            array_map('unlink', glob($this->folder.'/*') ?: []);
            rmdir($this->folder);
        }

        parent::tearDown();
    }

    /** The test's own folder stands in for MoneyWorld/. */
    protected function moneyWorldFolder(): string
    {
        return $this->folder;
    }

    public function test_module_files_are_looked_for_beside_the_trait(): void
    {
        $this->assertSame(realpath(base_path('tests/Feature/SiteControl')).'/MoneyWorld', $this->theRealFolder());
    }

    public function test_a_module_file_runs_as_the_test_on_top_of_the_core_records(): void
    {
        $this->moduleFile('finishes', <<<'PHP'
            use App\Models\BoqSection;
            use App\Models\Project;
            use Illuminate\Support\Facades\Auth;

            return function (): void {
                $section = BoqSection::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'name' => 'Finishes']);

                $this->rows[BoqSection::class] = $section;
                $this->params['kind'] = 'boq';
                $this->skippedRoutes['measurements.sheet'] = 'serves a stored sheet';
                $this->routeParams['projects.show'] = ['project' => $this->project];
                $this->routeQueries['dashboard'] = ['tab' => 'bills'];
                $this->ran[] = [Auth::id(), $this->rows[Project::class]->id, self::MONEY['boq_rate']];
            };
            PHP);

        $this->buildMoneyWorld();

        // It ran once, after the core rows were filed, with the owner signed in and the sentinel figures in reach.
        $this->assertSame([[$this->users['owner']->id, $this->project->id, '83614731.00']], $this->ran);

        $this->assertSame('Finishes', $this->rows[BoqSection::class]->name);
        $this->assertSame($this->organization->id, $this->rows[BoqSection::class]->organization_id);
        $this->assertSame($this->project->id, $this->rows[Project::class]->id);
        $this->assertSame('boq', $this->params['kind']);
        $this->assertArrayHasKey('tag', $this->params);
        $this->assertSame(['measurements.sheet' => 'serves a stored sheet'], $this->skippedRoutes);
        $this->assertSame(['dashboard' => ['tab' => 'bills']], $this->routeQueries);
        $this->assertSame($this->project->id, $this->givenParameter($this->routeCalled('projects.show'), 'project'));
    }

    public function test_module_files_run_in_name_order_and_again_for_every_build(): void
    {
        $this->moduleFile('b_second', 'return function (): void { $this->ran[] = "second"; };');
        $this->moduleFile('a_first', 'return function (): void { $this->ran[] = "first"; };');
        file_put_contents($this->folder.'/notes.md', 'Not a module file.');

        $this->buildModuleWorlds();
        $this->buildModuleWorlds();

        $this->assertSame(['first', 'second', 'first', 'second'], $this->ran);
    }

    public function test_a_world_with_no_module_file_is_left_as_it_was(): void
    {
        $this->params = ['tag' => 'EX-01'];

        $this->buildModuleWorlds();

        // A folder that is not there at all, as before the first module adds its file, is the same.
        $this->folder .= '/not-there';
        $this->buildModuleWorlds();
        $this->folder = dirname($this->folder);

        $this->assertSame(['tag' => 'EX-01'], $this->params);
        $this->assertSame([], $this->rows);
        $this->assertSame([], $this->skippedRoutes);
        $this->assertSame([], $this->routeParams);
        $this->assertSame([], $this->routeQueries);
    }

    /** @return array<string, array{0: string, 1: string}> What the file does => the words the refusal carries. */
    public static function refusedFiles(): array
    {
        $section = "\\App\\Models\\BoqSection::create(['organization_id' => \$this->organization->id, 'project_id' => \$this->project->id, 'name' => 'Finishes'])";

        return [
            'returns no closure' => ['return ["rows" => []];', 'MoneyWorld/module.php must return a closure'],
            'replaces a row that was there' => ["return function (): void { \$this->rows[\\App\\Models\\Site::class] = \\App\\Models\\Site::create(['organization_id' => \$this->organization->id, 'project_id' => \$this->project->id, 'name' => 'Tower B']); };", "replaced or removed \$this->rows['App\\Models\\Site']"],
            'removes a row that was there' => ['return function (): void { unset($this->rows[\App\Models\Site::class]); };', "replaced or removed \$this->rows['App\\Models\\Site']"],
            'replaces a plain parameter' => ['return function (): void { $this->params["tag"] = "EX-02"; };', "replaced or removed \$this->params['tag']"],
            'files a row under a name' => ["return function (): void { \$this->rows['section'] = {$section}; };", "\$this->rows takes a model class as the key and a saved row of that model as the value. Check the entry 'section'"],
            'files a row that was never saved' => ['return function (): void { $this->rows[\App\Models\BoqSection::class] = new \App\Models\BoqSection(["name" => "Finishes"]); };', "Check the entry 'App\\Models\\BoqSection'"],
            'files a row it did not find' => ['return function (): void { $this->rows[\App\Models\BoqSection::class] = \App\Models\BoqSection::query()->first(); };', "Check the entry 'App\\Models\\BoqSection'"],
            'files a row of another model' => ["return function (): void { \$this->rows[\\App\\Models\\Project::class] = {$section}; };", "Check the entry 'App\\Models\\Project'"],
            'gives a row as a plain parameter' => ['return function (): void { $this->params["project"] = $this->project; };', "\$this->params takes a route parameter name as the key and a plain value. Check the entry 'project'"],
            'lists a skipped route with no reason' => ['return function (): void { $this->skippedRoutes[] = "dashboard"; };', '$this->skippedRoutes takes a route name or URI as the key and the reason as the value'],
            'lists a skipped route with an empty reason' => ['return function (): void { $this->skippedRoutes["dashboard"] = " "; };', "Check the entry 'dashboard'"],
            'skips a route that does not exist' => ['return function (): void { $this->skippedRoutes["document-reads.pages.imagee"] = "serves a stored page"; };', 'names routes that are not GET routes, by name or by URI: document-reads.pages.imagee'],
            'skips a route that is not a GET route' => ['return function (): void { $this->skippedRoutes["logout"] = "signs out"; };', 'names routes that are not GET routes, by name or by URI: logout'],
            'gives values for a route that does not exist' => ['return function (): void { $this->routeParams["boq-scans.shoow"] = ["read" => 1]; };', 'names routes that are not GET routes, by name or by URI: boq-scans.shoow'],
            'gives a query for a route that does not exist' => ['return function (): void { $this->routeQueries["api/v1/boq-scans/{reed}/rows"] = ["page_no" => 3]; };', 'names routes that are not GET routes, by name or by URI: api/v1/boq-scans/{reed}/rows'],
            'gives a query as text' => ['return function (): void { $this->routeQueries["dashboard"] = "tab=bills"; };', '$this->routeQueries takes a route name or URI as the key and a list of values'],
            'gives no value for a route' => ['return function (): void { $this->routeParams["dashboard"] = []; };', '$this->routeParams takes a route name or URI as the key and a list of values'],
            'gives a route a row it did not find' => ['return function (): void { $this->routeParams["projects.show"] = ["project" => \App\Models\Project::query()->find(0)]; };', "\$this->routeParams['projects.show'] takes a parameter name as the key and a saved row or a plain value. Check the entry 'project'"],
            'gives a route a row that was never saved' => ['return function (): void { $this->routeParams["projects.show"] = ["project" => new \App\Models\Project]; };', "Check the entry 'project'"],
            'gives a route its values without names' => ['return function (): void { $this->routeParams["projects.show"] = [$this->project]; };', "Check the entry '0'"],
        ];
    }

    #[DataProvider('refusedFiles')]
    public function test_a_module_file_that_would_weaken_the_walks_is_refused(string $code, string $words): void
    {
        $this->smallWorld();
        $this->moduleFile('module', $code);

        $this->assertStringContainsString($words, $this->refusal());
    }

    public function test_a_later_module_file_cannot_replace_what_an_earlier_one_added(): void
    {
        $this->smallWorld();
        $this->moduleFile('a_reader', 'return function (): void { $this->skippedRoutes["dashboard"] = "serves a stored page"; $this->routeParams["projects.show"] = ["project" => 7]; };');
        $this->moduleFile('b_scan', 'return function (): void { $this->routeParams["projects.show"] = ["project" => 8]; };');

        $refusal = $this->refusal();

        $this->assertStringContainsString("MoneyWorld/b_scan.php replaced or removed \$this->routeParams['projects.show'], which the core fixtures or a module file earlier in name order had set", $refusal);
        $this->assertStringNotContainsString('a_reader', $refusal);
    }

    public function test_a_module_file_may_add_to_every_list(): void
    {
        $this->smallWorld();
        $this->moduleFile('a_reader', <<<'PHP'
            return function (): void {
                $this->rows[\App\Models\BoqSection::class] = \App\Models\BoqSection::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'name' => 'Finishes']);
                $this->params['kind'] = 'boq';
                $this->params['page_no'] = 3;
                $this->skippedRoutes['measurements.sheet'] = 'serves a stored sheet';
                $this->skippedRoutes['api/v1/approvals'] = 'serves a stored file';
                $this->routeParams['projects.show'] = ['project' => $this->project];
                $this->routeQueries['api/v1/projects/{project}/boq'] = ['page_no' => 3];
            };
            PHP);
        // Filing a model only when nobody has, or putting the same value back, changes nothing and is let through.
        $this->moduleFile('b_scan', 'return function (): void { $this->rows[\App\Models\Site::class] ??= \App\Models\Site::create(["organization_id" => $this->organization->id, "project_id" => $this->project->id, "name" => "Tower B"]); $this->params["kind"] = "boq"; };');

        $this->buildModuleWorlds();

        $this->assertSame([Site::class, BoqSection::class], array_keys($this->rows));
        $this->assertSame('Tower A', $this->rows[Site::class]->name);
        $this->assertSame(1, Site::query()->count());
        $this->assertSame(['tag' => 'EX-01', 'kind' => 'boq', 'page_no' => 3], $this->params);
        $this->assertSame(['measurements.sheet', 'api/v1/approvals'], array_keys($this->skippedRoutes));
        $this->assertSame(['projects.show'], array_keys($this->routeParams));
        $this->assertSame(['api/v1/projects/{project}/boq' => ['page_no' => 3]], $this->routeQueries);
    }

    public function test_a_walk_leaves_out_its_own_routes_and_the_ones_a_module_listed(): void
    {
        $this->skippedRoutes = [
            'dashboard' => 'listed by name',
            'api/v1/approvals' => 'has no name, so listed by URI',
            'projects/{project}' => 'has a name, listed by URI all the same',
        ];

        $this->assertTrue($this->skipsRoute($this->routeCalled('dashboard'), []));
        $this->assertTrue($this->skipsRoute($this->routeCalled('api/v1/approvals'), []));
        $this->assertTrue($this->skipsRoute($this->routeCalled('projects.show'), []));

        // The walk's own list still counts, by name and by URI, and nothing else is left out.
        $this->assertTrue($this->skipsRoute($this->routeCalled('ra-bills.show'), ['storage.local', 'ra-bills.show']));
        $this->assertTrue($this->skipsRoute($this->routeCalled('ra-bills.show'), ['ra-bills/{bill}']));
        $this->assertFalse($this->skipsRoute($this->routeCalled('ra-bills.show'), ['storage.local']));
        $this->assertFalse($this->skipsRoute($this->routeCalled('api/v1/projects/{project}/boq'), ['storage.local']));

        $this->skippedRoutes = [];
        $this->assertFalse($this->skipsRoute($this->routeCalled('dashboard'), []));
    }

    public function test_a_route_takes_the_value_a_module_gave_for_it_before_the_general_one(): void
    {
        $this->smallWorld();
        $second = $this->makeProject($this->organization, ['name' => 'Lakeview Villas']);

        $this->params = ['tag' => 'EX-01', 'project' => 5];
        $this->routeParams = [
            'projects.show' => ['project' => $second],
            'api/v1/projects/{project}/boq' => ['project' => 77],
            'projects/{project}/measurement-sheet.pdf' => ['project' => 'by-uri'],
        ];

        // A row is given by its route key, a plain value as it is, and a named route may be listed by its URI.
        $this->assertSame($second->getRouteKey(), $this->givenParameter($this->routeCalled('projects.show'), 'project'));
        $this->assertSame(77, $this->givenParameter($this->routeCalled('api/v1/projects/{project}/boq'), 'project'));
        $this->assertSame('by-uri', $this->givenParameter($this->routeCalled('measurements.sheet'), 'project'));

        // Every other route keeps the general value, and a parameter nobody gave is left to the model's row.
        $this->assertSame(5, $this->givenParameter($this->routeCalled('ra-bills.show'), 'project'));
        $this->assertSame('EX-01', $this->givenParameter($this->routeCalled('projects.show'), 'tag'));
        $this->assertNull($this->givenParameter($this->routeCalled('ra-bills.show'), 'bill'));
    }

    public function test_a_route_is_visited_with_the_query_a_module_gave_unless_the_walk_has_its_own(): void
    {
        $own = ['measurements.sheet' => ['from' => '2026-01-01', 'to' => '2026-12-31']];

        $this->routeQueries = [
            'dashboard' => ['tab' => 'bills'],
            'api/v1/projects/{project}/boq' => ['page_no' => 3, 'q' => 'rcc m25'],
            'measurements.sheet' => ['from' => '2027-01-01'],
            'ra-bills/{bill}' => ['print' => 1],
        ];

        $this->assertSame('?tab=bills', $this->queryFor($this->routeCalled('dashboard'), $own));
        $this->assertSame('?page_no=3&q=rcc+m25', $this->queryFor($this->routeCalled('api/v1/projects/{project}/boq'), $own));
        $this->assertSame('?print=1', $this->queryFor($this->routeCalled('ra-bills.show'), $own));
        $this->assertSame('?from=2026-01-01&to=2026-12-31', $this->queryFor($this->routeCalled('measurements.sheet'), $own));
        $this->assertSame('', $this->queryFor($this->routeCalled('projects.show'), $own));
    }

    public function test_a_route_nobody_gave_a_value_for_is_named_with_what_to_do_about_it(): void
    {
        $help = $this->unfilledRoutes(['boq-scans.show', 'api/v1/boq-scans/{read}']);

        $this->assertStringEndsWith(': boq-scans.show, api/v1/boq-scans/{read}', $help);

        foreach (['tests/Feature/SiteControl/MoneyWorld', '$this->rows', '$this->params', '$this->routeParams', '$this->skippedRoutes'] as $place) {
            $this->assertStringContainsString($place, $help);
        }
    }

    /** One company, one project and one filed row: enough for a module file to stand on. */
    private function smallWorld(): void
    {
        $this->organization = $this->tenant('Sentinel Infra');
        $this->project = $this->makeProject($this->organization);
        $this->rows = [Site::class => Site::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'name' => 'Tower A'])];
        $this->params = ['tag' => 'EX-01'];
    }

    private function moduleFile(string $module, string $code): void
    {
        file_put_contents("{$this->folder}/{$module}.php", "<?php\n\n{$code}\n");
    }

    /** Why the module files were turned away. Fails when they were let through. */
    private function refusal(): string
    {
        try {
            $this->buildModuleWorlds();
        } catch (AssertionFailedError $refused) {
            return $refused->getMessage();
        }

        $this->fail('The module files were let through.');
    }

    /** The GET route with this name, or with this URI. */
    private function routeCalled(string $label): RouteDefinition
    {
        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && in_array($label, [$route->getName(), $route->uri()], true)) {
                return $route;
            }
        }

        $this->fail("No GET route is called {$label}.");
    }
}
