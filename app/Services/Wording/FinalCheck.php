<?php

namespace App\Services\Wording;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * What must hold when a document is approved, finalised or shared.
 *
 * Guard D: the facts are read from the records again, and a draft made from other figures is stopped.
 * Ticks: a person has ticked every figure the document shows.
 * Guard E: every number a person typed by hand is ticked too, and the ticks are written to the audit log.
 *
 * The order at approve or finalise: build the sheet again, assertUnchanged, render from that sheet,
 * assertTicked, save, recordTypedNumbers. Or call pass() for all of it. At share only Guard D runs again.
 */
final class FinalCheck
{
    /** Shown beside a number that did not come from a fact. */
    public static function typedLabel(): string
    {
        return __('typed by you, not from the records');
    }

    /**
     * Guard D.
     *
     * @param  ?string  $storedFingerprint  The fingerprint kept with the draft when it was made.
     * @param  FactSheet  $rebuilt  The sheet built from the records now.
     *
     * @throws ValidationException
     */
    public function assertUnchanged(?string $storedFingerprint, FactSheet $rebuilt): void
    {
        if (! $rebuilt->matches($storedFingerprint)) {
            throw ValidationException::withMessages([
                'facts' => __('The records behind this draft have changed since it was prepared. Prepare the draft again and check it.'),
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $ticks  The request's number_ticks: fact keys, and the ids of typed numbers.
     * @return list<string> What the document shows that is not ticked.
     */
    public function missingTicks(Rendered $document, array $ticks): array
    {
        $given = array_filter($ticks, 'is_string');

        return array_values(array_filter($document->ticksNeeded(), fn (string $needed) => ! in_array($needed, $given, true)));
    }

    /** @throws ValidationException */
    public function assertTicked(Rendered $document, array $ticks): void
    {
        $missing = $this->missingTicks($document, $ticks);

        if ($missing === []) {
            return;
        }

        $typed = [];

        foreach ($document->typedNumbers() as $number) {
            $typed[$number->id] = $number->text.' ('.self::typedLabel().')';
        }

        $names = array_map(fn (string $key) => $typed[$key] ?? $document->labels[$key] ?? $key, $missing);

        throw ValidationException::withMessages([
            'number_ticks' => __('Tick every figure in the draft before you continue. Not ticked yet: :figures', ['figures' => implode('; ', $names)]),
        ]);
    }

    /**
     * Guard E: the audit row for numbers typed by hand and ticked. Nothing is written when there are none.
     *
     * @param  Model  $subject  The letter, quotation or update. The row is filed under its company.
     */
    public function recordTypedNumbers(Model $subject, Rendered $document, ?User $by = null): ?AuditLog
    {
        $typed = $document->typedNumbers();

        if ($typed === []) {
            return null;
        }

        return AuditLog::record('wording.typed_numbers_ticked', $subject, null, [
            'document_type' => $document->type,
            'language' => $document->locale,
            'fact_fingerprint' => $document->fingerprint,
            'numbers' => array_map(fn (TypedNumber $number) => $number->toArray(), $typed),
        ], $by);
    }

    /**
     * Guard D, the ticks and the audit row in one call.
     *
     * @param  Rendered  $document  Rendered from $rebuilt, not read back from storage.
     *
     * @throws ValidationException
     */
    public function pass(Model $subject, ?string $storedFingerprint, FactSheet $rebuilt, Rendered $document, array $ticks, ?User $by = null): void
    {
        $this->assertUnchanged($storedFingerprint, $rebuilt);

        if (! $rebuilt->matches($document->fingerprint)) {
            throw new WordingException('The document was not rendered from the sheet that was just built from the records.');
        }

        $this->assertTicked($document, $ticks);
        $this->recordTypedNumbers($subject, $document, $by);
    }
}
