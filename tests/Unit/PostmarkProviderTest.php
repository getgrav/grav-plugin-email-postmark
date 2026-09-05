<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailPostmark\Tests\Unit;

use Grav\Plugin\Email\Providers\DomainFacts;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\EmailPostmark\Api\PostmarkApi;
use Grav\Plugin\EmailPostmark\Provider\PostmarkProvider;
use Grav\Plugin\EmailPostmark\Provider\PostmarkReports;
use Grav\Plugin\EmailPostmark\Provider\PostmarkSetup;
use Grav\Plugin\EmailPostmark\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * What Postmark says about itself when a settings screen asks.
 *
 * Everything here but the domain lookup is arithmetic on config, and that is
 * the point being held: these are called every time a settings screen is drawn,
 * and a network round trip in one of them is a screen that hangs when somebody
 * else's API is slow.
 */
final class PostmarkProviderTest extends TestCase
{
    public function testItIsTheContractsProvider(): void
    {
        self::assertInstanceOf(Provider::class, new PostmarkProvider());
    }

    public function testItAnswersForThePostmarkEngine(): void
    {
        $provider = new PostmarkProvider();

        self::assertSame(['postmark'], $provider->engines());
        self::assertSame('postmark', $provider->key());
        self::assertSame('Postmark', $provider->label());
    }

    /**
     * The registry takes it and finds it again, by engine and by key.
     *
     * This is the whole of what `onEmailProviders` does, minus Grav, and it is
     * worth pinning here: a provider whose `engines()` and `key()` disagreed
     * with what a store looks it up by would register perfectly and then never
     * be found.
     */
    public function testTheRegistryTakesItAndFindsItAgain(): void
    {
        $registry = new ProviderRegistry();
        $registry->add(new PostmarkProvider());

        self::assertInstanceOf(PostmarkProvider::class, $registry->forEngine('postmark'));
        self::assertInstanceOf(PostmarkProvider::class, $registry->byKey('postmark'));
    }

    /**
     * Headers get out and never come back.
     *
     * Both halves matter and both are invisible when they go wrong. Symfony's
     * Postmark bridge puts every header it does not recognise into the API
     * request's `Headers` array, so `List-Unsubscribe` survives on either
     * transport — but no Postmark webhook carries a headers array at all, on
     * any record type, so nothing set on the way out comes back.
     */
    public function testHeadersReachTheWireAndNeverComeBack(): void
    {
        $capabilities = (new PostmarkProvider())->capabilities();

        self::assertTrue($capabilities->customHeaders);
        self::assertTrue($capabilities->unsubscribeHeaders);
        self::assertFalse($capabilities->echoesHeaders);
    }

    /**
     * The note beside that answer says what to do about it, in plain words and
     * without a jargon term in it.
     */
    public function testTheEchoNoteNamesTheMetadataHeaderAndTheTransportCatch(): void
    {
        $note = (new PostmarkProvider())->capabilities()->echoNote;

        self::assertStringContainsString('X-PM-Metadata-Grav-Send-Id', $note);
        self::assertStringContainsString('SMTP', $note, 'the API transport does not turn that header into metadata');
        self::assertStringEndsWith('.', $note);
    }

    public function testItReportsDeliveries(): void
    {
        self::assertInstanceOf(PostmarkReports::class, (new PostmarkProvider())->reports());
    }

    /** No token, no button — a button that always fails is worse than none. */
    public function testThereIsNoSetupButtonWithoutAServerToken(): void
    {
        self::assertNull((new PostmarkProvider([]))->setup());
        self::assertNull((new PostmarkProvider(['api_token' => '   ']))->setup());
    }

    public function testWithATokenThereIsASetupButton(): void
    {
        self::assertInstanceOf(PostmarkSetup::class, (new PostmarkProvider(['api_token' => 'a-server-token']))->setup());
    }

    /**
     * What Postmark needs a sending domain's DNS to say.
     *
     * There is no DKIM zone because Postmark publishes the key as a TXT record
     * rather than having a selector CNAME into a zone of theirs, which is
     * exactly why the lookup below is worth having.
     */
    public function testTheDnsFactsAreTheOnesPostmarkDocuments(): void
    {
        $facts = (new PostmarkProvider())->domain();

        self::assertInstanceOf(DomainFacts::class, $facts);
        self::assertSame('spf.mtasv.net', $facts->spfInclude);
        self::assertNull($facts->dkimZone);
        self::assertSame('pm.mtasv.net', $facts->returnPathZone);
    }

    /** With no account token there is nothing to ask, and asking answers nothing. */
    public function testWithNoAccountTokenThereIsNoLookup(): void
    {
        $facts = (new PostmarkProvider())->domain();

        self::assertNull($facts->lookup);
        self::assertSame([], $facts->ask('example.com'));
    }

    /**
     * With an account token, the selector and the return path come back out of
     * Postmark's own records.
     *
     * The list endpoint carries the name and the id and nothing about DNS, so
     * the id is found first and the domain read second.
     */
    public function testWithAnAccountTokenItReadsTheSelectorAndReturnPath(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['TotalCount' => 2, 'Domains' => [
                ['ID' => 11, 'Name' => 'other.example'],
                ['ID' => 22, 'Name' => 'Example.COM'],
            ]]],
            ['status' => 200, 'body' => [
                'ID' => 22,
                'Name' => 'example.com',
                'DKIMHost' => '20260901234500pm._domainkey.example.com',
                'DKIMPendingHost' => '',
                'ReturnPathDomain' => 'pm-bounces.example.com',
            ]],
        ]);

        $facts = (new PostmarkProvider(['account_token' => 'an-account-token'], $http))->domain();

        self::assertSame([
            'selectors' => ['20260901234500pm'],
            'return_paths' => ['pm-bounces.example.com'],
        ], $facts->ask('example.com'));

        self::assertSame(
            'an-account-token',
            $http->call(0)['headers'][PostmarkApi::ACCOUNT_TOKEN_HEADER],
            'the domains API takes the account token, not the server token'
        );
    }

    /** A pending key is a selector too — it is the one a merchant is being asked to publish. */
    public function testAPendingSelectorIsReportedBesideTheLiveOne(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['Domains' => [['ID' => 22, 'Name' => 'example.com']]]],
            ['status' => 200, 'body' => [
                'DKIMHost' => 'oldpm._domainkey.example.com',
                'DKIMPendingHost' => 'newpm._domainkey.example.com',
            ]],
        ]);

        $facts = (new PostmarkProvider(['account_token' => 'an-account-token'], $http))->domain();

        self::assertSame(['selectors' => ['oldpm', 'newpm']], $facts->ask('example.com'));
    }

    /** A domain the account has never heard of is nothing, not a guess and not an error. */
    public function testADomainTheAccountDoesNotHaveAnswersNothing(): void
    {
        $http = new FakeHttp([['status' => 200, 'body' => ['Domains' => [['ID' => 11, 'Name' => 'other.example']]]]]);

        $facts = (new PostmarkProvider(['account_token' => 'an-account-token'], $http))->domain();

        self::assertSame([], $facts->ask('example.com'));
    }

    /**
     * A refused or unreachable API is an unanswered question rather than a
     * broken settings screen.
     */
    public function testARefusedLookupAnswersNothingRatherThanThrowing(): void
    {
        foreach ([
            ['status' => 401, 'body' => ['ErrorCode' => 10, 'Message' => 'No Account or Server API tokens were supplied']],
            ['status' => 0, 'body' => null, 'error' => 'Could not resolve host: api.postmarkapp.com'],
        ] as $answer) {
            $facts = (new PostmarkProvider(['account_token' => 'an-account-token'], new FakeHttp([$answer])))->domain();

            self::assertSame([], $facts->ask('example.com'));
        }
    }

    /** An empty domain never leaves the building. */
    public function testAnEmptyDomainAsksNothing(): void
    {
        $http = new FakeHttp([]);

        self::assertSame([], (new PostmarkProvider(['account_token' => 'a-token'], $http))->domain()->ask('   '));
        self::assertSame([], $http->calls);
    }

    /**
     * The instructions name the screens and the boxes.
     *
     * "Configure a webhook" is not instructions, and every one of these
     * dashboards calls it something different.
     */
    public function testTheInstructionsNameTheActualScreens(): void
    {
        $instructions = (new PostmarkProvider())->instructions();

        foreach (['Webhooks', 'Add webhook', 'Basic auth'] as $named) {
            self::assertStringContainsString($named, $instructions);
        }
    }
}
