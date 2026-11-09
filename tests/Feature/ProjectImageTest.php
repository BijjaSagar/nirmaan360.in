<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\BuildsTenant;
use Tests\TestCase;

class ProjectImageTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private Organization $organization;

    private User $pm;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->withoutVite();
        $this->organization = $this->tenant();
        $this->pm = $this->member($this->organization, 'pm');
        $this->project = $this->makeProject($this->organization, ['name' => 'Sargam Hotel']);
    }

    private function fields(array $overrides = []): array
    {
        return [
            'code' => $this->project->code, 'name' => $this->project->name, 'contract_value' => '0', 'status' => 'active',
            'retention_pct' => '5', 'tds_pct' => '2', 'gst_pct' => '18', 'advance_amount' => '0',
            'advance_recovery_pct' => '0', 'measurement_tolerance_pct' => '5',
            'deduction_basis' => 'gross', 'deduction_order' => 'retention,advance,material,tds',
            ...$overrides,
        ];
    }

    private function upload(?UploadedFile $file = null): void
    {
        $this->actingAs($this->pm)
            ->patch(route('projects.update', $this->project), $this->fields(['image' => $file ?? UploadedFile::fake()->image('site.jpg', 800, 600)]))
            ->assertSessionHasNoErrors();

        $this->project->refresh();
    }

    public function test_a_new_project_can_have_a_photo(): void
    {
        $this->actingAs($this->pm)->post(route('projects.store'), $this->fields([
            'code' => 'NEW1', 'name' => 'Rise Tower', 'image' => UploadedFile::fake()->image('site.jpg', 800, 600),
        ]))->assertSessionHasNoErrors();

        $project = Project::where('code', 'NEW1')->sole();

        $this->assertStringStartsWith('org-'.$this->organization->id.'/projects/'.$project->id.'/', $project->image_path);
        Storage::assertExists($project->image_path);
    }

    public function test_a_phone_sized_photo_is_stored_smaller(): void
    {
        $this->upload(UploadedFile::fake()->image('site.jpg', 4000, 3000));

        [$width, $height] = getimagesizefromstring(Storage::get($this->project->image_path));

        $this->assertSame([1600, 1200], [$width, $height]);
    }

    public function test_a_small_photo_is_not_stretched_and_a_png_becomes_a_jpeg(): void
    {
        $this->upload(UploadedFile::fake()->image('site.png', 300, 200));

        $size = getimagesizefromstring(Storage::get($this->project->image_path));

        $this->assertSame([300, 200, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
    }

    public function test_a_photo_taken_with_the_phone_held_upright_is_saved_upright(): void
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(400, 200));
        $jpeg = (string) ob_get_clean();

        // The smallest EXIF block that says "rotate 90 degrees clockwise to view".
        $exif = "Exif\0\0MM\0*".pack('N', 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('n', 6)."\0\0".pack('N', 0);
        $bytes = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);

        $this->upload(UploadedFile::fake()->createWithContent('phone.jpg', $bytes));

        $this->assertSame([200, 400], array_slice(getimagesizefromstring(Storage::get($this->project->image_path)), 0, 2));
    }

    public function test_a_new_photo_replaces_the_old_file(): void
    {
        $this->upload();
        $old = $this->project->image_path;

        $this->upload();

        $this->assertNotSame($old, $this->project->image_path);
        Storage::assertMissing($old);
        Storage::assertExists($this->project->image_path);
    }

    public function test_a_photo_can_be_removed(): void
    {
        $this->upload();
        $old = $this->project->image_path;

        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->fields(['remove_image' => '1']))->assertSessionHasNoErrors();

        $this->assertNull($this->project->refresh()->image_path);
        Storage::assertMissing($old);
    }

    public function test_saving_the_form_without_a_file_keeps_the_photo(): void
    {
        $this->upload();
        $path = $this->project->image_path;

        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->fields())->assertSessionHasNoErrors();

        $this->assertSame($path, $this->project->refresh()->image_path);
    }

    public function test_only_pictures_within_the_size_limit_are_accepted(): void
    {
        foreach ([
            UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->image('huge.jpg')->size(9000),
        ] as $file) {
            $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->fields(['image' => $file]))->assertSessionHasErrors('image');
        }

        $this->assertNull($this->project->refresh()->image_path);
    }

    public function test_site_staff_can_see_the_photo_but_not_change_it(): void
    {
        $this->upload();
        $engineer = $this->member($this->organization, 'engineer');

        $this->actingAs($engineer)->get(route('projects.image', $this->project))->assertOk();
        $this->actingAs($engineer)->patch(route('projects.update', $this->project), $this->fields(['remove_image' => '1']))->assertForbidden();

        $this->assertNotNull($this->project->refresh()->image_path);
    }

    public function test_another_company_cannot_read_the_photo(): void
    {
        $this->upload();
        $other = $this->member($this->tenant(), 'pm');

        $this->actingAs($other)->get(route('projects.image', $this->project))->assertNotFound();
    }

    public function test_someone_limited_to_other_projects_cannot_read_the_photo(): void
    {
        $this->upload();
        $limited = $this->member($this->organization, 'engineer');
        $limited->forceFill(['limited_to_projects' => true])->save();

        $this->actingAs($limited)->get(route('projects.image', $this->project))->assertNotFound();
    }

    public function test_a_project_with_no_photo_has_nothing_to_read(): void
    {
        $this->actingAs($this->pm)->get(route('projects.image', $this->project))->assertNotFound();
    }

    public function test_the_photo_is_not_kept_in_shared_caches(): void
    {
        $this->upload();

        $header = $this->actingAs($this->pm)->get(route('projects.image', $this->project))->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $header);
    }

    public function test_the_screens_show_the_photo_or_the_first_letter(): void
    {
        $this->actingAs($this->pm)->get(route('projects.index'))->assertOk()->assertDontSee('/image?v=', false)->assertSee('>S</span>', false);

        $this->upload();
        $address = route('projects.image', [$this->project, 'v' => $this->project->imageVersion()]);

        foreach (['projects.index', 'dashboard'] as $screen) {
            $this->actingAs($this->pm)->get(route($screen))->assertOk()->assertSee($address, false);
        }

        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()->assertSee($address, false);
        $this->actingAs($this->pm)->get(route('projects.edit', $this->project))->assertOk()->assertSee('name="remove_image"', false);
    }

    public function test_the_app_gets_an_address_that_changes_with_the_photo(): void
    {
        Sanctum::actingAs($this->pm, ['*']);

        $this->assertNull($this->getJson('/api/v1/projects')->assertOk()->json('data.0.image_url'));

        $this->upload();
        Sanctum::actingAs($this->pm, ['*']);
        $first = $this->getJson('/api/v1/projects')->json('data.0.image_url');
        $this->assertStringEndsWith('/api/v1/projects/'.$this->project->id.'/image?v='.$this->project->imageVersion(), $first);
        $this->get($first)->assertOk();

        $this->upload();
        Sanctum::actingAs($this->pm, ['*']);
        $this->assertNotSame($first, $this->getJson('/api/v1/projects')->json('data.0.image_url'));
    }
}
