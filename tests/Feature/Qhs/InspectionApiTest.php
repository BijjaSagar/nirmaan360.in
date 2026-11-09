<?php

namespace Tests\Feature\Qhs;

use App\Models\Inspection;
use App\Services\Qhs\ChecklistService;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\FieldOps\FieldOpsTestCase;

class InspectionApiTest extends FieldOpsTestCase
{
    private const API = '/api/v1';

    private function template(object $w, string $name = 'Pour card')
    {
        return app(ChecklistService::class)->createTemplate(['name' => $name, 'category' => 'pour_card', 'items' => ['Level checked', 'Cover blocks fixed', 'Cubes cast']], $w->owner);
    }

    public function test_templates_come_with_their_items(): void
    {
        $w = $this->world('engineer');
        $this->actingAs($w->owner);
        $template = $this->template($w);
        $inactive = $this->template($w, 'Old one');
        app(ChecklistService::class)->updateTemplate($inactive, ['name' => 'Old one', 'category' => 'general', 'items' => ['x'], 'is_active' => false]);

        Sanctum::actingAs($w->user);

        $this->getJson(self::API.'/checklist-templates')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $template->id)
            ->assertJsonPath('data.0.items', ['Level checked', 'Cover blocks fixed', 'Cubes cast']);
    }

    public function test_an_inspection_can_be_sent_whole_and_is_saved_once(): void
    {
        $w = $this->world('engineer');
        $this->actingAs($w->owner);
        $template = $this->template($w);
        Sanctum::actingAs($w->user);
        $payload = [
            'client_uuid' => (string) Str::uuid(),
            'checklist_template_id' => $template->id,
            'location' => 'Slab L3',
            'inspected_on' => '2026-11-02',
            'results' => [
                ['position' => 1, 'result' => 'pass'],
                ['position' => 2, 'result' => 'fail', 'remark' => 'Cover 20 mm'],
                ['position' => 3, 'result' => 'na'],
            ],
            'complete' => true,
        ];

        $this->postJson(self::API."/projects/{$w->project->id}/inspections", $payload)->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.client_uuid', $payload['client_uuid'])
            ->assertJsonPath('data.items.1.remark', 'Cover 20 mm')
            ->assertJsonPath('data.items.0.position', 1);

        $this->postJson(self::API."/projects/{$w->project->id}/inspections", $payload)->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertSame(1, Inspection::count());
    }

    public function test_an_inspection_can_be_filled_in_over_several_calls(): void
    {
        $w = $this->world('engineer');
        $this->actingAs($w->owner);
        $template = $this->template($w);
        Sanctum::actingAs($w->user);

        $created = $this->postJson(self::API."/projects/{$w->project->id}/inspections", ['checklist_template_id' => $template->id])->assertCreated()->assertJsonPath('data.status', 'in_progress');
        $id = $created->json('data.id');
        $itemIds = collect($created->json('data.items'))->pluck('id');

        $this->postJson(self::API."/inspections/$id/complete")->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->patchJson(self::API."/inspections/$id", ['items' => $itemIds->map(fn ($itemId) => ['id' => $itemId, 'result' => 'pass'])->all()])->assertOk()->assertJsonPath('data.items.2.result', 'pass');
        $this->postJson(self::API."/inspections/$id/complete")->assertOk()->assertJsonPath('data.status', 'passed');

        $this->patchJson(self::API."/inspections/$id", ['items' => [['id' => $itemIds[0], 'result' => 'fail', 'remark' => 'x']]])->assertStatus(409);
        $this->getJson(self::API."/inspections/$id")->assertOk()->assertJsonPath('data.status', 'passed');
        $this->getJson(self::API."/projects/{$w->project->id}/inspections?status=passed")->assertOk()->assertJsonPath('total', 1);
        $this->getJson(self::API."/projects/{$w->project->id}/inspections?status=failed")->assertOk()->assertJsonPath('total', 0);
    }

    public function test_bad_input_is_a_422(): void
    {
        $w = $this->world('engineer');
        $this->actingAs($w->owner);
        $template = $this->template($w);
        Sanctum::actingAs($w->user);
        $url = self::API."/projects/{$w->project->id}/inspections";

        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('checklist_template_id');
        $this->postJson($url, ['checklist_template_id' => $template->id, 'results' => [['position' => 1, 'result' => 'maybe']]])->assertUnprocessable()->assertJsonValidationErrors('results.0.result');
        $this->postJson($url, ['checklist_template_id' => $template->id, 'results' => [['position' => 7, 'result' => 'pass']]])->assertUnprocessable()->assertJsonValidationErrors('results');
        $this->postJson($url, ['checklist_template_id' => $template->id, 'results' => [['position' => 1, 'result' => 'fail']]])->assertUnprocessable()->assertJsonValidationErrors('results');
        $this->postJson($url, ['checklist_template_id' => $template->id, 'client_uuid' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
        $this->assertSame(0, Inspection::count());
    }

    public function test_another_companys_data_is_refused_or_hidden(): void
    {
        $w = $this->world('engineer');
        $theirs = $this->world('owner', 'business', 'Rival Builders');
        $this->actingAs($theirs->owner);
        $theirTemplate = $this->template($theirs, 'Rival secret checklist');
        $theirInspection = app(ChecklistService::class)->start($theirs->project, ['checklist_template_id' => $theirTemplate->id], $theirs->owner);
        $theirItem = $theirInspection->items->first();

        Sanctum::actingAs($w->user);

        $this->getJson(self::API.'/checklist-templates')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::API."/projects/{$w->project->id}/inspections", ['checklist_template_id' => $theirTemplate->id])->assertUnprocessable()->assertJsonValidationErrors('checklist_template_id');
        $this->postJson(self::API."/projects/{$theirs->project->id}/inspections", ['checklist_template_id' => $theirTemplate->id])->assertNotFound();
        $this->getJson(self::API."/projects/{$theirs->project->id}/inspections")->assertNotFound();
        $this->getJson(self::API."/inspections/{$theirInspection->id}")->assertNotFound();
        $this->patchJson(self::API."/inspections/{$theirInspection->id}", ['items' => [['id' => $theirItem->id, 'result' => 'pass']]])->assertNotFound();
        $this->postJson(self::API."/inspections/{$theirInspection->id}/complete")->assertNotFound();
    }

    public function test_permissions_and_plan(): void
    {
        $w = $this->world('viewer');
        $this->actingAs($w->owner);
        $template = $this->template($w);
        $inspection = app(ChecklistService::class)->start($w->project, ['checklist_template_id' => $template->id], $w->owner);

        $this->app['auth']->forgetGuards(); // the set-up above signed the owner in
        $this->getJson(self::API.'/checklist-templates')->assertUnauthorized();

        Sanctum::actingAs($w->user);
        $this->getJson(self::API."/inspections/{$inspection->id}")->assertOk();
        $this->postJson(self::API."/projects/{$w->project->id}/inspections", ['checklist_template_id' => $template->id])->assertForbidden();
        $this->patchJson(self::API."/inspections/{$inspection->id}", ['items' => [['id' => $inspection->items->first()->id, 'result' => 'pass']]])->assertForbidden();
        $this->postJson(self::API."/inspections/{$inspection->id}/complete")->assertForbidden();

        $small = $this->world('owner', 'contractor', 'Small Co');
        Sanctum::actingAs($small->owner);
        $this->getJson(self::API.'/checklist-templates')->assertForbidden();
        $this->getJson(self::API."/projects/{$small->project->id}/inspections")->assertForbidden();
    }
}
