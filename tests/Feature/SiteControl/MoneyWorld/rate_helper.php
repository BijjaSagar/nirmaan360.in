<?php

use App\Models\BoqItem;
use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRun;
use App\Services\RateHelper\RateSuggestionService;
use Illuminate\Support\Str;

/*
 * Rate helper: one run on the company's project, for its BOQ line, started the way the screen starts
 * it. The company's rate library already holds a rate for the same kind of work, in the library_rate
 * figure, so the review screen has a rate to show the owner and to keep from every site role.
 */
return function (): void {
    $run = app(RateSuggestionService::class)->start(
        $this->project,
        $this->users['owner'],
        [$this->rows[BoqItem::class]->id],
        (string) Str::uuid(),
    );

    $this->rows[RateSuggestionRun::class] = $run;
    $this->rows[RateSuggestionLine::class] = $run->lines()->firstOrFail();
};
