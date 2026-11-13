<?php

namespace App\Services\RateHelper;

/**
 * The rows one source can offer during one run, kept by unit and by word so a hundred BOQ lines can
 * each be matched without reading the rows again. It holds ids and words only, never a rate.
 */
final class CandidatePool
{
    /** @var array<int, int> Row id => how many distinct words its description has. */
    private array $sizes = [];

    /** @var array<string, array<string, list<int>>> Unit key => word => the rows that have it. */
    private array $index = [];

    /** A row with a lump-sum or blank unit, or with no words to match by, is left out. */
    public function add(int $id, ?string $description, ?string $unit): void
    {
        $key = UnitGate::key($unit);
        $words = TextMatch::words((string) $description);

        if ($key === null || $words === []) {
            return;
        }

        $this->sizes[$id] = count($words);

        foreach ($words as $word) {
            $this->index[$key][$word][] = $id;
        }
    }

    /**
     * The rows in the same kind of unit whose description is alike enough, best first and then newest first.
     *
     * @return array<int, int> Row id => score.
     */
    public function matches(?string $description, ?string $unit, int $minScore, int $limit): array
    {
        $key = UnitGate::key($unit);
        $words = TextMatch::words((string) $description);

        if ($key === null || $words === [] || ! isset($this->index[$key])) {
            return [];
        }

        $shared = [];

        foreach ($words as $word) {
            foreach ($this->index[$key][$word] ?? [] as $id) {
                $shared[$id] = ($shared[$id] ?? 0) + 1;
            }
        }

        $scores = [];

        foreach ($shared as $id => $count) {
            $score = TextMatch::score($count, count($words), $this->sizes[$id]);

            if ($score >= $minScore) {
                $scores[$id] = $score;
            }
        }

        uksort($scores, fn (int $a, int $b) => [$scores[$b], $b] <=> [$scores[$a], $a]);

        return array_slice($scores, 0, max(0, $limit), true);
    }
}
