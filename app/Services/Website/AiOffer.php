<?php

namespace App\Services\Website;

use App\Models\ClientQuotation;
use App\Services\Agents\Autonomy;
use App\Services\Agents\ModelGateway;
use App\Services\DocumentReading\AiSpend;
use App\Services\Wording\ReviewedLanguages;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Route;

/**
 * What the website may say about AI, in one place, so the AI page, the home page and the pricing page
 * cannot drift apart or run ahead of the code.
 *
 * The four agents are built, so they are always described. While no key is saved they cannot run for
 * anyone, so the pages say they are in early access and ask for requests; once the platform is connected
 * to the AI model they are spoken of as working and as part of the Business and Enterprise plans, and
 * nothing on the page says early access any more. The hero tag, the intro, the button and the pricing
 * line all follow that one state.
 *
 * Readers send a page, a photo or spoken words to the AI model. A reader gets a card, and the word
 * "readers" appears at all, only while the readers are on for that reader (AiSpend::readersOn, the same
 * test the consent switch, the privacy policy and the menu use) and the route that opens it is deployed.
 * Helpers work from the company's own records. Each gets a card once its module's route is deployed,
 * key or no key, under a heading that says so and does not call them AI.
 *
 * A card waits for the real name of the route its module registers; for a module not built yet it is the
 * name the design gives, so registering that route is what makes its card appear.
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

    /** The headings of the groups of cards on the AI page. */
    public const AGENTS_GROUP = 'Agents';

    public const READERS_GROUP = 'Readers';

    public const HELPERS_GROUP = 'Helpers that work from your own records';

    /** Folder under app/Services/Agents/Definitions => icon, name, what it does. */
    private const AGENTS = [
        'site_reporter' => ['message', 'Site Reporter', 'Type what happened on site in Marathi, Hindi or English. It prepares a draft DPR and asks one question if something is unclear.'],
        'measurement_billing' => ['bill', 'Measurement and Billing', 'Drafts client and subcontractor RA bills from approved measurements.'],
        'project_brain' => ['chart', 'Project Brain', 'Ask about a project\'s BOQ, measurements, reports and bills and get the answer with links to the records.'],
        'owners_copilot' => ['users', 'Owner\'s Copilot', 'A morning brief built from your own records.'],
    ];

    /**
     * Readers: route that must exist => icon, name, what it does, and the reader kind it reads with (null
     * for voice, which counts as a reader by its route). None of them is built yet.
     */
    private const READERS = [
        'boq-scans.create' => ['scan', 'Scanned BOQ reader', 'Reads a scanned or photographed BOQ or estimate page by page. Every line where quantity times rate does not equal the printed amount, or the total does not add up, is stopped for you to fix, and you confirm every line before anything is saved.', 'boq'],
        'slip-reads.store' => ['camera', 'Challan and bill photo', 'Fills the challan or bill form from a photo. You check each field against the photo and type the counted quantity yourself.', 'slip'],
        'tender-reads.show' => ['file', 'Tender reader', 'Lists the dates, deposits, periods and documents a tender asks for, each with its page, and shows both places when the tender says two different things.', 'tender'],
        self::VOICE_ROUTE => ['phone', 'Voice DPR', 'Speak the day\'s report in Marathi, Hindi or English in the field app, on phones that can turn speech into text by themselves. It becomes a draft for you to check and send.', null],
    ];

    /** Helpers that work from the company's own records: route that must exist => icon, name, what it does. Each is built. */
    private const HELPERS = [
        'bill-checks.run' => ['clipboard', 'RA bill pre-check', 'Re-adds the bill, compares it with earlier bills, the BOQ and approved measurements, and explains each finding in Marathi, Hindi or English.'],
        'rate-suggestions.store' => ['rupee', 'Rate helper', 'Suggests rates from your own rate library and past work, with the source and date on every figure. You accept each line yourself.'],
        'site-diary.hindrances.index' => ['flag', 'Hindrance register and letters', 'Keeps a dated register from your daily reports and drafts extension, delay and payment letters with every date and figure taken from your records.'],
        self::OWNER_PACK_ROUTE => ['calendar', 'Home-owner pack', 'A quotation from your BOQ with a stage-payment schedule, and a weekly update of the work in your approved daily reports and measurements, in Marathi, Hindi or English.'],
    ];

    /**
     * The home-owner pack's card names the three languages only while all three are offered for its documents.
     * On the live site Hindi and Marathi wait for a native reader's review (ReviewedLanguages), and until then
     * the card says what the pack holds without naming a language.
     */
    private const OWNER_PACK_ROUTE = 'owner-updates.index';

    private const OWNER_PACK_UNNAMED_LANGUAGES = 'For the home owner: a quotation from your BOQ with a stage-payment schedule, and a weekly update of the work in your approved daily reports and measurements.';

    /** Autonomy level => name on the website, what it means. A level the agents do not have is never shown. */
    private const LEVELS = [
        'L0' => ['Advise', 'It answers and recommends. Nothing changes.'],
        'L1' => ['Draft', 'It prepares a record for a person to submit.'],
        'L2' => ['Act with approval', 'After one tap from an approver it sends the draft into your normal approval chain.'],
    ];

    /** The tag above the heading of the AI page and of the home page's AI section. */
    private const TAG_NOT_LIVE = 'Early access';

    private const TAG_LIVE = 'Part of the Business and Enterprise plans';

    /** Every sentence that says something is drafted names the agents, never the helpers. */
    private const INTRO_LIVE = 'The agents work on the records already in your account. What an agent prepares is saved as a draft and marked as one, and nothing it prepares is approved, posted to stock, billed or sent outside your company until a person confirms it.';

    private const INTRO_NOT_LIVE = 'These agents work on the records already in your account. None of them are live yet. Tell us which one would save you the most time.';

    /** The button under the intro: label, route name, route parameters. */
    private const ACTION_NOT_LIVE = ['Request early access', 'contact', ['type' => 'ai_early_access']];

    private const ACTION_LIVE = ['See pricing', 'pricing', []];

    private const PRICING_NOT_LIVE = 'AI agents are in early access and are not part of these plans yet. Prices are in Indian rupees.';

    private const PRICING_AGENTS = 'AI agents are part of the Business and Enterprise plans and use AI credits. Prices are in Indian rupees.';

    private const PRICING_AGENTS_AND_READERS = 'AI agents and readers are part of the Business and Enterprise plans and use AI credits. Prices are in Indian rupees.';

    public function __construct(private ModelGateway $gateway, private PlatformSettings $settings, private AiSpend $spend) {}

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

    /** The tag above the heading: early access only while the agents cannot run. */
    public function tag(): string
    {
        return $this->agentsLive() ? self::TAG_LIVE : self::TAG_NOT_LIVE;
    }

    /** The paragraph under the heading of the AI page. */
    public function intro(): string
    {
        return $this->agentsLive() ? self::INTRO_LIVE : self::INTRO_NOT_LIVE;
    }

    /** @return array{0:string,1:string,2:array<string,string>} The button under the intro: its label, route name and route parameters. */
    public function action(): array
    {
        return $this->agentsLive() ? self::ACTION_LIVE : self::ACTION_NOT_LIVE;
    }

    /** The readers are on: the one test (AiSpend::readersOn), asked here without a company, as the website has none. */
    public function readersOn(): bool
    {
        return $this->spend->readersOn();
    }

    /** @return list<array{0:string,1:string,2:string}> Icon, name and text of each reader that may be shown today. */
    public function readers(): array
    {
        $shown = [];

        foreach (self::READERS as $route => [$icon, $name, $text, $kind]) {
            if (Route::has($route) && $this->spend->readersOn(null, $kind) && ($route !== self::VOICE_ROUTE || $this->settings->get(self::VOICE_ACCEPTED) === '1')) {
                $shown[] = [$icon, $name, $text];
            }
        }

        return $shown;
    }

    /** @return list<array{0:string,1:string,2:string}> Icon, name and text of each helper whose module is deployed. */
    public function helpers(): array
    {
        $shown = [];

        foreach (self::HELPERS as $route => [$icon, $name, $text]) {
            if (Route::has($route)) {
                $shown[] = [$icon, $name, $route === self::OWNER_PACK_ROUTE ? $this->ownerPackText($text) : $text];
            }
        }

        return $shown;
    }

    /** @return array<string, list<array{0:string,1:string,2:string}>> Heading => cards, for every group that has a card today. */
    public function groups(): array
    {
        return array_filter([
            self::AGENTS_GROUP => $this->agents(),
            self::READERS_GROUP => $this->readers(),
            self::HELPERS_GROUP => $this->helpers(),
        ]);
    }

    /** The line under the plan table. */
    public function pricingLine(): string
    {
        return match (true) {
            ! $this->agentsLive() => self::PRICING_NOT_LIVE,
            $this->readersOn() => self::PRICING_AGENTS_AND_READERS,
            default => self::PRICING_AGENTS,
        };
    }

    /** @return list<string> Every line this class can put on a page, whatever is switched on. Tests read it. */
    public function everyLine(): array
    {
        $lines = [
            self::AGENTS_GROUP, self::READERS_GROUP, self::HELPERS_GROUP, self::TAG_NOT_LIVE, self::TAG_LIVE,
            self::INTRO_LIVE, self::INTRO_NOT_LIVE, self::ACTION_LIVE[0], self::ACTION_NOT_LIVE[0],
            self::PRICING_NOT_LIVE, self::PRICING_AGENTS, self::PRICING_AGENTS_AND_READERS, self::OWNER_PACK_UNNAMED_LANGUAGES,
        ];

        foreach ([...self::AGENTS, ...array_values(self::READERS), ...array_values(self::HELPERS)] as [, $name, $text]) {
            $lines[] = $name;
            $lines[] = $text;
        }

        foreach (self::LEVELS as [$name, $text]) {
            $lines[] = $name;
            $lines[] = $text;
        }

        return $lines;
    }

    /** The card's text names Marathi, Hindi and English only while a quotation and an update can be prepared in each. */
    private function ownerPackText(string $text): string
    {
        return ReviewedLanguages::allOffered(ReviewedLanguages::OWNER_PACK, ClientQuotation::LANGUAGES) ? $text : self::OWNER_PACK_UNNAMED_LANGUAGES;
    }
}
