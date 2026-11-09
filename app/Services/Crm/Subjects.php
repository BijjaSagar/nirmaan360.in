<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Models\CrmTender;
use App\Models\Party;
use Illuminate\Database\Eloquent\Model;

/** What a task, activity or message can be attached to: a lead, a tender or a company (a Party). */
final class Subjects
{
    /** @var array<string,class-string<Model>> */
    private const MODELS = ['lead' => CrmLead::class, 'tender' => CrmTender::class, 'company' => Party::class];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::MODELS);
    }

    /** The record, only if it belongs to the company. */
    public static function find(?string $type, int|string|null $id, int $organizationId): ?Model
    {
        $model = self::MODELS[$type] ?? null;

        if ($model === null || $id === null || $id === '') {
            return null;
        }

        return $model::query()->where('organization_id', $organizationId)->whereKey($id)->first();
    }

    public static function typeOf(Model $subject): string
    {
        return array_search($subject::class, self::MODELS, true) ?: throw new \InvalidArgumentException('Not a CRM subject.');
    }

    /** A short name for lists and reminders. */
    public static function title(Model $subject): string
    {
        return match (true) {
            $subject instanceof CrmTender => $subject->title,
            $subject instanceof CrmLead => $subject->company_name ? $subject->company_name.' · '.$subject->name : $subject->name,
            default => (string) $subject->getAttribute('name'),
        };
    }

    public static function url(string $type, int $id): string
    {
        return match ($type) {
            'lead' => route('crm.leads.show', $id),
            'tender' => route('crm.tenders.show', $id),
            default => route('crm.companies.show', $id),
        };
    }
}
