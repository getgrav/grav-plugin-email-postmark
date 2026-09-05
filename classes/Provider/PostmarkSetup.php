<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailPostmark\Provider;

use Grav\Plugin\Email\Providers\SetupResult;
use Grav\Plugin\Email\Providers\WebhookSetup;
use Grav\Plugin\EmailPostmark\Api\PostmarkApi;

/**
 * "Set up in Postmark", which is the one button.
 *
 * A merchant who has already pasted a server token into this plugin should not
 * then have to find the Webhooks tab on the right server, work out which of the
 * five triggers to tick, paste the address again and set basic auth on it by
 * hand — every one of which is a place to get it silently wrong, and the
 * silence is the problem. Postmark's Webhooks API takes the same server token
 * the plugin already sends with, so the whole of it is one call.
 *
 * ## What it registers
 *
 * The events the caller asked for, translated into Postmark's trigger names,
 * with every other trigger explicitly off — including `SubscriptionChange`,
 * which is Postmark's own suppression list changing rather than anything that
 * happened to a message. An update leaves out what it does not mention, so
 * naming all six is what makes unticking an event actually stop it.
 *
 * If the plugin has a basic-auth user and password configured, they are set on
 * the webhook as well. That is the pair {@see PostmarkReports::verify()}
 * checks, and setting both ends from one config value is what stops them
 * drifting apart — the commonest way a store ends up with a webhook it is
 * refusing every post from.
 *
 * ## Pressing it twice
 *
 * A webhook already pointed at the same address on the same stream is updated
 * in place rather than added beside. Postmark will happily hold several
 * webhooks on one stream and post every event to all of them, and a store that
 * pressed the button twice would then record everything twice.
 */
final class PostmarkSetup implements WebhookSetup
{
    public function __construct(
        private readonly PostmarkApi $api,
        private readonly string $serverToken,
        private readonly string $stream = PostmarkApi::DEFAULT_STREAM,
        private readonly string $basicUser = '',
        private readonly string $basicPassword = '',
    ) {
    }

    public function create(string $url, array $events, array $config): SetupResult
    {
        // The plugin's own credentials win. `$config` is what the caller could
        // find for this plugin, and a caller that looked in the wrong place —
        // or in no place — should not be able to send the call out with an
        // empty token when the plugin is holding a good one.
        $token = $this->serverToken !== '' ? $this->serverToken : trim((string)($config['api_token'] ?? ''));
        $stream = $this->stream !== '' ? $this->stream : trim((string)($config['message_stream'] ?? ''));

        $triggers = [];
        foreach ($events as $event) {
            $trigger = PostmarkApi::TRIGGERS[strtolower(trim((string)$event))] ?? null;
            if ($trigger !== null && !\in_array($trigger, $triggers, true)) {
                $triggers[] = $trigger;
            }
        }

        if ($triggers === []) {
            // Creating a webhook with every trigger off is creating a webhook
            // that will never post, which looks finished and is not.
            return SetupResult::failed('None of the events asked for is one Postmark can report, so there was nothing to register.');
        }

        $answer = $this->api->createWebhook($token, $url, $triggers, $stream, [
            'user' => $this->basicUser !== '' ? $this->basicUser : trim((string)($config['basic_user'] ?? '')),
            'password' => $this->basicUser !== '' ? $this->basicPassword : (string)($config['basic_password'] ?? ''),
        ]);

        return $answer['ok']
            ? SetupResult::ok($answer['message'], $answer['id'])
            : SetupResult::failed(self::sentence($answer['message']));
    }

    public function permissionsNeeded(): string
    {
        return 'The server token from Postmark, on the server the store sends through: open that server, then API Tokens, and copy the token there. It is the same one this plugin already sends with, and Postmark puts no separate webhook permission on it. The account token from Account → API Tokens is a different credential and will be refused here.';
    }

    /**
     * Postmark's own words, made into something a merchant can act on.
     *
     * The API answers a phrase rather than a sentence — "No Account or Server
     * API tokens were supplied" — and a message ending in a full stop with the
     * next step after it reads as an answer rather than as a fragment of a log.
     */
    private static function sentence(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'Postmark refused it and said nothing about why. Check the Grav log for more details.';
        }

        $message = ucfirst($message);
        if (!str_ends_with($message, '.')) {
            $message .= '.';
        }

        if (stripos($message, 'token') !== false || stripos($message, 'unauthorized') !== false) {
            $message .= ' Check that the API Token field holds the server token from the server this store sends through, rather than an account token.';
        }

        return $message;
    }
}
