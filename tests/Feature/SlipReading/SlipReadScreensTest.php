<?php

namespace Tests\Feature\SlipReading;

use App\Models\AiDocumentSettings;
use App\Models\DocumentRead;
use App\Models\SlipRead;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\MaterialControl\DeliveryChallanService;
use App\Services\SlipReading\SlipReadKind;
use App\Support\PlatformSettings;

/** The button on the new-challan form, and the challan's page, with the reader on and off. */
class SlipReadScreensTest extends SlipReadingTestCase
{
    private const BUTTON = 'Read details from the photo';

    public function test_the_form_offers_the_reader_to_whoever_may_use_it_while_it_is_on(): void
    {
        $store = $this->member($this->organization, 'store');
        $page = $this->actingAs($store)->get(route('material_control.challans.create', $this->project))->assertOk();

        $page->assertSee(self::BUTTON)
            ->assertSee('Each field shows the text as read, beside the photo. You confirm each field against the photo.')
            ->assertSee('slip-reads', false)
            ->assertSee('data-reader-capture', false)
            // The counted quantity stays the person's to type.
            ->assertSee('Type the quantity you counted yourself.');

        $this->assertMatchesRegularExpression('/up to \d+ credits/', $page->getContent());
    }

    public function test_the_form_is_as_before_when_the_reader_is_off_or_not_for_this_person(): void
    {
        $store = $this->member($this->organization, 'store');
        // A fresh copy each time, as each request loads the person and the company again.
        $form = fn () => $this->actingAs($store->fresh())->get(route('material_control.challans.create', $this->project))->assertOk();

        AiDocumentSettings::for($this->organization)->update(['enabled' => false]);
        // Consent is asked when the photo is sent, so the button still shows: the guard answers then.
        $form()->assertSee(self::BUTTON);
        AiDocumentSettings::for($this->organization)->update(['enabled' => true, 'consent_version' => AiDocumentConsent::VERSION]);

        config(['modules.slip_reading.read_kinds' => []]);
        $form()->assertDontSee(self::BUTTON)->assertDontSee('data-reader-capture', false);
        config(['modules.slip_reading.read_kinds' => [SlipReadKind::class]]);

        app(PlatformSettings::class)->set(['ai_readers_released' => '0']);
        $form()->assertDontSee(self::BUTTON);
        $this->release();

        $this->organization->update(['plan' => 'contractor']);
        $form()->assertDontSee(self::BUTTON);
        $this->organization->update(['plan' => 'business']);

        $form()->assertSee(self::BUTTON);
    }

    public function test_the_screens_are_in_the_persons_language(): void
    {
        $store = $this->member($this->organization, 'store');

        $store->update(['locale' => 'hi']);
        $this->actingAs($store)->get(route('material_control.challans.create', $this->project))->assertOk()
            ->assertSee('फोटो से विवरण पढ़ें')->assertDontSee(self::BUTTON);

        $store->update(['locale' => 'mr']);
        $this->actingAs($store)->get(route('material_control.challans.create', $this->project))->assertOk()
            ->assertSee('फोटोवरून तपशील वाचा')->assertDontSee(self::BUTTON);

        $slip = $this->storedReading($store, ['status' => SlipRead::READING]);
        DocumentRead::withoutGlobalScopes()->whereKey($slip->document_read_id)->update(['status' => DocumentRead::CANCELLED]);
        $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('read.message', 'फोटो वाचण्याचे हे काम संपले आहे. फोटो पुन्हा वाचा किंवा तपशील स्वतः टाइप करा.');
    }

    public function test_the_challans_page_still_shows_with_its_bill_form(): void
    {
        $challan = app(DeliveryChallanService::class)->create($this->project, [
            'party_id' => $this->vendor->id, 'material_id' => $this->cement->id, 'qty' => '10', 'challan_date' => '2026-11-11',
        ], $this->owner);

        $this->actingAs($this->owner)->get(route('material_control.challans.show', $challan))->assertOk()->assertSee('Attach bill');
    }
}
