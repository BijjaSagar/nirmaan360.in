<?php

namespace App\Services\Crm;

use App\Models\CrmCompanyProfile;
use App\Models\CrmLead;
use App\Models\CrmTender;
use Illuminate\Support\Str;

/** Tags are short labels kept as a JSON list on leads, tenders and company profiles. */
class TagService
{
    public const MAX_TAGS = 15;

    public const MAX_LENGTH = 30;

    /**
     * Turn a comma or newline separated string, or a list, into clean unique tags.
     *
     * @return list<string>
     */
    public static function parse(string|array|null $input): array
    {
        $parts = is_array($input) ? $input : preg_split('/[,\n]/', (string) $input);
        $tags = [];

        foreach ($parts ?: [] as $part) {
            $tag = trim((string) preg_replace('/\s+/u', ' ', (string) $part));
            $tag = Str::limit(Str::lower($tag), self::MAX_LENGTH, '');

            if ($tag !== '' && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return array_slice($tags, 0, self::MAX_TAGS);
    }

    /**
     * Every tag in use in a company, for the suggestions list on forms and the filter chips.
     *
     * @return list<string>
     */
    public function suggestions(int $organizationId): array
    {
        $all = collect();

        foreach ([CrmLead::class, CrmTender::class, CrmCompanyProfile::class] as $model) {
            $model::query()
                ->where('organization_id', $organizationId)
                ->whereNotNull('tags')
                ->pluck('tags')
                ->each(fn ($tags) => $all->push(...(is_array($tags) ? $tags : [])));
        }

        return $all->filter()->unique()->sort()->values()->all();
    }
}
