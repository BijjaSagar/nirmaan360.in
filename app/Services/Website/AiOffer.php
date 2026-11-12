<?php

namespace App\Services\Website;

use App\Services\Agents\Autonomy;
use App\Services\Agents\ModelGateway;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Route;

/**
 * What the website may say about AI, in one place, so the AI page, the home page and the pricing page
 * cannot drift apart or run ahead of the code.
 *
 * The four agents are built, so they are always described. They are spoken of as live, and as part of a
 * plan, only while the platform is connected to the AI model. A reader or helper gets a card only once the
 * route that opens it is deployed, and one that needs the AI model also waits for the platform admin's
 * release switch. A later module needs no change here: registering its route under the name in CARDS is
 * what makes its card appear.
 *
 * Every line is English and goes through __() in the view. Hindi and Marathi are in lang/modules/website.
 */
class AiOffer
{
    /** Platform setting saved as "1" once the readers may be offered to companies. */
    public const RELEASED = 'ai_readers_released';

    /** Platform setting saved as "1" once speech was shown to stay on the phone for each language offered. */
    public const VOICE_ACCEPTED = 'ai_voice_accepted';

    /** The field app's endpoint that takes the words heard on the phone. */
    public const VOICE_ROUTE = 'api.dpr-voice-drafts.store';

    /** Folder under app/Services/Agents/Definitions => icon, name, what it does. */
    private const AGENTS = [
        'site_reporter' => ['message', 'Site Reporter', 'Type what happened on site in Marathi, Hindi or English. It prepares a draft DPR and asks one question if something is unclear.'],
        'measurement_billing' => ['bill', 'Measurement and Billing', 'Drafts client and subcontractor RA bills from approved measurements.'],
        'project_brain' => ['chart', 'Project Brain', 'Ask about a project\'s BOQ, measurements, reports and bills and get the answer with links to the records.'],
        'owners_copilot' => ['users', 'Owner\'s Copilot', 'A morning brief built from your own records.'],
    ];

    /** Route that must exist => icon, name, what it does, and whether it cannot work without the AI model. */
    private const CARDS = [
        'boq-scans.create' => ['scan', 'Scanned BOQ reader', 'Reads a scanned or photographed BOQ or estimate page by page. Every line where quantity times rate does not equal the printed amount, or the total does not add up, is stopped for you to fix, and you confirm every line before anything is saved.', true],
        'slip-reads.store' => ['camera', 'Challan and bill photo', 'Fills the challan or bill form from a photo. You check each field against the photo and type the counted quantity yourself.', true],
        'tender-reads.show' => ['file', 'Tender reader', 'Lists the dates, deposits, periods and documents a tender asks for, each with its page, and shows both places when the tender says two different things.', true],
        'bill-checks.store' => ['clipboard', 'RA bill pre-check', 'Re-adds the bill, compares it with earlier bills, the BOQ and approved measurements, and explains each finding in Marathi, Hindi or English.', false],
        'rate-suggestions.store' => ['rupee', 'Rate helper', 'Suggests rates from your own rate library and past work, with the source and date on every figure. You accept each line yourself.', false],
        'hindrances.index' => ['flag', 'Hindrance register and letters', 'Keeps a dated register from your daily reports and drafts extension, delay and payment letters with every date and figure taken from your records.', false],
        'owner-updates.index' => ['calendar', 'Home-owner pack', 'A quotation, a stage-payment schedule and a weekly update in Marathi, Hindi or English, with the figures taken from your approved records.', false],
        self::VOICE_ROUTE => ['phone', 'Voice DPR', 'Speak the day\'s report in Marathi, Hindi or English in the field app, on phones that can turn speech into text by themselves. It becomes a draft for you to check and send.', true],
    ];

    /** Autonomy level => name on the website, what it means. A level the agents do not have is never shown. */
    private const LEVELS = [
        'L0' => ['Advise', 'It answers and recommends. Nothing changes.'],
        'L1' => ['Draft', 'It prepares a record for a person to submit.'],
        'L2' => ['Act with approval', 'After one tap from an approver it sends the draft into your normal approval chain.'],
    ];

    private const INTRO_LIVE = 'These work on the records already in your account. What the AI prepares is saved as a draft and marked as one. Nothing is approved, posted to stock, billed or sent outside your company until a person confirms it.';

    private const INTRO_NOT_LIVE = 'These agents work on the records already in your account. None of them are live yet. Tell us which one would save you the most time.';

    private const PRICING_NOT_LIVE = 'AI agents are in early access and are not part of these plans yet. Prices are in Indian rupees.';

    private const PRICING_AGENTS = 'AI agents are part of the Business and Enterprise plans and use AI credits. Prices are in Indian rupees.';

    private const PRICING_AGENTS_AND_READERS = 'AI agents and readers are part of the Business and Enterprise plans and use AI credits. Prices are in Indian rupees.';

    public function __construct(private ModelGateway $gateway, private PlatformSettings $settings) {}

    /** With no key saved the agents cannot run for anyone, so nothing is presented as live. */
    public function agentsLive(): bool
    {
        return $this->gateway->isConnected();
    }

    /** @return list<array{0:string,1:string,2:string}> Icon, name and text of each agent. */
    public function agents(): array
    {
        return array_values(self::AGENTS);
    }

    /** @return list<string> */
    public function agentKeys(): array
    {
        return array_keys(self::AGENTS);
    }

    /** @return list<array{0:string,1:string}> Name and text of each level, lowest first. */
    public function levels(): array
    {
        return array_values(array_intersect_key(self::LEVELS, Autonomy::LEVELS));
    }

    /** The paragraph under the heading of the AI page. */
    public function intro(): string
    {
        return $this->agentsLive() ? self::INTRO_LIVE : self::INTRO_NOT_LIVE;
    }

    /** The platform admin has released the readers, and the model they need is connected. */
    public function readersReleased(): bool
    {
        return $this->agentsLive() && $this->settings->get(self::RELEASED) === '1';
    }

    /** @return list<array{0:string,1:string,2:string}> Icon, name and text of each reader or helper that may be shown today. */
    public function cards(): array
    {
        $shown = [];

        foreach (self::CARDS as $route => [$icon, $name, $text, $needsModel]) {
            if ($this->cardIsOffered($route, $needsModel)) {
                $shown[] = [$icon, $name, $text];
            }
        }

        return $shown;
    }

    /** A reader is deployed and released: the same test the company's consent switch is shown by. */
    public function readerDeployed(): bool
    {
        $kinds = collect(config('modules', []))->pluck('read_kinds')->filter()->isNotEmpty();

        return ($kinds || Route::has(self::VOICE_ROUTE)) && $this->readersReleased();
    }

    /** The line under the plan table. */
    public function pricingLine(): string
    {
        return match (true) {
            ! $this->agentsLive() => self::PRICING_NOT_LIVE,
            $this->readerDeployed() => self::PRICING_AGENTS_AND_READERS,
            default => self::PRICING_AGENTS,
        };
    }

    /** @return list<string> Every line this class can put on a page, whatever is switched on. Tests read it. */
    public function everyLine(): array
    {
        $lines = [self::INTRO_LIVE, self::INTRO_NOT_LIVE, self::PRICING_NOT_LIVE, self::PRICING_AGENTS, self::PRICING_AGENTS_AND_READERS];

        foreach ([...self::AGENTS, ...array_values(self::CARDS)] as [, $name, $text]) {
            $lines[] = $name;
            $lines[] = $text;
        }

        foreach (self::LEVELS as [$name, $text]) {
            $lines[] = $name;
            $lines[] = $text;
        }

        return $lines;
    }

    private function cardIsOffered(string $route, bool $needsModel): bool
    {
        if (! Route::has($route)) {
            return false;
        }

        if ($needsModel && ! $this->readersReleased()) {
            return false;
        }

        return $route !== self::VOICE_ROUTE || $this->settings->get(self::VOICE_ACCEPTED) === '1';
    }
}
