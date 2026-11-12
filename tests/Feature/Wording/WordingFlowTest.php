<?php

namespace Tests\Feature\Wording;

use App\Models\AuditLog;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FinalCheck;
use App\Services\Wording\SlotIntake;
use App\Services\Wording\SlotText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\BuildsTenant;

/** One letter from the model's answer to the finalised text, the way a feature uses the service. */
class WordingFlowTest extends WordingTestCase
{
    use BuildsTenant, RefreshDatabase;

    public function test_a_letter_goes_from_a_models_answer_to_a_finalised_text_with_every_figure_from_the_records(): void
    {
        $company = $this->tenant('Acme Buildcon');
        $manager = $this->member($company, 'pm');
        $project = $this->makeProject($company);
        $type = $this->letter();
        $sheet = $this->sheet();

        // 1. The model answers. One slot tries to state the amount itself, and one id is made up.
        $draft = app(SlotIntake::class)->accept($type, ['slots' => [
            ['id' => 'opening', 'text' => 'आपके सहयोग के लिए हम आभारी हैं और अपने बिलों की ओर आपका ध्यान चाहते हैं।'],
            ['id' => 'closing', 'text' => 'यह ₹1,50,000.00 की राशि पंद्रह दिन से बकाया है।'],
            ['id' => 'bill_1', 'text' => 'बकाया राशि बहुत कम है।'],
        ]], 'hi');

        $this->assertTrue($draft->hasRejections());
        $this->assertSame(['closing'], array_keys($draft->rejected));
        $this->assertSame(['bill_1'], $draft->dropped);

        // 2. The draft: the model's clean slot, the code text for the refused one, the figures from the sheet.
        $document = $this->renderer()->render($type, $sheet, 'hi', $draft->texts());

        $this->assertSame(
            "आपके सहयोग के लिए हम आभारी हैं और अपने बिलों की ओर आपका ध्यान चाहते हैं।\n\n"
            ."बिल RA-P101-003 पर 5 नवंबर 2026 को बकाया राशि ₹1,50,000.00 है।\n\n"
            .'हम आपके उत्तर की प्रतीक्षा में हैं।',
            $document->text(),
        );
        $this->assertStringNotContainsString('पंद्रह', $document->text());

        // What the feature keeps on its row.
        $row = [
            'facts' => json_decode(json_encode($sheet->toArray()), true),
            'fact_fingerprint' => $document->fingerprint,
            'slots' => json_decode(json_encode($document->freeTexts()), true),
        ];
        $this->assertSame(['ai', 'template'], array_column($row['slots'], 'source'));

        // 3. A person rewrites the close and types a number of their own.
        $row['slots']['closing'] = SlotText::edited('इस बारे में हमने 2 बार फ़ोन किया था।')->toArray();
        $edited = $this->renderer()->render($type, FactSheet::fromArray($row['facts']), 'hi', $row['slots']);

        $this->assertSame(['typed:closing:1:2'], array_column(array_map(fn ($number) => $number->toArray(), $edited->typedNumbers()), 'id'));
        $this->assertSame(['bill_1_number', 'as_on', 'bill_1_outstanding', 'typed:closing:1:2'], $edited->ticksNeeded());

        // 4. Finalise: the facts are read from the records again and the letter is rendered from them.
        $check = app(FinalCheck::class);
        $rebuilt = $this->sheet();
        $final = $this->renderer()->render($type, $rebuilt, 'hi', $row['slots']);

        try {
            $check->pass($project, $row['fact_fingerprint'], $rebuilt, $final, ['bill_1_number', 'as_on', 'bill_1_outstanding'], $manager);
            $this->fail('Finalised with a typed number that nobody ticked.');
        } catch (ValidationException $e) {
            $this->assertSame(['number_ticks'], array_keys($e->errors()));
        }

        $check->pass($project, $row['fact_fingerprint'], $rebuilt, $final, $final->ticksNeeded(), $manager);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'wording.typed_numbers_ticked')->sole();
        $this->assertSame($company->id, $audit->organization_id);
        $this->assertSame(['2'], array_column($audit->new_values['numbers'], 'text'));

        // 5. A payment arrives before the letter is shared. Guard D stops the old text.
        $this->expectException(ValidationException::class);
        $check->assertUnchanged($row['fact_fingerprint'], $this->sheet(['bill_1_outstanding' => '50000.00']));
    }
}
