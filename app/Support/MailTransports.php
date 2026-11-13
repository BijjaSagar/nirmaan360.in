<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;

/**
 * The transports a mailer really sends through, read the way Laravel's MailManager reads them: a mailer's
 * MAIL_URL scheme wins over its transport key, the old single-driver mail config still counts, and a failover
 * or roundrobin mailer is followed into every mailer it names. The mailer's name alone says little: a mailer
 * called "failover" can fall back to "log", and a mailer of any name can use the log transport.
 */
final class MailTransports
{
    /** The log transport writes the whole message, links and tokens included, into the log. The array transport sends nothing. */
    public const KEEPS_MAIL = ['log', 'array'];

    /**
     * Every mailer the given one (the default when null) can hand a message to, itself first, each with its
     * transport. A mailer that is not defined has null.
     *
     * @return array<string, ?string>
     */
    public static function chain(?string $mailer = null): array
    {
        $chain = [];
        self::walk($mailer ?? (string) config('mail.default'), $chain);

        return $chain;
    }

    /**
     * The first mailer in the chain that could keep a message instead of sending it, with its transport, or
     * null when every mailer reached sends. A mailer that is not defined counts as keeping it: nobody can say
     * where its mail goes.
     *
     * @return ?array{mailer: string, transport: string}
     */
    public static function firstThatKeepsMail(?string $mailer = null): ?array
    {
        foreach (self::chain($mailer) as $name => $transport) {
            if ($transport === null || in_array($transport, self::KEEPS_MAIL, true)) {
                return ['mailer' => $name, 'transport' => $transport ?? 'not defined'];
            }
        }

        return null;
    }

    /** @param  array<string, ?string>  $chain */
    private static function walk(string $mailer, array &$chain): void
    {
        if (array_key_exists($mailer, $chain)) {
            return;
        }

        $config = self::config($mailer);
        $transport = $config['transport'] ?? null;
        $chain[$mailer] = is_string($transport) && $transport !== '' ? $transport : null;

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach ((array) ($config['mailers'] ?? []) as $inner) {
                self::walk((string) $inner, $chain);
            }
        }
    }

    /** As MailManager::getConfig and createSymfonyTransport read it. */
    private static function config(string $mailer): ?array
    {
        $config = config('mail.driver') ? config('mail') : config("mail.mailers.{$mailer}");

        if (! is_array($config)) {
            return null;
        }

        if (isset($config['url'])) {
            $config = array_merge($config, (new ConfigurationUrlParser)->parseConfiguration($config));
            $config['transport'] = Arr::pull($config, 'driver');
        }

        $config['transport'] ??= config('mail.driver');

        return $config;
    }
}
