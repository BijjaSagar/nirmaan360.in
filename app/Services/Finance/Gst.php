<?php

namespace App\Services\Finance;

use App\Support\Dec;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** GST state codes and the small sums every finance screen shares. */
final class Gst
{
    /** Spellings used on the GST portal. */
    public const STATES = [
        '01' => 'Jammu & Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh',
        '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
        '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
        '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
        '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
        '26' => 'Dadra & Nagar Haveli & Daman & Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa',
        '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
        '35' => 'Andaman & Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh',
    ];

    /** The two-digit code for a state typed as free text, or null when it is not recognised. */
    public static function stateCode(?string $state): ?string
    {
        $wanted = self::normalise((string) $state);

        if ($wanted === '') {
            return null;
        }

        foreach (self::STATES as $code => $name) {
            if (self::normalise($name) === $wanted) {
                return $code;
            }
        }

        return null;
    }

    /** The state code a GSTIN starts with, or null when it does not start with a real one. */
    public static function codeFromGstin(?string $gstin): ?string
    {
        $code = substr((string) $gstin, 0, 2);

        return isset(self::STATES[$code]) ? $code : null;
    }

    /**
     * Split GST into CGST and SGST, or put it all under IGST for an inter-state supply.
     * SGST takes any odd paisa, so the parts always add back to the tax charged.
     *
     * @return array{cgst:string, sgst:string, igst:string}
     */
    public static function split(mixed $tax, bool $interState): array
    {
        if ($interState) {
            return ['cgst' => '0.00', 'sgst' => '0.00', 'igst' => Dec::money($tax)];
        }

        $half = Dec::of($tax)->dividedBy(2, 2, RoundingMode::HalfUp);

        return ['cgst' => Dec::money($half), 'sgst' => Dec::money(Dec::of($tax)->minus($half)), 'igst' => '0.00'];
    }

    /** "2026-27" for any date from April 2026 to March 2027. */
    public static function financialYear(CarbonInterface $date): string
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return $start.'-'.substr((string) ($start + 1), -2);
    }

    /** @return array{0:string,1:string} First and last day of the financial quarter holding the date. */
    public static function quarterRange(CarbonInterface $date): array
    {
        $offset = intdiv(($date->month + 8) % 12, 3) * 3;
        $start = Carbon::create($date->month >= 4 ? $date->year : $date->year - 1, 4, 1)->addMonths($offset);

        return [$start->toDateString(), $start->copy()->addMonths(3)->subDay()->toDateString()];
    }

    private static function normalise(string $state): string
    {
        return preg_replace('/[^a-z]/', '', str_replace('&', 'and', strtolower($state))) ?? '';
    }
}
