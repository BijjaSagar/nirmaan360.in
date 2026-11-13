<?php

namespace App\Services\DocumentReading;

use App\Models\User;
use App\Services\Agents\CreditService;
use App\Services\Agents\ModelGateway;
use App\Services\DocumentReading\Contracts\ReaderOffer;
use LogicException;

/**
 * What the field app needs to know before it shows any AI button to a person: whether the readers
 * are released and reachable, whether the company's plan and consent allow them, and which
 * features this person may use. Nothing here sends anything to the provider.
 */
class ReaderAvailability
{
    /** The features the app asks about today. Each is false until its module is deployed and registers an offer. */
    public const FEATURES = ['boq_scan', 'slip_challan', 'voice_dpr'];

    public function __construct(
        private AiSpend $spend,
        private ModelGateway $gateway,
        private ReaderSettings $settings,
        private CreditService $credits,
    ) {}

    /**
     * @return array{released:bool, connected:bool, plan:bool, consent:array{enabled:bool, version:?string, current:bool}, can:array<string, bool>, credits_free:?string, voice:array{on_device_only:bool}}
     */
    public function for(User $user): array
    {
        $organization = $user->organization;
        $plan = (bool) $organization?->allowsFeature('ai_agents');

        return [
            // The one test of whether the readers are on, as the consent switch, the menu and the website ask it.
            'released' => $organization !== null && $this->spend->readersOn($organization),
            'connected' => $this->gateway->isConnected() && ProviderBlock::current() === null,
            'plan' => $plan,
            'consent' => $organization === null ? ['enabled' => false, 'version' => null, 'current' => false] : $this->settings->consent($organization),
            'can' => $this->can($user, $plan),
            // The balance is a figure only for people who may open the credits page. Everyone else is told whether a read fits when they ask its price.
            'credits_free' => $plan && $user->hasPermission('agents.credits') ? $this->credits->free($organization) : null,
            // Speech is turned into text on the phone itself; the server takes text only and has no route for audio.
            'voice' => ['on_device_only' => true],
        ];
    }

    /**
     * Which AI features this person may use, by the name the app asks about.
     *
     * @return array<string, bool>
     */
    public function can(User $user, ?bool $plan = null): array
    {
        $plan ??= (bool) $user->organization?->allowsFeature('ai_agents');
        $may = $plan && $user->is_active && $user->hasPermission('agents.use');
        $can = array_fill_keys(self::FEATURES, false);

        foreach ($this->offers() as $name => $offer) {
            $can[$name] = $may && $offer->allows($user);
        }

        return $can;
    }

    /** @return array<string, ReaderOffer> By the name the app asks about. */
    private function offers(): array
    {
        $offers = [];

        foreach (config('modules', []) as $module) {
            foreach ($module['reader_offers'] ?? [] as $name => $class) {
                $offer = app($class);

                if (! is_string($name) || ! $offer instanceof ReaderOffer) {
                    throw new LogicException("{$class} is listed under reader_offers but is not a named ReaderOffer.");
                }

                $offers[$name] = $offer;
            }
        }

        return $offers;
    }
}
