<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailPostmark\Tests\Unit;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\EmailPostmark\Api\PostmarkApi;
use Grav\Plugin\EmailPostmark\Provider\PostmarkSetup;
use Grav\Plugin\EmailPostmark\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * The one button, against a client that answers without a network.
 *
 * Half of what matters here is what came back and half is what went out: a
 * webhook created with the wrong triggers, on the wrong stream, or without the
 * basic auth the reader is going to check, is a webhook that looks entirely
 * healthy in Postmark's dashboard and reports nothing a store can use.
 */
final class PostmarkSetupTest extends TestCase
{
    private const URL = 'https://shop.example.com/newsletter/webhook/postmark/a-long-secret';

    /** @var list<string> the five a store acts on, in the contract's words */
    private const EVENTS = [Event::DELIVERED, Event::BOUNCED, Event::COMPLAINED, Event::OPENED, Event::CLICKED];

    /** The ordinary case: nothing there yet, so one is created. */
    public function testItCreatesTheWebhookAndSaysWhichOne(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['Webhooks' => []]],
            ['status' => 200, 'body' => ['ID' => 1234567, 'Url' => self::URL]],
        ]);

        $result = self::button($http)->create(self::URL, self::EVENTS, []);

        self::assertTrue($result->ok);
        self::assertSame('1234567', $result->webhookId);
        self::assertStringContainsString('1234567', $result->message);

        $create = $http->call(1);
        self::assertSame('POST', $create['method']);
        self::assertSame(PostmarkApi::BASE . '/webhooks', $create['url']);
        self::assertSame(self::URL, $create['body']['Url']);
        self::assertSame('outbound', $create['body']['MessageStream']);
        self::assertSame('a-server-token', $create['headers'][PostmarkApi::SERVER_TOKEN_HEADER]);
    }

    /**
     * The five a store acts on go on, and `SubscriptionChange` stays off.
     *
     * Every trigger is named rather than only the enabled ones, because an
     * update leaves out what it does not mention and a store that unticked an
     * event would otherwise keep receiving it.
     */
    public function testItTurnsOnTheFiveTriggersAndLeavesTheRestOff(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['Webhooks' => []]],
            ['status' => 200, 'body' => ['ID' => 1]],
        ]);

        self::button($http)->create(self::URL, self::EVENTS, []);

        $triggers = $http->call(1)['body']['Triggers'];

        foreach (['Delivery', 'Bounce', 'SpamComplaint', 'Open', 'Click'] as $on) {
            self::assertTrue($triggers[$on]['Enabled'], $on);
        }

        self::assertFalse($triggers['SubscriptionChange']['Enabled'], 'a store did not ask to hear about suppressions');
        self::assertFalse($triggers['Open']['PostFirstOpenOnly'], 'counting opens means counting all of them');
    }

    /** An event this store does not want turns its trigger off rather than leaving it out. */
    public function testAnUntickedEventTurnsItsTriggerOff(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['Webhooks' => []]],
            ['status' => 200, 'body' => ['ID' => 1]],
        ]);

        self::button($http)->create(self::URL, [Event::DELIVERED, Event::BOUNCED], []);

        $triggers = $http->call(1)['body']['Triggers'];

        self::assertTrue($triggers['Delivery']['Enabled']);
        self::assertTrue($triggers['Bounce']['Enabled']);
        self::assertFalse($triggers['Open']['Enabled']);
        self::assertFalse($triggers['Click']['Enabled']);
    }

    /**
     * The basic auth the reader checks is set on the webhook by the same button
     * that creates it.
     *
     * Setting both ends from one config value is what stops them drifting
     * apart, which is the commonest way a store ends up refusing every post
     * from a webhook it created itself.
     */
    public function testItPutsTheStoresBasicAuthOnTheWebhook(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['Webhooks' => []]],
            ['status' => 200, 'body' => ['ID' => 1]],
        ]);

        self::button($http, 'hooks', 'a-long-password')->create(self::URL, self::EVENTS, []);

        self::assertSame(
            ['Username' => 'hooks', 'Password' => 'a-long-password'],
            $http->call(1)['body']['HttpAuth']
        );
    }

    /** With no pair configured, no `HttpAuth` is sent — an empty one would lock the webhook out. */
    public function testWithNoBasicAuthConfiguredItSendsNone(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['Webhooks' => []]],
            ['status' => 200, 'body' => ['ID' => 1]],
        ]);

        self::button($http)->create(self::URL, self::EVENTS, []);

        self::assertArrayNotHasKey('HttpAuth', $http->call(1)['body']);
    }

    /**
     * Pressing the button twice updates the webhook rather than adding a second
     * one beside it.
     *
     * Postmark will happily hold several webhooks on one stream and post every
     * event to all of them, and a store that pressed twice would then record
     * everything twice.
     */
    public function testASecondPressUpdatesTheWebhookAlreadyAtThatAddress(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['Webhooks' => [
                ['ID' => 42, 'Url' => 'https://elsewhere.example.com/hook'],
                ['ID' => 99, 'Url' => self::URL],
            ]]],
            ['status' => 200, 'body' => ['ID' => 99]],
        ]);

        $result = self::button($http)->create(self::URL, self::EVENTS, []);

        self::assertTrue($result->ok);
        self::assertSame('99', $result->webhookId);
        self::assertStringContainsString('updated', $result->message);

        $update = $http->call(1);
        self::assertSame('PUT', $update['method']);
        self::assertSame(PostmarkApi::BASE . '/webhooks/99', $update['url']);
    }

    /**
     * A webhook registered against an older secret is pointed at the new
     * address rather than left dead beside a new one.
     */
    public function testAWebhookOnAnOlderSecretIsPointedAtTheNewAddress(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['Webhooks' => [
                ['ID' => 42, 'Url' => 'https://elsewhere.example.com/hook'],
                ['ID' => 99, 'Url' => 'https://shop.example.com/newsletter/webhook/postmark/the-old-secret'],
            ]]],
            ['status' => 200, 'body' => ['ID' => 99]],
        ]);

        $result = self::button($http, 'hooks', 'a-long-password')->create(self::URL, self::EVENTS, []);

        self::assertTrue($result->ok);
        self::assertSame('99', $result->webhookId);
        self::assertStringContainsString('older secret', $result->message);
        self::assertCount(2, $http->calls, 'nothing should have been created');

        $update = $http->call(1);
        self::assertSame('PUT', $update['method']);
        self::assertSame(PostmarkApi::BASE . '/webhooks/99', $update['url']);
        self::assertSame(self::URL, $update['body']['Url']);
        self::assertSame('outbound', $update['body']['MessageStream']);
        self::assertSame(['Username' => 'hooks', 'Password' => 'a-long-password'], $update['body']['HttpAuth']);

        foreach (['Delivery', 'Bounce', 'SpamComplaint', 'Open', 'Click'] as $on) {
            self::assertTrue($update['body']['Triggers'][$on]['Enabled'], $on);
        }

        self::assertFalse($update['body']['Triggers']['SubscriptionChange']['Enabled']);
    }

    /** A refused repointing comes back in Postmark's own words. */
    public function testARefusedRepointingIsAPlainSentence(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['Webhooks' => [
                ['ID' => 99, 'Url' => 'https://shop.example.com/newsletter/webhook/postmark/the-old-secret'],
            ]]],
            ['status' => 422, 'body' => ['ErrorCode' => 402, 'Message' => 'The webhook could not be updated']],
        ]);

        $result = self::button($http)->create(self::URL, self::EVENTS, []);

        self::assertFalse($result->ok);
        self::assertStringContainsString('could not be updated', $result->message);
        self::assertStringEndsWith('.', $result->message);
        self::assertNull($result->webhookId);
    }

    /** A store on its own stream gets its webhook on that stream, not on `outbound`. */
    public function testItCreatesTheWebhookOnTheStreamTheStoreSendsOn(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['Webhooks' => []]],
            ['status' => 200, 'body' => ['ID' => 1]],
        ]);

        (new PostmarkSetup(new PostmarkApi($http), 'a-server-token', 'broadcast'))
            ->create(self::URL, self::EVENTS, []);

        self::assertStringContainsString('MessageStream=broadcast', $http->call(0)['url']);
        self::assertSame('broadcast', $http->call(1)['body']['MessageStream']);
    }

    /**
     * A refused token comes back in Postmark's own words, with the one thing a
     * merchant most often has wrong said outright.
     */
    public function testARefusedTokenComesBackInPostmarksOwnWords(): void
    {
        $http = new FakeHttp([[
            'status' => 401,
            'body' => ['ErrorCode' => 10, 'Message' => 'No Account or Server API tokens were supplied in the HTTP headers'],
        ]]);

        $result = self::button($http)->create(self::URL, self::EVENTS, []);

        self::assertFalse($result->ok);
        self::assertStringContainsString('No Account or Server API tokens were supplied', $result->message);
        self::assertStringContainsString('server token', $result->message);
        self::assertStringEndsWith('.', $result->message, 'a merchant gets a sentence, not a fragment of a log');
        self::assertNull($result->webhookId);
    }

    /** A refusal on the create call itself, after the listing went through. */
    public function testARefusalOnTheCreateItselfIsReported(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['Webhooks' => []]],
            ['status' => 422, 'body' => ['ErrorCode' => 402, 'Message' => 'A webhook with that URL already exists']],
        ]);

        $result = self::button($http)->create(self::URL, self::EVENTS, []);

        self::assertFalse($result->ok);
        self::assertStringContainsString('already exists', $result->message);
    }

    /** A network that never answered is a plain sentence rather than a stack trace. */
    public function testANetworkFailureIsAPlainSentence(): void
    {
        $http = new FakeHttp([['status' => 0, 'body' => null, 'error' => 'Could not resolve host: api.postmarkapp.com']]);

        $result = self::button($http)->create(self::URL, self::EVENTS, []);

        self::assertFalse($result->ok);
        self::assertStringContainsString('could not be reached', $result->message);
        self::assertStringContainsString('Could not resolve host', $result->message);
    }

    /** No address to register is refused before anything leaves the building. */
    public function testWithNoAddressNothingIsSent(): void
    {
        $http = new FakeHttp([]);

        $result = self::button($http)->create('', self::EVENTS, []);

        self::assertFalse($result->ok);
        self::assertSame([], $http->calls);
    }

    /** Nothing Postmark can report is refused rather than made into a webhook that never posts. */
    public function testAskingForNothingPostmarkReportsIsRefused(): void
    {
        $http = new FakeHttp([]);

        $result = self::button($http)->create(self::URL, ['dropped', 'nonsense'], []);

        self::assertFalse($result->ok);
        self::assertStringContainsString('nothing to register', $result->message);
        self::assertSame([], $http->calls);
    }

    /**
     * The plugin's own token wins over whatever the caller found.
     *
     * A caller that looked in the wrong place — or in no place — should not be
     * able to send the call out with an empty token while the plugin is holding
     * a good one.
     */
    public function testThePluginsOwnCredentialsWinOverTheCallers(): void
    {
        $http = new FakeHttp([
            ['status' => 200, 'body' => ['Webhooks' => []]],
            ['status' => 200, 'body' => ['ID' => 1]],
        ]);

        self::button($http, 'hooks', 'a-long-password')
            ->create(self::URL, self::EVENTS, ['api_token' => 'someone-elses', 'basic_user' => 'nobody']);

        self::assertSame('a-server-token', $http->call(1)['headers'][PostmarkApi::SERVER_TOKEN_HEADER]);
        self::assertSame('hooks', $http->call(1)['body']['HttpAuth']['Username']);
    }

    /** What the token has to be allowed to do, before the button is pressed. */
    public function testItSaysWhatTheTokenHasToBe(): void
    {
        $sentence = self::button(new FakeHttp([]))->permissionsNeeded();

        self::assertStringContainsString('server token', $sentence);
        self::assertStringContainsString('account token', $sentence);
    }

    // ------------------------------------------------------------- internals

    private static function button(FakeHttp $http, string $user = '', string $password = ''): PostmarkSetup
    {
        return new PostmarkSetup(new PostmarkApi($http), 'a-server-token', PostmarkApi::DEFAULT_STREAM, $user, $password);
    }
}
