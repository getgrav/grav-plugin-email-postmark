<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailPostmark\Provider;

use Grav\Plugin\Email\Providers\Capabilities;
use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\DomainFacts;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\WebhookSetup;
use Grav\Plugin\EmailPostmark\Api\PostmarkApi;
use Grav\Plugin\EmailPostmark\Http\CurlHttp;
use Grav\Plugin\EmailPostmark\Http\Http;

/**
 * Everything Postmark knows about itself, answered by Postmark's own plugin.
 *
 * Registered on `onEmailProviders` with this plugin's config handed in. Nothing
 * on the site carries a list of provider names any more: an add-on that wants
 * to record a bounce asks `Email::providerFor('postmark')` and gets this.
 *
 * ## What moved here, and from where
 *
 * The webhook reader is the KahunaCart newsletter add-on's `PostmarkParser`,
 * moved whole — see {@see PostmarkReports}. The DNS row is that add-on's
 * `Deliverability\Transports` entry for Postmark. The header answers are what
 * its `Admin\Transport` and `Providers\SendHeader` had worked out about this
 * transport. The one thing that is new here is {@see PostmarkSetup}, because
 * Postmark's Webhooks API takes the same server token the plugin already sends
 * with and there was never a reason for a merchant to do it by hand.
 *
 * ## Everything cheap, except the two things that are not
 *
 * Every method here but `setup()`'s call and `domain()`'s lookup is arithmetic
 * on config. Both of the exceptions are behind something — a button and a
 * cache — because these are called every time a settings screen is drawn, and a
 * network round trip in one of them is a settings screen that hangs when
 * somebody else's API is slow.
 */
final class PostmarkProvider implements Provider
{
    /** The engine key this plugin registers on `onEmailEngines`. */
    public const ENGINE = 'postmark';

    /**
     * @param array<string, mixed> $config this plugin's own config block,
     *        `plugins.email-postmark`
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?Http $http = null,
    ) {
    }

    public function engines(): array
    {
        return [self::ENGINE];
    }

    public function key(): string
    {
        return self::ENGINE;
    }

    public function label(): string
    {
        return 'Postmark';
    }

    /**
     * What this transport does to a message on the way out.
     *
     * **Headers reach the wire, on both transports.** Over SMTP the headers are
     * the first half of the message. Over the API, Symfony's Postmark bridge
     * puts every header it does not recognise into the request's `Headers`
     * array and Postmark sends them, so `List-Unsubscribe` and
     * `List-Unsubscribe-Post` survive either way. That is worth stating rather
     * than assuming: an API transport dropping the unsubscribe pair is how a
     * bulk sender ends up in Gmail's spam folder a year later with nothing on
     * any screen saying why.
     *
     * **Headers never come back.** No outbound Postmark webhook carries a
     * headers array, on any record type, and there is no setting that turns one
     * on. `Metadata` is the way back, and the `echoNote` is where a merchant is
     * told what to do about it in plain words.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            customHeaders: true,
            unsubscribeHeaders: true,
            echoesHeaders: false,
            echoNote: 'Postmark returns no headers in its webhooks, so a send id has to travel as metadata instead. '
                . 'On the SMTP transport a header named ' . SendId::HEADER . ' becomes metadata and comes back under '
                . SendId::METADATA_KEY . '. On the API transport that header is sent as an ordinary header and does not '
                . 'become metadata, so switch this plugin to SMTP if you want bounces tied to the exact message they came from.',
        );
    }

    public function reports(): ?DeliveryReports
    {
        return new PostmarkReports();
    }

    /**
     * The setup button, whenever there is a server token to press it with.
     *
     * Null with no token rather than a button that always fails: a card that
     * offers a button which cannot work is worse than one that says what to
     * paste first.
     */
    public function setup(): ?WebhookSetup
    {
        $token = $this->text('api_token');

        if ($token === '') {
            return null;
        }

        return new PostmarkSetup(
            new PostmarkApi($this->http()),
            $token,
            $this->text('message_stream') ?: PostmarkApi::DEFAULT_STREAM,
            $this->text('basic_user'),
            (string)($this->config['basic_password'] ?? ''),
        );
    }

    /**
     * What Postmark needs a sending domain's DNS to say.
     *
     * `spf.mtasv.net` is the SPF include. There is no DKIM zone, because
     * Postmark publishes the key itself as a TXT record rather than having a
     * selector CNAME into a zone of theirs — which is why the selector cannot be
     * guessed and why the lookup below exists. `pm.mtasv.net` is what a custom
     * return path CNAMEs into, which is the other way to align SPF.
     *
     * The lookup needs an **account** token, which is a different credential
     * from the server token this plugin sends with. Without one it answers
     * nothing, which is a complete answer: the DNS conventions above are still
     * enough to tell a real record from a name that happens to resolve.
     */
    public function domain(): DomainFacts
    {
        $accountToken = $this->text('account_token');

        return new DomainFacts(
            spfInclude: 'spf.mtasv.net',
            dkimZone: null,
            returnPathZone: 'pm.mtasv.net',
            lookup: $accountToken === ''
                ? null
                : fn (string $domain): array => (new PostmarkApi($this->http()))->domainFacts($accountToken, $domain),
        );
    }

    public function instructions(): string
    {
        return 'In Postmark, open the server this store sends through, go to its Webhooks tab and press Add webhook. '
            . 'Paste the address above into the Webhook URL box, tick Delivery, Bounce, Spam complaint, Open and Click, '
            . 'and save. If you set a username and password under Basic auth on that screen, put the same pair into this '
            . "plugin's own settings so the store can check them. Postmark does not sign its webhooks, so those two "
            . 'fields and the secret in the address are the whole of the protection.';
    }

    // ------------------------------------------------------------- internals

    private function http(): Http
    {
        return $this->http ?? new CurlHttp();
    }

    private function text(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return \is_string($value) || \is_int($value) ? trim((string)$value) : '';
    }
}
