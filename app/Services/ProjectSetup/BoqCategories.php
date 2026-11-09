<?php

namespace App\Services\ProjectSetup;

use Illuminate\Support\Str;

/** The kinds of work a BOQ line can belong to. RCC and brick work get extra visibility on the budget screen. */
final class BoqCategories
{
    /** Stored value => label. The label is translated where it is shown. */
    public const ALL = [
        'excavation' => 'Excavation',
        'filling' => 'Filling',
        'pcc' => 'PCC',
        'foundation' => 'Foundation concrete',
        'steel' => 'Steel',
        'rcc' => 'RCC',
        'brick_work' => 'Brick work',
        'plaster' => 'Plaster',
        'flooring' => 'Flooring and tiles',
        'plumbing' => 'Plumbing',
        'electrical' => 'Electrical',
        'finishing' => 'Painting and finishing',
        'other' => 'Other',
    ];

    /** The two heads where the most money is lost, so the budget screen watches them closely. */
    public const WATCHED = ['rcc', 'brick_work'];

    /** Concrete lines can be entered by ingredients as well as by cubic metre. */
    public const CONCRETE = ['pcc', 'foundation', 'rcc'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(?string $category): string
    {
        return $category === null || $category === '' ? '' : __(self::ALL[$category] ?? Str::headline($category));
    }

    /**
     * Turn what a spreadsheet says into a stored value. The stored word, the English label and the
     * label with spaces or dashes in other places all work. Null when it is not a known category.
     */
    public static function fromText(string $text): ?string
    {
        $wanted = self::squash($text);

        foreach (self::ALL as $key => $label) {
            if ($wanted === self::squash($key) || $wanted === self::squash($label)) {
                return $key;
            }
        }

        return null;
    }

    private static function squash(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($text));
    }
}
