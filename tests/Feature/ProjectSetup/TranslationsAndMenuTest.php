<?php

namespace Tests\Feature\ProjectSetup;

use App\Models\BoqItem;
use App\Services\ProjectSetup\BoqCategories;
use App\Services\ProjectSetup\ConcreteMix;
use App\Services\ProjectSetup\StarterBoq;

class TranslationsAndMenuTest extends ProjectSetupTestCase
{
    /** @return array<string,string> */
    private function strings(string $locale): array
    {
        $strings = json_decode((string) file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach (glob(lang_path("modules/*/{$locale}.json")) as $file) {
            $strings = [...$strings, ...json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)];
        }

        return $strings;
    }

    /** Every text literal passed to __() in the module's own files and in the shared files it edits, and the texts it passes by variable. */
    private function usedTexts(): array
    {
        $files = [
            ...glob(app_path('Services/ProjectSetup/*.php')),
            ...glob(app_path('Http/Controllers/ProjectSetup/*.php')),
            ...glob(resource_path('views/modules/project_setup/*.blade.php')),
            ...glob(resource_path('views/modules/project_setup/boq/*.blade.php')),
            app_path('Services/BoqService.php'),
            app_path('Http/Controllers/BoqController.php'),
            app_path('Http/Controllers/Api/BoqItemApiController.php'),
            resource_path('views/boq/index.blade.php'),
        ];
        $texts = [];

        foreach ($files as $file) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $text) {
                $texts[] = str_replace("\\'", "'", $text);
            }
        }

        array_push($texts, ...array_values(BoqCategories::ALL), ...array_values(ConcreteMix::ROLES));

        foreach ([...StarterBoq::DETAILED, ...StarterBoq::THUMB_RULE] as $row) {
            $texts[] = $row[0];
        }

        foreach (config('modules.project_setup.nav', []) as $item) {
            array_push($texts, $item['label'], $item['group']);
        }

        // Words the permission screen builds from the module's key.
        array_push($texts, 'Permit', 'Not started', 'Applied', 'Approved', 'Rejected');

        return array_values(array_unique(array_filter($texts, fn (string $text) => mb_strlen($text) > 1)));
    }

    public function test_every_text_in_the_module_has_a_hindi_and_a_marathi_translation(): void
    {
        $this->assertGreaterThan(200, count($this->usedTexts()));

        foreach (['hi', 'mr'] as $locale) {
            $strings = $this->strings($locale);
            $missing = array_values(array_filter($this->usedTexts(), fn (string $text) => ! array_key_exists($text, $strings) || trim((string) $strings[$text]) === ''));

            $this->assertSame([], $missing, "Missing {$locale} translations");
        }
    }

    public function test_the_two_language_files_cover_the_same_texts_and_keep_their_placeholders(): void
    {
        $hindi = json_decode((string) file_get_contents(lang_path('modules/project_setup/hi.json')), true);
        $marathi = json_decode((string) file_get_contents(lang_path('modules/project_setup/mr.json')), true);

        $this->assertSame(array_keys($hindi), array_keys($marathi));

        foreach ([$hindi, $marathi] as $translations) {
            foreach ($translations as $english => $translated) {
                preg_match_all('/:[a-z]+/', $english, $expected);
                preg_match_all('/:[a-z]+/', $translated, $actual);

                $this->assertEqualsCanonicalizing($expected[0], $actual[0], "Placeholders differ in: {$english}");
            }
        }
    }

    public function test_the_screens_read_in_hindi_and_marathi(): void
    {
        $this->line('10.000', '100.00', ['category' => 'rcc']);

        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm)->get(route('project-setup.permits.index', $this->project))->assertOk()->assertSee('अनुमति जोड़ें');
        $this->actingAs($this->pm)->get(route('project-setup.boq-setup', $this->project))->assertOk()->assertSee('यह BOQ कितना विस्तृत है?');
        $this->actingAs($this->pm)->get(route('project-setup.history', $this->project))->assertOk()->assertSee('BOQ इतिहास');
        $this->actingAs($this->pm)->get(route('project-setup.budget', $this->project))->assertOk()->assertSee('लाइव भाव की सुविधा नहीं है।');
        $this->actingAs($this->pm)->get(route('projects.create'))->assertOk()->assertSee('आकार, समय और बजट');

        $this->pm->update(['locale' => 'mr']);
        $this->actingAs($this->pm)->get(route('project-setup.permits.index', $this->project))->assertOk()->assertSee('परवानगी जोडा');
        $this->actingAs($this->pm)->get(route('project-setup.boq-setup', $this->project))->assertOk()->assertSee('हा BOQ किती तपशीलवार आहे?');
        $this->actingAs($this->pm)->get(route('project-setup.budget', $this->project))->assertOk()->assertSee('थेट भावाची सोय नाही.');
        $this->line('0.000', '0.00', ['category' => 'brick_work']);
        $this->actingAs($this->pm)->get(route('boq.index', $this->project))->assertOk()->assertSee('विटांचे काम', false)->assertSee('भरायचे बाकी', false);
    }

    public function test_the_starter_items_are_written_in_the_language_of_the_person_who_adds_them(): void
    {
        $this->pm->update(['locale' => 'hi']);

        $this->actingAs($this->pm)->post(route('project-setup.boq-setup.starter', $this->project));

        $this->assertContains('खुदाई', BoqItem::where('project_id', $this->project->id)->pluck('description')->all());
    }

    public function test_the_menu_groups_the_screens_for_those_with_access(): void
    {
        $this->actingAs($this->pm)->get(route('boq.index', $this->project))->assertOk()
            ->assertSee('Project setup')->assertSee(route('project-setup.permits.index', $this->project), false)
            ->assertSee(route('project-setup.boq-setup', $this->project), false)->assertSee(route('project-setup.history', $this->project), false)
            ->assertSee(route('project-setup.budget', $this->project), false);

        // An engineer may look at the list and the history, but never sees the budget.
        $this->actingAs($this->engineer)->get(route('boq.index', $this->project))->assertOk()
            ->assertSee(route('project-setup.permits.index', $this->project), false)->assertDontSee(route('project-setup.budget', $this->project), false);

        $this->actingAs($this->holding(['dashboard.view', 'project.view']))->get(route('projects.show', $this->project))->assertOk()
            ->assertDontSee('Project setup');
    }

    public function test_the_menu_is_hidden_on_plans_without_site_control(): void
    {
        $free = $this->tenant('Free Co', 'free');
        $owner = $this->member($free, 'owner');
        $project = $this->makeProject($free);

        $this->actingAs($owner)->get(route('boq.index', $project))->assertOk()
            ->assertDontSee(route('project-setup.history', $project), false)->assertDontSee('BOQ setup');
    }

    public function test_the_module_is_on_every_paid_plan_and_not_on_the_free_one(): void
    {
        foreach (['contractor', 'business', 'enterprise'] as $plan) {
            $this->assertTrue($this->tenant("Co {$plan}", $plan)->allowsFeature('site_control'), $plan);
        }

        $this->assertFalse($this->tenant('Free Co', 'free')->allowsFeature('site_control'));
    }

    public function test_the_roles_get_the_checklist_permissions_they_should(): void
    {
        $this->assertTrue($this->pm->can('permit.manage'));
        $this->assertTrue($this->qs->can('permit.view'));
        $this->assertFalse($this->qs->can('permit.manage'));
        $this->assertTrue($this->engineer->can('permit.view'));
        $this->assertFalse($this->engineer->can('permit.manage'));
        // The budget stays with the project manager.
        $this->assertTrue($this->pm->can('money.overview'));
        $this->assertFalse($this->qs->can('money.overview'));
        $this->assertFalse($this->engineer->can('money.view'));
    }
}
