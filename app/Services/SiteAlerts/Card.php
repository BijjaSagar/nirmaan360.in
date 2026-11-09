<?php

namespace App\Services\SiteAlerts;

use JsonSerializable;

/**
 * What one dashboard card shows, already worded for the viewer. The web page and the mobile API
 * render the same object, so a card never needs its own view.
 */
final class Card implements JsonSerializable
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const BAD = 'bad';

    /**
     * @param  ?string  $value  The headline figure, for example 35% or 90 of 180 days.
     * @param  ?string  $tone  ok, warn or bad; colours the headline. Null is neutral.
     * @param  list<array{label:string, percent:string, tone?:string}>  $meters  Bars from 0 to 100.
     * @param  list<array{label:string, value?:?string, sub?:?string, href?:?string, tone?:?string}>  $rows  A short list under the figure.
     * @param  bool  $money  True when the card carries money figures; such a card must already be limited to money.overview.
     */
    public function __construct(
        public string $key,
        public string $title,
        public int $order = 50,
        public ?string $value = null,
        public ?string $note = null,
        public ?string $tone = null,
        public array $meters = [],
        public array $rows = [],
        public ?string $href = null,
        public ?string $action = null,
        public bool $wide = false,
        public bool $money = false,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'value' => $this->value,
            'note' => $this->note,
            'tone' => $this->tone,
            'meters' => $this->meters,
            'rows' => $this->rows,
            'url' => $this->href,
            'money' => $this->money,
        ];
    }
}
