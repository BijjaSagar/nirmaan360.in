<?php

namespace App\Services\Notify;

use App\Models\User;

/**
 * The kinds of push a person can switch on or off in their profile. Every push that names a
 * category obeys its switch; one with no category (a company data notice) follows only the
 * main push switch.
 */
final class PushCategory
{
    public const ALERTS = 'alerts';

    public const APPROVALS = 'approvals';

    public const PAYMENTS = 'payments';

    public const MATERIAL = 'material';

    public const ISSUES = 'issues';

    /** The only kind a contractor login receives: its own payments and bills. */
    public const CONTRACTOR = 'contractor';

    /** A staff switch is offered to someone who holds any one of these permissions. An empty list means everyone. */
    private const STAFF = [
        self::ALERTS => ['alerts.view'],
        self::APPROVALS => [],
        self::PAYMENTS => ['contractor.request', 'contractor.approve'],
        self::MATERIAL => ['material_control.reason'],
        self::ISSUES => ['issue.view'],
    ];

    /**
     * The switches this person is offered, in the order they are shown.
     *
     * @return list<string>
     */
    public static function namesFor(User $user): array
    {
        if ($user->isContractor()) {
            return [self::CONTRACTOR];
        }

        $names = [];

        foreach (self::STAFF as $name => $permissions) {
            if ($permissions === [] || array_filter($permissions, $user->hasPermission(...)) !== []) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [...array_keys(self::STAFF), self::CONTRACTOR];
    }

    public static function label(string $category, ?string $locale = null): string
    {
        return match ($category) {
            self::ALERTS => __('Site alerts', [], $locale),
            self::APPROVALS => __('Approvals', [], $locale),
            self::PAYMENTS => __('Contractor payments', [], $locale),
            self::MATERIAL => __('Material checks', [], $locale),
            self::ISSUES => __('Issues', [], $locale),
            self::CONTRACTOR => __('My payments and bills', [], $locale),
            default => $category,
        };
    }

    public static function hint(string $category, ?string $locale = null): string
    {
        return match ($category) {
            self::ALERTS => __('New alerts on your projects, such as work falling behind schedule. Critical ones reach you at any hour.', [], $locale),
            self::APPROVALS => __('Something is waiting for your approval, or a decision was made on what you sent.', [], $locale),
            self::PAYMENTS => __('A contractor payment needs your approval, or one you raised was approved, rejected or sent back.', [], $locale),
            self::MATERIAL => __('A material gap or a short delivery needs a written reason.', [], $locale),
            self::ISSUES => __('An issue was assigned to you, raised as urgent, or changed status.', [], $locale),
            self::CONTRACTOR => __('A payment to you was approved, or one of your bills was paid.', [], $locale),
            default => '',
        };
    }
}
