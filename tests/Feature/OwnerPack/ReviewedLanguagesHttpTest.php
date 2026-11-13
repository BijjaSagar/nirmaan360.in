<?php

namespace Tests\Feature\OwnerPack;

use App\Models\ClientQuotation;
use App\Models\OwnerUpdate;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A quotation's cover note and the weekly update are put together from fixed sentences a client reads, so
 * they follow the same rule as the site letters (App\Services\Wording\ReviewedLanguages): on the live site
 * Hindi and Marathi are offered only once a native reader has reviewed them; everywhere else every
 * language is, so that the wording can be read. A draft keeps the language it already has.
 */
class ReviewedLanguagesHttpTest extends TestCase
{
    use BuildsOwnerUpdates, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->site();
    }

    /** The live site checks the form's token, which the test environment leaves out. */
    private function live(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /** @return list<string> The values of the language list on a page. */
    private function languageOptions(string $url): array
    {
        preg_match('/<select id="language".*?<\/select>/s', (string) $this->get($url)->assertOk()->getContent(), $select);
        preg_match_all('/<option value="([a-z]+)"/', $select[0], $values);

        return $values[1];
    }

    /** @return array<string, mixed> */
    private function quotationForm(string $language): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'client_name' => 'Anil Deshmukh',
            'language' => $language,
            'boq_item_ids' => [$this->lines[0]->id],
        ];
    }

    /** @return array<string, mixed> */
    private function updateForm(string $language): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'week_start' => self::WEEK, 'language' => $language];
    }

    public function test_anywhere_but_the_live_site_every_language_is_offered(): void
    {
        $this->actingAs($this->manager);

        $this->assertEqualsCanonicalizing(ClientQuotation::LANGUAGES, $this->languageOptions(route('quotations.create', $this->project)));
        $this->assertEqualsCanonicalizing(OwnerUpdate::LANGUAGES, $this->languageOptions(route('owner-updates.create', $this->project)));

        $this->post(route('quotations.store', $this->project), $this->quotationForm('mr'))->assertSessionHasNoErrors();
        $this->post(route('owner-updates.store', $this->project), $this->updateForm('hi'))->assertSessionHasNoErrors();
        $this->assertSame(['mr'], ClientQuotation::pluck('language')->all());
        $this->assertSame(['hi'], OwnerUpdate::pluck('language')->all());
    }

    public function test_on_the_live_site_a_quotation_is_offered_in_hindi_or_marathi_only_once_reviewed(): void
    {
        $this->manager->update(['locale' => 'hi']);
        $this->actingAs($this->manager);
        $this->live();

        // Nothing reviewed: English only, and the form starts in English whatever the person reads in.
        $this->assertSame(['en'], $this->languageOptions(route('quotations.create', $this->project)));
        $this->assertStringContainsString('<option value="en" lang="en" selected', (string) $this->get(route('quotations.create', $this->project))->getContent());

        foreach (['hi', 'mr'] as $language) {
            $this->post(route('quotations.store', $this->project), $this->quotationForm($language))->assertSessionHasErrors('language');
        }

        $this->assertSame(0, ClientQuotation::count());
        $this->post(route('quotations.store', $this->project), $this->quotationForm('en'))->assertSessionHasNoErrors();
        $english = ClientQuotation::sole();

        // A draft in English cannot be switched to a language that waits for review.
        $this->patch(route('quotations.update', $english), ['language' => 'mr'])->assertSessionHasErrors('language');
        $this->assertSame('en', $english->fresh()->language);

        // Once Marathi is reviewed for the owner pack, it is offered, and Hindi still is not.
        config(['wording.reviewed_languages.owner_pack' => ['mr']]);
        $this->assertSame(['en', 'mr'], array_values(array_intersect(ClientQuotation::LANGUAGES, $this->languageOptions(route('quotations.create', $this->project)))));
        $this->post(route('quotations.store', $this->project), $this->quotationForm('mr'))->assertSessionHasNoErrors();
        $this->post(route('quotations.store', $this->project), $this->quotationForm('hi'))->assertSessionHasErrors('language');
        $this->patch(route('quotations.update', $english), ['language' => 'mr'])->assertSessionHasNoErrors();
        $this->assertSame('mr', $english->fresh()->language);

        // The site letters' list does not open the owner pack's.
        config(['wording.reviewed_languages.owner_pack' => [], 'wording.reviewed_languages.site_letters' => ['hi', 'mr']]);
        $this->post(route('quotations.store', $this->project), $this->quotationForm('hi'))->assertSessionHasErrors('language');
    }

    public function test_a_draft_keeps_its_language_when_that_language_is_no_longer_offered(): void
    {
        // Prepared in Hindi where every language is offered.
        $quotation = $this->draft(['language' => 'hi']);
        $update = $this->draftUpdate(['language' => 'hi']);

        $this->actingAs($this->manager);
        $this->live();

        // Its form lists its own language beside English, and saving it unchanged keeps it.
        $this->assertSame(['en', 'hi'], array_values(array_intersect(['en', 'hi', 'mr'], $this->languageOptions(route('quotations.show', $quotation)))));
        $this->assertSame(['en', 'hi'], array_values(array_intersect(['en', 'hi', 'mr'], $this->languageOptions(route('owner-updates.show', $update)))));

        $this->patch(route('quotations.update', $quotation), ['language' => 'hi', 'client_name' => 'A. Deshmukh'])->assertSessionHasNoErrors();
        $this->assertSame(['hi', 'A. Deshmukh'], [$quotation->fresh()->language, $quotation->fresh()->client_name]);
        $this->patch(route('owner-updates.update', $update), ['language' => 'hi', 'next_week_text' => ''])->assertSessionHasNoErrors();
        $this->assertSame('hi', $update->fresh()->language);

        // It may go to English, but not to another language that waits for review.
        $this->patch(route('quotations.update', $quotation), ['language' => 'mr'])->assertSessionHasErrors('language');
        $this->patch(route('owner-updates.update', $update), ['language' => 'mr'])->assertSessionHasErrors('language');
        $this->patch(route('quotations.update', $quotation), ['language' => 'en'])->assertSessionHasNoErrors();
        $this->assertSame('en', $quotation->fresh()->language);
    }

    public function test_on_the_live_site_a_weekly_update_is_offered_in_hindi_or_marathi_only_once_reviewed(): void
    {
        $this->actingAs($this->manager);
        $this->live();

        $this->assertSame(['en'], $this->languageOptions(route('owner-updates.create', $this->project)));
        // The link that prepares a new update for the same week and language starts in English when that language waits.
        $page = (string) $this->get(route('owner-updates.create', [$this->project, 'week_start' => self::WEEK, 'language' => 'mr']))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="en" lang="en" selected', $page);
        $this->assertStringNotContainsString('<option value="mr"', $page);

        foreach (['hi', 'mr'] as $language) {
            $this->post(route('owner-updates.store', $this->project), $this->updateForm($language))->assertSessionHasErrors('language');
        }

        $this->assertSame(0, OwnerUpdate::count());

        config(['wording.reviewed_languages.owner_pack' => ['hi']]);
        $this->assertEqualsCanonicalizing(['en', 'hi'], $this->languageOptions(route('owner-updates.create', $this->project)));
        $this->post(route('owner-updates.store', $this->project), $this->updateForm('hi'))->assertSessionHasNoErrors();
        $this->post(route('owner-updates.store', $this->project), $this->updateForm('mr'))->assertSessionHasErrors('language');
        $this->assertSame(['hi'], OwnerUpdate::pluck('language')->all());
    }
}
