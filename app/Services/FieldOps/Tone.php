<?php

namespace App\Services\FieldOps;

/**
 * The label and colour of every status chip in the assets and QHS screens.
 * Labels are English here and go through __() when shown.
 */
final class Tone
{
    /** set => value => [label, tone]. Tones: good, warn, bad, info, muted. */
    private const SETS = [
        'asset_status' => ['active' => ['Active', 'good'], 'under_repair' => ['Under repair', 'warn'], 'retired' => ['Retired', 'muted']],
        'ownership' => ['owned' => ['Owned', 'muted'], 'hired' => ['Hired', 'info']],
        'maintenance' => ['overdue' => ['Overdue', 'bad'], 'due_soon' => ['Due soon', 'warn'], 'ok' => ['On schedule', 'good']],
        'bill' => ['DRAFT' => ['Draft', 'muted'], 'FINAL' => ['Final', 'info'], 'PAID' => ['Paid', 'good'], 'CANCELLED' => ['Cancelled', 'bad']],
        'breakdown' => ['open' => ['Open', 'bad'], 'resolved' => ['Resolved', 'good']],
        'ncr_status' => ['open' => ['Open', 'bad'], 'in_progress' => ['In Progress', 'info'], 'resolved' => ['Resolved', 'good'], 'closed' => ['Closed', 'muted']],
        'severity' => [
            'minor' => ['Minor', 'muted'], 'major' => ['Major', 'warn'],
            'low' => ['Low', 'muted'], 'medium' => ['Medium', 'warn'], 'high' => ['High', 'bad'], 'critical' => ['Critical', 'bad'],
        ],
        'incident_status' => ['open' => ['Open', 'bad'], 'investigating' => ['Investigating', 'info'], 'closed' => ['Closed', 'muted']],
        'kind' => ['incident' => ['Incident', 'bad'], 'near_miss' => ['Near miss', 'warn']],
        'injury' => [
            'none' => ['No injury', 'muted'], 'first_aid' => ['First aid', 'warn'], 'medical' => ['Medical treatment', 'warn'],
            'lost_time' => ['Lost time', 'bad'], 'fatal' => ['Fatal', 'bad'],
        ],
        'inspection' => ['in_progress' => ['In Progress', 'info'], 'passed' => ['Passed', 'good'], 'failed' => ['Failed', 'bad']],
        'result' => ['pending' => ['Pending', 'muted'], 'pass' => ['Pass', 'good'], 'fail' => ['Fail', 'bad'], 'na' => ['Not applicable', 'muted']],
        'verdict' => [
            'pending' => ['Pending', 'muted'], 'ok' => ['On track', 'good'], 'low' => ['Low early strength', 'warn'],
            'pass' => ['Pass', 'good'], 'fail' => ['Below grade', 'bad'],
        ],
        'rfi' => [
            'draft' => ['Draft', 'muted'], 'in_review' => ['In Review', 'info'], 'returned' => ['Returned', 'warn'], 'rejected' => ['Rejected', 'bad'],
            'issued' => ['Issued', 'info'], 'answered' => ['Answered', 'good'], 'closed' => ['Closed', 'muted'],
        ],
    ];

    /** @return array{0:string,1:string} The English label and the tone. */
    public static function chip(string $set, ?string $value): array
    {
        return self::SETS[$set][(string) $value] ?? [(string) $value, 'muted'];
    }

    /** @return list<string> Every label, so each can be checked for a translation. */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::SETS as $values) {
            foreach ($values as [$label]) {
                $labels[$label] = true;
            }
        }

        return array_keys($labels);
    }
}
