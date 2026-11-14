<?php

namespace App\Services\VoiceDpr;

use App\Models\BoqItem;
use App\Services\DocumentReading\PrintedNumber;
use App\Services\DocumentReading\UnitText;
use App\Support\Dec;
use Illuminate\Support\Str;

/**
 * Turns the model's answer into the proposal the phone shows, and checks every figure in it against
 * the exact words it came from. Nothing the model says is taken on trust:
 *
 * - heard: the "_spoken" words stand in the transcript as one whole number, they say exactly the
 *   proposed value, no other field leans on the same words, and they are not a size, a grade or a
 *   time of day ("12 mm", "M 25", "9 वाजता").
 * - anchored: the trade (for a count) or the unit (for a quantity) is said within a few words of
 *   those words, in the same clause, and each is the other's nearest: in "सहा गवंडी, बारा हेल्पर" the
 *   twelve belongs to the helpers whatever the model says.
 * - the trade and the weather are from the lists sent, the BOQ line is one of the refs sent, the
 *   unit is compared with the line's own, and the limits are those of a DPR.
 * - a figure in the hindrances or the summary that nobody said is reported.
 *
 * Which BOQ line a quantity belongs to is the model's choice, and no check here can confirm it: the
 * phone asks for a tap on every work row.
 */
final class VoiceDraftChecks
{
    /** The largest count a DPR takes (DprService::rules). */
    private const MOST_WORKERS = '100000';

    /** The largest quantity a DPR takes (DprService::rules). */
    private const MOST_QUANTITY = '99999999';

    private SpokenNumbers $numbers;

    private Transcript $transcript;

    /** @var list<array{start:int, end:int, first:int, last:int, value:string, plain:bool}> */
    private array $mentions;

    /** @var array<int, true> Words already backing a field. */
    private array $used = [];

    /** @var array<string, int> Field => where its words start in the transcript, for the record. */
    private array $backing = [];

    public function __construct(string $transcript, string $locale)
    {
        $this->numbers = new SpokenNumbers($locale);
        $this->transcript = new Transcript($transcript);
        $this->mentions = $this->transcript->mentions($this->numbers);
    }

    /**
     * @param  array<string, mixed>  $output  The model's answer, already of the right shape.
     * @param  array<string, BoqItem>  $items  The lines offered, by ref.
     * @return array{proposal: array<string, mixed>, backing: array<string, int>}
     */
    public function proposal(array $output, array $items): array
    {
        $most = (int) config('modules.voice_dpr.max_rows');
        $manpower = array_slice((array) ($output['manpower'] ?? []), 0, $most);
        $work = array_slice((array) ($output['work'] ?? []), 0, $most);

        $trades = $this->spans(array_map(fn ($row) => (string) ($row['trade_spoken'] ?? ''), $manpower));
        $units = $this->spans(array_map(fn ($row) => (string) ($row['unit_spoken'] ?? ''), $work));

        $people = [];

        foreach ($manpower as $index => $row) {
            $count = $this->figure((string) ($row['count'] ?? ''), 'count', self::MOST_WORKERS);
            $spoken = self::cut($row['count_spoken'] ?? '', 120);
            $trade = self::cut($row['trade_spoken'] ?? '', 60);
            [$heard, $anchored] = $this->bind("manpower.{$index}.count", $spoken, $count, $trade, $trades, false);

            $people[] = [
                'trade' => self::fromList((string) ($row['trade'] ?? ''), (array) config('buildos.trades')),
                'trade_spoken' => $trade,
                'count' => $count ?? '',
                'count_spoken' => $spoken,
                'heard' => $heard,
                'anchored' => $anchored,
            ];
        }

        $rows = [];

        foreach ($work as $index => $row) {
            $item = $items[(string) ($row['item'] ?? '')] ?? null;
            $qty = $this->figure((string) ($row['qty'] ?? ''), 'qty', self::MOST_QUANTITY);
            $spoken = self::cut($row['qty_spoken'] ?? '', 120);
            $unit = self::cut($row['unit_spoken'] ?? '', 60);
            [$heard, $anchored] = $this->bind("work.{$index}.qty", $spoken, $qty, $unit, $units, true);

            $rows[] = [
                'boq_item_id' => $item?->id,
                'code' => (string) ($item?->code ?? ''),
                'description' => $item !== null ? self::cut($item->description, 255) : self::cut($row['description'] ?? '', 255),
                'qty' => $qty === null ? '' : Dec::qty($qty),
                'qty_spoken' => $spoken,
                'unit_spoken' => $unit,
                'unit_item' => $item?->unit,
                'unit_ok' => $item !== null && $this->sameUnit($unit, (string) $item->unit),
                'heard' => $heard,
                'anchored' => $anchored,
            ];
        }

        $hindrances = self::cut($output['hindrances'] ?? '', 5000);
        $summary = self::cut($output['summary'] ?? '', 5000);

        return [
            'proposal' => [
                'weather' => $this->weather((string) ($output['weather'] ?? ''), (string) ($output['weather_spoken'] ?? '')),
                'manpower' => $people,
                'work' => $rows,
                'hindrances' => $hindrances,
                'summary' => $summary,
                'unused_numbers' => $this->unused(),
                'text_has_unheard_number' => $this->unheardIn($hindrances) || $this->unheardIn($summary),
            ],
            'backing' => $this->backing,
        ];
    }

    /**
     * Ties a figure to the words it came from.
     *
     * @param  list<array{start:int, end:int, first:int, last:int}>  $anchors  Where every trade (or every unit) of the answer was said.
     * @return array{0: bool, 1: bool} heard, anchored
     */
    private function bind(string $field, string $spoken, ?string $value, string $anchor, array $anchors, bool $anchorOptional): array
    {
        $said = $value === null || Transcript::phrase($spoken) === '' ? null : $this->numbers->read($spoken);

        if ($said === null || ! Dec::of($said)->isEqualTo(Dec::of($value))) {
            return [false, false];
        }

        $places = array_values(array_filter(
            $this->transcript->occurrences($spoken),
            fn (array $place) => $this->isPlainMention($place) && ! $this->isUsed($place),
        ));

        if ($places === []) {
            return [false, false];
        }

        $chosen = $places[0];
        $anchored = false;

        if ($anchorOptional && Transcript::phrase($anchor) === '') {
            // No unit was said: there is nothing to tie the quantity to, and the unit is marked as not matching.
            $anchored = true;
        } else {
            foreach ($places as $place) {
                if ($this->anchored($place, $anchor, $anchors)) {
                    [$chosen, $anchored] = [$place, true];
                    break;
                }
            }
        }

        for ($word = $chosen['first']; $word <= $chosen['last']; $word++) {
            $this->used[$word] = true;
        }

        $this->backing[$field] = $chosen['start'];

        return [true, $anchored];
    }

    /**
     * @param  array{start:int, end:int, first:int, last:int}  $place
     * @param  list<array{start:int, end:int, first:int, last:int}>  $anchors
     */
    private function anchored(array $place, string $anchor, array $anchors): bool
    {
        $within = (int) config('modules.voice_dpr.anchor_words');
        $plain = array_values(array_filter($this->mentions, fn (array $mention) => $mention['plain']));

        foreach ($this->transcript->occurrences($anchor) as $said) {
            $gap = $this->transcript->gap($place, $said);

            if ($gap < 0 || $gap > $within || $this->transcript->clauseBreakBetween($place, $said)) {
                continue;
            }

            // The number is the one nearest to the word, and the word the one nearest to the number.
            if ($this->nearest($said, $place, $plain) && $this->nearest($place, $said, $anchors)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether, of the candidates in the same clause as $from, $target alone is the closest to it.
     *
     * @param  array{start:int, end:int, first:int, last:int}  $from
     * @param  array{start:int, end:int, first:int, last:int}  $target
     * @param  list<array{start:int, end:int, first:int, last:int}>  $candidates
     */
    private function nearest(array $from, array $target, array $candidates): bool
    {
        $best = null;
        $holders = [];

        foreach ($candidates as $candidate) {
            $gap = $this->transcript->gap($from, $candidate);

            if ($gap < 0 || $this->transcript->clauseBreakBetween($from, $candidate)) {
                continue;
            }

            $key = $candidate['first'].':'.$candidate['last'];

            if ($best === null || $gap < $best) {
                [$best, $holders] = [$gap, [$key => true]];
            } elseif ($gap === $best) {
                $holders[$key] = true;
            }
        }

        return count($holders) === 1 && isset($holders[$target['first'].':'.$target['last']]);
    }

    /**
     * Every place any of these phrases was said.
     *
     * @param  list<string>  $phrases
     * @return list<array{start:int, end:int, first:int, last:int}>
     */
    private function spans(array $phrases): array
    {
        $spans = [];

        foreach (array_unique($phrases) as $phrase) {
            foreach ($this->transcript->occurrences(self::cut($phrase, 60)) as $place) {
                $spans[$place['first'].':'.$place['last']] = $place;
            }
        }

        return array_values($spans);
    }

    /** @param  array{first:int, last:int}  $place */
    private function isPlainMention(array $place): bool
    {
        foreach ($this->mentions as $mention) {
            if ($mention['plain'] && $mention['first'] === $place['first'] && $mention['last'] === $place['last']) {
                return true;
            }
        }

        return false;
    }

    /** @param  array{first:int, last:int}  $place */
    private function isUsed(array $place): bool
    {
        for ($word = $place['first']; $word <= $place['last']; $word++) {
            if (isset($this->used[$word])) {
                return true;
            }
        }

        return false;
    }

    /** A proposed figure as a plain decimal, or null when it is not one a DPR takes. */
    private function figure(string $raw, string $kind, string $most): ?string
    {
        $read = PrintedNumber::parse($raw, $kind);

        return $read->ok && ! Dec::of((string) $read->value)->isGreaterThan(Dec::of($most)) ? (string) $read->value : null;
    }

    private function sameUnit(string $spoken, string $unit): bool
    {
        $heard = UnitText::normalise($spoken);
        $line = UnitText::normalise($unit);

        return $heard['known'] && UnitText::key($heard['unit']) === UnitText::key($line['unit']);
    }

    /** The weather from the list, and only when the words given for it were said. */
    private function weather(string $weather, string $spoken): string
    {
        $listed = self::fromList($weather, (array) config('buildos.weather'));

        return $listed !== '' && Transcript::phrase($spoken) !== '' && $this->transcript->occurrences($spoken) !== [] ? $listed : '';
    }

    /** @return list<string> Numbers said that back no field, each value once. Sizes, grades and times are not counted. */
    private function unused(): array
    {
        $values = [];

        foreach ($this->mentions as $mention) {
            if ($mention['plain'] && ! $this->isUsed($mention)) {
                $values[] = $mention['value'];
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Whether text the model wrote holds a figure nobody said: any digits, and any number word beside
     * a word of quantity, whose value is not among the numbers of the transcript.
     */
    private function unheardIn(string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }

        $written = new Transcript($text);
        $said = array_map(fn (array $mention) => Dec::of($mention['value']), $this->mentions);
        $quantityWords = $this->quantityWords();

        foreach ($written->mentions($this->numbers) as $mention) {
            $digits = preg_match('/\p{N}/u', mb_substr($written->text(), $mention['start'], $mention['end'] - $mention['start'])) === 1;

            if (! $digits && ! $this->besideQuantityWord($written, $mention, $quantityWords)) {
                continue;
            }

            $value = Dec::of($mention['value']);

            if (array_filter($said, fn ($heard) => $heard->isEqualTo($value)) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{first:int, last:int}  $mention
     * @param  array<string, true>  $quantityWords
     */
    private function besideQuantityWord(Transcript $text, array $mention, array $quantityWords): bool
    {
        foreach ([$mention['first'] - 1, $mention['last'] + 1] as $index) {
            if (isset($text->tokens()[$index])) {
                $word = $text->word($index);

                if (isset($quantityWords[$word]) || isset($quantityWords[UnitText::key($word)])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, true> Unit spellings, trades and the configured words of quantity, in lower case. */
    private function quantityWords(): array
    {
        $words = [];

        foreach (array_keys(UnitText::words()) as $unit) {
            $words[(string) $unit] = true;
        }

        foreach ([...(array) config('buildos.trades'), ...(array) config('modules.voice_dpr.quantity_words')] as $word) {
            $words[SpokenNumbers::lower((string) $word)] = true;
        }

        return $words;
    }

    /** The value as the list spells it, compared without regard to case, or empty. */
    private static function fromList(string $value, array $list): string
    {
        foreach ($list as $listed) {
            if (mb_strtolower(trim($value)) === mb_strtolower((string) $listed)) {
                return (string) $listed;
            }
        }

        return '';
    }

    /** Model text as plain text of at most this many characters. */
    private static function cut(mixed $text, int $length): string
    {
        $text = is_string($text) ? $text : '';
        $text = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', mb_check_encoding($text, 'UTF-8') ? $text : '');

        return Str::limit(trim($text), $length, '');
    }
}
