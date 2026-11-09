<?php

namespace Tests\Feature\Drawings;

use App\Models\DrawingIssuePin;
use App\Models\DrawingSheet;
use App\Models\Issue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\BuildsTenant;
use Tests\TestCase;

class IssuePinTest extends TestCase
{
    use BuildsDrawings, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function pin($user, DrawingSheet $sheet, array $data): TestResponse
    {
        return $this->actingAs($user)->postJson(route('drawings.register.pins.store', $sheet), $data);
    }

    private function issue($project, string $title = 'Honeycombing'): Issue
    {
        return Issue::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'title' => $title, 'priority' => 'high', 'status' => 'open']);
    }

    public function test_pinning_creates_the_issue_and_links_it(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'engineer');
        $assignee = $this->member($organization, 'pm');
        $sheet = $this->scaledSheet($project);

        $this->pin($user, $sheet, ['title' => 'Crack at column', 'priority' => 'critical', 'assigned_to' => $assignee->id, 'due_date' => '2026-12-01', 'x' => 120.5, 'y' => 240])
            ->assertCreated()
            ->assertJsonPath('pin.title', 'Crack at column')
            ->assertJsonPath('pin.status', 'open')
            ->assertJsonPath('pin.x', '120.500')
            ->assertJsonPath('pin.assignee', $assignee->name);

        $issue = Issue::firstOrFail();
        $this->assertSame($project->id, $issue->project_id);
        $this->assertSame($user->id, $issue->raised_by);
        $this->assertSame('critical', $issue->priority);
        $this->assertSame($issue->id, DrawingIssuePin::firstOrFail()->issue_id);
    }

    public function test_a_new_issue_needs_a_title_and_priority(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);

        $this->pin($this->member($organization, 'pm'), $this->scaledSheet($project), ['x' => 1, 'y' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'priority']);
    }

    public function test_an_existing_issue_is_pinned_and_pinning_again_moves_it(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $sheet = $this->scaledSheet($project);
        $other = $this->makeSheet($sheet->version, 2);
        $issue = $this->issue($project);

        $this->pin($user, $sheet, ['issue_id' => $issue->id, 'x' => 10, 'y' => 10])->assertCreated();
        $this->pin($user, $other, ['issue_id' => $issue->id, 'x' => 30, 'y' => 40])->assertOk();

        $pin = DrawingIssuePin::sole();
        $this->assertSame($other->id, $pin->drawing_sheet_id);
        $this->assertSame('30.000', $pin->x);
        $this->assertSame(1, Issue::count());
    }

    public function test_repeating_a_pin_returns_the_original(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $sheet = $this->scaledSheet($project);
        $data = ['title' => 'Leak', 'priority' => 'low', 'x' => 1, 'y' => 1, 'client_uuid' => (string) Str::uuid()];

        $this->pin($user, $sheet, $data)->assertCreated();
        $this->pin($user, $sheet, $data)->assertOk();

        $this->assertSame(1, Issue::count());
        $this->assertSame(1, DrawingIssuePin::count());
    }

    public function test_a_pin_must_be_on_the_sheet(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);

        $this->pin($this->member($organization, 'pm'), $this->scaledSheet($project), ['title' => 'x', 'priority' => 'low', 'x' => 5000, 'y' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('x');

        $this->assertSame(0, Issue::count());
    }

    public function test_an_issue_from_another_project_cannot_be_pinned(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $issue = $this->issue($this->makeProject($organization));

        $this->pin($this->member($organization, 'pm'), $this->scaledSheet($project), ['issue_id' => $issue->id, 'x' => 1, 'y' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('issue_id');
    }

    public function test_an_assignee_from_another_company_is_refused(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $stranger = $this->member($this->tenant('Other Co'), 'pm');

        $this->pin($this->member($organization, 'pm'), $this->scaledSheet($project), ['title' => 'x', 'priority' => 'low', 'assigned_to' => $stranger->id, 'x' => 1, 'y' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }

    public function test_removing_a_pin_keeps_the_issue(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $id = $this->pin($user, $this->scaledSheet($project), ['title' => 'Leak', 'priority' => 'low', 'x' => 1, 'y' => 1])->json('pin.id');

        $this->actingAs($user)->deleteJson(route('drawings.register.pins.destroy', $id))->assertOk();

        $this->assertSame(0, DrawingIssuePin::count());
        $this->assertSame(1, Issue::count());
    }

    public function test_pinning_needs_the_issue_permission(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $sheet = $this->scaledSheet($project);

        foreach (['viewer', 'qs', 'accounts', 'store'] as $role) {
            $this->pin($this->member($organization, $role), $sheet, ['title' => 'x', 'priority' => 'low', 'x' => 1, 'y' => 1])->assertForbidden();
        }
    }

    public function test_other_companies_cannot_pin_or_remove(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $sheet = $this->scaledSheet($project);
        $id = $this->pin($this->member($organization, 'pm'), $sheet, ['title' => 'Leak', 'priority' => 'low', 'x' => 1, 'y' => 1])->json('pin.id');
        $outsider = $this->member($this->tenant('Other Co'), 'owner');

        $this->pin($outsider, $sheet, ['title' => 'x', 'priority' => 'low', 'x' => 1, 'y' => 1])->assertNotFound();
        $this->actingAs($outsider)->deleteJson(route('drawings.register.pins.destroy', $id))->assertNotFound();
    }

    public function test_the_viewer_lists_pins_for_its_sheet(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $sheet = $this->scaledSheet($project);
        $this->pin($user, $sheet, ['title' => 'Leak in slab', 'priority' => 'low', 'x' => 1, 'y' => 1])->assertCreated();

        $this->actingAs($user)->get(route('drawings.register.sheet', $sheet))->assertOk()->assertSee('Leak in slab');
    }
}
