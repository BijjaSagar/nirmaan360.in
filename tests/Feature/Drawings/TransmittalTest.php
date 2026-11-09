<?php

namespace Tests\Feature\Drawings;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\DrawingTransmittal;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\BuildsTenant;
use Tests\TestCase;

class TransmittalTest extends TestCase
{
    use BuildsDrawings, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function issue($user, $project, array $versions, array $overrides = []): TestResponse
    {
        return $this->actingAs($user)->post(route('drawings.transmittals.store', $project), [
            'recipient_name' => 'Ar. Mehta',
            'subject' => 'GFC drawings',
            'purpose' => 'for_construction',
            'versions' => $versions,
            ...$overrides,
        ]);
    }

    public function test_a_transmittal_records_exact_revisions_and_numbers_in_order(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $drawing = $this->makeDrawing($project);
        $second = $this->makeDrawing($project, 'A-102');

        $this->issue($user, $project, [$drawing->currentVersion->id, $second->currentVersion->id], ['copies' => [$drawing->currentVersion->id => 3]])->assertRedirect();
        $this->issue($user, $project, [$drawing->currentVersion->id])->assertRedirect();

        $first = DrawingTransmittal::orderBy('id')->firstOrFail();
        $this->assertSame('TR-0001', $first->number);
        $this->assertSame('TR-0002', DrawingTransmittal::latest('id')->firstOrFail()->number);
        $this->assertSame('issued', $first->status);
        $this->assertSame(3, $first->items()->where('drawing_version_id', $drawing->currentVersion->id)->value('copies'));
        $this->assertSame(1, $first->items()->where('drawing_version_id', $second->currentVersion->id)->value('copies'));
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => DrawingTransmittal::class, 'action' => 'items_issued']);

        $this->actingAs($user)->get(route('drawings.transmittals.show', $first))->assertOk()->assertSee('A-102');
        $this->actingAs($user)->get(route('drawings.register.show', $drawing))->assertOk()->assertSee('TR-0001');
    }

    public function test_numbers_are_per_project(): void
    {
        $organization = $this->tenant();
        $user = $this->member($organization, 'pm');

        foreach ([$this->makeProject($organization), $this->makeProject($organization)] as $project) {
            $this->issue($user, $project, [$this->makeDrawing($project)->currentVersion->id])->assertRedirect();
        }

        $this->assertSame(['TR-0001', 'TR-0001'], DrawingTransmittal::pluck('number')->all());
    }

    public function test_repeating_a_submission_issues_once(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $versionId = $this->makeDrawing($project)->currentVersion->id;
        $uuid = (string) Str::uuid();

        $this->issue($user, $project, [$versionId], ['client_uuid' => $uuid])->assertRedirect();
        $this->issue($user, $project, [$versionId], ['client_uuid' => $uuid])->assertRedirect();

        $this->assertSame(1, DrawingTransmittal::count());
    }

    public function test_an_old_revision_cannot_be_issued(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $drawing = $this->makeDrawing($project);
        $old = $drawing->currentVersion->id;
        $this->makeVersion($drawing, 'R1');

        $this->issue($this->member($organization, 'pm'), $project, [$old])->assertSessionHasErrors('versions.0');
        $this->assertSame(0, DrawingTransmittal::count());
    }

    public function test_a_drawing_from_another_project_cannot_be_issued(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $elsewhere = $this->makeDrawing($this->makeProject($organization));

        $this->issue($this->member($organization, 'pm'), $project, [$elsewhere->currentVersion->id])->assertSessionHasErrors('versions.0');
    }

    public function test_a_removed_drawing_cannot_be_issued(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $drawing = $this->makeDrawing($project);
        $version = $drawing->currentVersion->id;
        $drawing->delete();

        $this->issue($this->member($organization, 'pm'), $project, [$version])->assertSessionHasErrors('versions');
    }

    public function test_a_contact_can_be_the_recipient(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $party = Party::create(['organization_id' => $organization->id, 'name' => 'Mehta Architects', 'type' => 'consultant']);

        $this->issue($this->member($organization, 'pm'), $project, [$this->makeDrawing($project)->currentVersion->id], ['recipient_name' => '', 'party_id' => $party->id])->assertRedirect();

        $this->assertSame('Mehta Architects', DrawingTransmittal::firstOrFail()->recipient_name);
    }

    public function test_a_contact_from_another_company_is_refused(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $other = $this->tenant('Other Co');
        $party = Party::create(['organization_id' => $other->id, 'name' => 'Theirs', 'type' => 'consultant']);

        $this->issue($this->member($organization, 'pm'), $project, [$this->makeDrawing($project)->currentVersion->id], ['party_id' => $party->id])->assertSessionHasErrors('party_id');
    }

    public function test_acknowledging_and_cancelling(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $this->issue($user, $project, [$this->makeDrawing($project)->currentVersion->id]);
        $transmittal = DrawingTransmittal::firstOrFail();

        $this->actingAs($user)->post(route('drawings.transmittals.acknowledge', $transmittal), ['acknowledged_on' => '2026-11-20', 'acknowledged_by' => 'Site office'])->assertRedirect();
        $this->assertSame('acknowledged', $transmittal->fresh()->status);

        $this->actingAs($user)->post(route('drawings.transmittals.acknowledge', $transmittal), ['acknowledged_on' => '2026-11-21', 'acknowledged_by' => 'Again'])->assertSessionHasErrors('status');

        $this->actingAs($user)->post(route('drawings.transmittals.cancel', $transmittal), ['cancel_reason' => 'Wrong set'])->assertRedirect();
        $this->assertSame('cancelled', $transmittal->fresh()->status);
        $this->assertTrue(AuditLog::where('auditable_type', DrawingTransmittal::class)->where('action', 'updated')->exists());

        $this->actingAs($user)->post(route('drawings.transmittals.cancel', $transmittal), ['cancel_reason' => 'Again'])->assertSessionHasErrors('status');
    }

    public function test_an_issued_transmittal_cannot_be_edited_or_deleted(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->issue($this->member($organization, 'pm'), $project, [$this->makeDrawing($project)->currentVersion->id]);
        $transmittal = DrawingTransmittal::firstOrFail();

        try {
            $transmittal->update(['subject' => 'Changed']);
            $this->fail('An issued transmittal was edited.');
        } catch (ImmutableRecordException) {
            $this->assertSame('GFC drawings', $transmittal->fresh()->subject);
        }

        $this->expectException(ImmutableRecordException::class);
        $transmittal->fresh()->delete();
    }

    public function test_the_pdf_downloads(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'viewer');
        $this->issue($this->member($organization, 'pm'), $project, [$this->makeDrawing($project)->currentVersion->id]);

        $response = $this->actingAs($user)->get(route('drawings.transmittals.pdf', DrawingTransmittal::firstOrFail()))->assertOk();

        $this->assertStringContainsString('TR-0001.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_viewers_can_read_but_not_issue(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $viewer = $this->member($organization, 'viewer');
        $versionId = $this->makeDrawing($project)->currentVersion->id;

        $this->actingAs($viewer)->get(route('drawings.transmittals.index', $project))->assertOk();
        $this->actingAs($viewer)->get(route('drawings.transmittals.create', $project))->assertForbidden();
        $this->issue($viewer, $project, [$versionId])->assertForbidden();
    }

    public function test_other_companies_cannot_reach_a_transmittal(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->issue($this->member($organization, 'pm'), $project, [$this->makeDrawing($project)->currentVersion->id]);
        $transmittal = DrawingTransmittal::firstOrFail();
        $outsider = $this->member($this->tenant('Other Co'), 'owner');

        $this->actingAs($outsider)->get(route('drawings.transmittals.show', $transmittal))->assertNotFound();
        $this->actingAs($outsider)->get(route('drawings.transmittals.pdf', $transmittal))->assertNotFound();
        $this->actingAs($outsider)->post(route('drawings.transmittals.cancel', $transmittal), ['cancel_reason' => 'x'])->assertNotFound();
    }
}
