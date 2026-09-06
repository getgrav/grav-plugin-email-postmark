<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailPostmark\Api;

use Grav\Plugin\EmailPostmark\Http\Http;

/**
 * The three things this plugin asks Postmark's REST API for.
 *
 * Documentation: `postmarkapp.com/developer/api/webhooks-api` and
 * `postmarkapp.com/developer/api/domains-api`, read 2026-09-05.
 *
 * ## Two tokens, and confusing them is the commonest failure
 *
 * Postmark has two kinds of credential and they are not interchangeable:
 *
 * - **A server token** — the one already in this plugin's `api_token` field,
 *   the one messages are sent with. It is per server and it is what the
 *   Webhooks API takes, in `X-Postmark-Server-Token`.
 * - **An account token** — one per account, from Account → API Tokens. It is
 *   what the Domains API takes, in `X-Postmark-Account-Token`, and a server
 *   token used there answers 401 however right it is.
 *
 * So creating the webhook needs nothing a store has not already pasted, and
 * reading a domain's DNS back needs a second token this plugin asks for
 * separately and does without when it has not got one.
 *
 * ## What this never does
 *
 * It never deletes a webhook. A merchant who wants one gone should remove it in
 * Postmark's own dashboard, where they can see what else is pointed at it —
 * this plugin removing a webhook it did not create is exactly the sort of thing
 * that takes a store's other integration down at four on a Friday.
 *
 * It stores no credential. Every call is handed the token it needs.
 */
final class PostmarkApi
{
    public const BASE = 'https://api.postmarkapp.com';

    /** The webhooks API's token header. A server token, not an account token. */
    public const SERVER_TOKEN_HEADER = 'X-Postmark-Server-Token';

    /** The domains API's token header. An account token, not a server token. */
    public const ACCOUNT_TOKEN_HEADER = 'X-Postmark-Account-Token';

    /** The stream a webhook is created on when a store has not named another. */
    public const DEFAULT_STREAM = 'outbound';

    /**
     * Postmark's trigger names for the five things a store acts on.
     *
     * `SubscriptionChange` is deliberately not here. It is Postmark's own
     * suppression list changing, which is a different fact from a bounce, and a
     * store that has not asked to hear about it should not have this plugin
     * turn it on.
     *
     * @var array<string, string> the contract's event type => Postmark's trigger
     */
    public const TRIGGERS = [
        'delivered' => 'Delivery',
        'bounced' => 'Bounce',
        'complained' => 'SpamComplaint',
        'opened' => 'Open',
        'clicked' => 'Click',
    ];

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * Create the store's webhook, or update the one already pointed at that
     * address, or say plainly why neither could be done.
     *
     * Pressing the button twice must not leave a store with two webhooks
     * posting the same events at the same address, so the existing ones are
     * listed first and one already pointed at this URL is updated in place. The
     * same listing catches this store's webhook registered before the secret
     * changed, which is updated to the new address rather than joined by a
     * second one.
     *
     * @param list<string> $triggers Postmark's own trigger names
     * @param array{user?: string, password?: string} $auth basic auth to set on
     *        the webhook, so the reader's own check has something to check
     * @return array{ok: bool, id: string|null, message: string}
     */
    public function createWebhook(
        string $serverToken,
        string $url,
        array $triggers,
        string $stream = self::DEFAULT_STREAM,
        array $auth = [],
    ): array {
        $serverToken = trim($serverToken);
        $url = trim($url);
        $stream = trim($stream) === '' ? self::DEFAULT_STREAM : trim($stream);

        if ($serverToken === '') {
            return self::no('no Postmark server token is configured');
        }

        if ($url === '') {
            return self::no('there is no webhook address to register yet');
        }

        $existing = $this->existingWebhook($serverToken, $url, $stream);
        if ($existing['error'] !== null) {
            return self::no($existing['error']);
        }

        $body = [
            'Url' => $url,
            'MessageStream' => $stream,
            'Triggers' => self::triggerBlock($triggers),
        ];

        $user = trim((string)($auth['user'] ?? ''));
        if ($user !== '') {
            // Postmark signs nothing, so this is the only thing that makes a
            // forged post distinguishable from a real one beyond the secret in
            // the address. Setting it here from the same values the reader
            // checks is what stops the two drifting apart.
            $body['HttpAuth'] = ['Username' => $user, 'Password' => (string)($auth['password'] ?? '')];
        }

        $id = $existing['id'];

        $answer = $id === null
            ? $this->http->json('POST', self::BASE . '/webhooks', $body, [self::SERVER_TOKEN_HEADER => $serverToken])
            : $this->http->json('PUT', self::BASE . '/webhooks/' . rawurlencode($id), $body, [self::SERVER_TOKEN_HEADER => $serverToken]);

        $refusal = self::refusal($answer);
        if ($refusal !== null) {
            return self::no($refusal);
        }

        $newId = self::stringOrNull($answer['body']['ID'] ?? null) ?? $id;

        return [
            'ok' => true,
            'id' => $newId,
            'message' => match (true) {
                $id === null && $newId === null => sprintf('The webhook was created in Postmark on the %s stream.', $stream),
                $id === null => sprintf('The webhook was created in Postmark as number %s, on the %s stream.', (string)$newId, $stream),
                $existing['stale'] => sprintf(
                    'Postmark had this store\'s webhook registered with an older secret. Number %s now points at this address.',
                    (string)$id
                ),
                default => sprintf('The webhook already at that address was updated in Postmark, as number %s.', (string)$id),
            },
        ];
    }

    /**
     * A sending domain's selectors and return paths, as Postmark's account has
     * them.
     *
     * Answers `[]` for everything that is not an answer — no account token, a
     * domain the account does not have, an API that did not reply. Never
     * throws: this runs behind a cached settings read and a slow API is an
     * unanswered question rather than a broken screen.
     *
     * @return array{selectors?: list<string>, return_paths?: list<string>}
     */
    public function domainFacts(string $accountToken, string $domain): array
    {
        $accountToken = trim($accountToken);
        $domain = strtolower(trim($domain));

        if ($accountToken === '' || $domain === '') {
            return [];
        }

        $headers = [self::ACCOUNT_TOKEN_HEADER => $accountToken];

        // The list endpoint carries the name and the id and nothing about DNS,
        // so the id has to be found first and the domain read second. Both are
        // cheap and the answer is cached by the caller.
        $list = $this->http->json('GET', self::BASE . '/domains?count=500&offset=0', null, $headers);
        if (self::refusal($list) !== null) {
            return [];
        }

        $id = null;
        foreach ((array)($list['body']['Domains'] ?? []) as $row) {
            if (\is_array($row) && strtolower(trim((string)($row['Name'] ?? ''))) === $domain) {
                $id = self::stringOrNull($row['ID'] ?? null);
                break;
            }
        }

        if ($id === null) {
            return [];
        }

        $one = $this->http->json('GET', self::BASE . '/domains/' . rawurlencode($id), null, $headers);
        if (self::refusal($one) !== null) {
            return [];
        }

        $body = $one['body'] ?? [];

        $selectors = [];
        foreach (['DKIMHost', 'DKIMPendingHost'] as $key) {
            $selector = self::selectorIn((string)($body[$key] ?? ''));
            if ($selector !== null && !\in_array($selector, $selectors, true)) {
                $selectors[] = $selector;
            }
        }

        $returnPaths = [];
        $returnPath = strtolower(trim((string)($body['ReturnPathDomain'] ?? '')));
        if ($returnPath !== '') {
            $returnPaths[] = $returnPath;
        }

        $facts = [];
        if ($selectors !== []) {
            $facts['selectors'] = $selectors;
        }
        if ($returnPaths !== []) {
            $facts['return_paths'] = $returnPaths;
        }

        return $facts;
    }

    // ------------------------------------------------------------- internals

    /**
     * The webhook on this stream worth writing to: the one already pointed at
     * this address, or failing that this store's own against an older secret.
     *
     * A webhook address is the store's endpoint followed by a secret, so a
     * webhook under the same endpoint but not at the whole address is this
     * store's own registered before the secret changed. It is answered as
     * `stale`, and updating it is better than creating a second one: the old
     * address answers 404, and Postmark would post every event to both.
     *
     * The list is read once and looked through twice, exactly first and then by
     * endpoint, so Postmark is asked one question per press.
     *
     * @return array{id: string|null, stale: bool, error: string|null}
     */
    private function existingWebhook(string $serverToken, string $url, string $stream): array
    {
        $answer = $this->http->json(
            'GET',
            self::BASE . '/webhooks?MessageStream=' . rawurlencode($stream),
            null,
            [self::SERVER_TOKEN_HEADER => $serverToken],
        );

        $refusal = self::refusal($answer);
        if ($refusal !== null) {
            return ['id' => null, 'stale' => false, 'error' => $refusal];
        }

        $endpoint = self::endpointOf($url);
        $stale = null;

        foreach ((array)($answer['body']['Webhooks'] ?? []) as $hook) {
            if (!\is_array($hook)) {
                continue;
            }

            $theirs = trim((string)($hook['Url'] ?? ''));
            if ($theirs === $url) {
                return ['id' => self::stringOrNull($hook['ID'] ?? null), 'stale' => false, 'error' => null];
            }

            if ($stale === null && $endpoint !== '' && str_starts_with($theirs, $endpoint)) {
                $stale = self::stringOrNull($hook['ID'] ?? null);
            }
        }

        return ['id' => $stale, 'stale' => $stale !== null, 'error' => null];
    }

    /**
     * The address without its secret: everything up to and including the last
     * slash. Two addresses that share it belong to the same store.
     */
    private static function endpointOf(string $url): string
    {
        $cut = strrpos($url, '/');

        return $cut === false || $cut < \strlen('https://x/') ? '' : substr($url, 0, $cut + 1);
    }

    /**
     * Postmark's trigger block for the events asked for, with the rest off.
     *
     * Every trigger is named rather than only the enabled ones, because an
     * update leaves out what it does not mention and a store that unticked an
     * event would otherwise keep receiving it.
     *
     * @param list<string> $triggers
     * @return array<string, array<string, bool>>
     */
    private static function triggerBlock(array $triggers): array
    {
        $wanted = array_map(static fn (string $name): string => strtolower($name), $triggers);

        $block = [
            'Delivery' => ['Enabled' => \in_array('delivery', $wanted, true)],
            'Bounce' => ['Enabled' => \in_array('bounce', $wanted, true), 'IncludeContent' => false],
            'SpamComplaint' => ['Enabled' => \in_array('spamcomplaint', $wanted, true), 'IncludeContent' => false],
            // `PostFirstOpenOnly` false is the honest setting for an add-on
            // that counts opens: true would report the first open of a message
            // and silently drop every one after it.
            'Open' => ['Enabled' => \in_array('open', $wanted, true), 'PostFirstOpenOnly' => false],
            'Click' => ['Enabled' => \in_array('click', $wanted, true)],
            'SubscriptionChange' => ['Enabled' => false],
        ];

        return $block;
    }

    /**
     * Postmark's own words about why it said no, or null when it said yes.
     *
     * Their errors are a JSON object with `ErrorCode` and `Message` on every
     * endpoint, including the 401 for a token used on the wrong API, so the
     * merchant gets the sentence Postmark wrote rather than a status code.
     *
     * @param array{status: int, body: array<string, mixed>|null, error: string} $answer
     */
    private static function refusal(array $answer): ?string
    {
        if ($answer['status'] === 0) {
            return $answer['error'] !== ''
                ? 'Postmark could not be reached: ' . $answer['error']
                : 'Postmark could not be reached';
        }

        if ($answer['status'] >= 200 && $answer['status'] < 300) {
            return null;
        }

        $message = trim((string)($answer['body']['Message'] ?? ''));

        return $message !== ''
            ? 'Postmark refused it: ' . $message
            : sprintf('Postmark answered %d', $answer['status']);
    }

    /** The selector out of a DKIM host, which is everything before `._domainkey`. */
    private static function selectorIn(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        $at = strpos($host, '._domainkey');
        $selector = $at === false ? '' : trim(substr($host, 0, $at));

        return $selector === '' ? null : $selector;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    /** @return array{ok: bool, id: null, message: string} */
    private static function no(string $message): array
    {
        return ['ok' => false, 'id' => null, 'message' => $message];
    }
}
