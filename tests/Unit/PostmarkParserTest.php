<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailPostmark\Tests\Unit;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\SendHeader;
use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailPostmark\Provider\PostmarkReports;
use PHPUnit\Framework\TestCase;

/**
 * Postmark's five record types, read into the contract's vocabulary.
 *
 * Moved out of the KahunaCart newsletter add-on's `ParserTest`, where one file
 * held six providers. The assertions are the ones that were there; what changed
 * is the namespace, the type names — the contract says `bounced` where the
 * add-on's table says `bounce` — and the send id, which the contract carries as
 * the string the provider handed back rather than as a row id.
 *
 * Every one of these runs against **Postmark's own documented sample payloads**,
 * in `tests/fixtures/webhooks/postmark/`, copied out of their webhook
 * documentation on 2026-09-04. That matters more than it sounds: Postmark has
 * renamed a field before, and a parser written against a payload somebody
 * remembered reads null forever without failing anything. A fixture from the
 * documentation and a test that reads it is what turns a silent stop into a red
 * bar.
 *
 * The one fixture that is not verbatim is `subscription-change.json`, which is
 * assembled from their documented field list for that record type — it is here
 * only to hold the rule that an event this store does not act on is skipped
 * rather than refused.
 */
final class PostmarkParserTest extends TestCase
{
    /**
     * Every documented sample, and the event it has to become.
     *
     * Written out longhand rather than generated, because a table built from
     * the same constants the parser reads would agree with the code however
     * wrong both were.
     *
     * @return iterable<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function samples(): iterable
    {
        yield 'hard bounce' => ['bounce', [
            'type' => Event::BOUNCED,
            'hard' => true,
            'email' => 'margareth@nasa.com',
            // Postmark's MessageID is Postmark's own UUID and joins against
            // nothing here, so it is the provider id and the message id is null.
            'message_id' => null,
            'provider_id' => '883953f4-6105-42a2-a16a-77a8eac79483',
        ]];

        yield 'complaint' => ['spam-complaint', [
            'type' => Event::COMPLAINED,
            'hard' => null,
            'email' => 'margareth@nasa.com',
        ]];

        // `Recipient` rather than `Email` on this one, which is the field name
        // that varies across Postmark's five record types.
        yield 'delivery' => ['delivery', [
            'type' => Event::DELIVERED,
            'email' => 'margareth@nasa.com',
        ]];

        yield 'open' => ['open', ['type' => Event::OPENED]];
        yield 'click' => ['click', ['type' => Event::CLICKED]];
    }

    /** @param array<string, mixed> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('samples')]
    public function testTheDocumentedSampleBecomesTheContractsEvent(string $fixture, array $expected): void
    {
        $payload = (new PostmarkReports())->parse(self::request(self::body($fixture)));

        self::assertCount(1, $payload->events, 'one documented sample is one event');
        $event = $payload->events[0]->toArray();

        foreach ($expected as $field => $value) {
            self::assertSame($value, $event[$field], "{$fixture}: {$field}");
        }
    }

    /**
     * Every sample carries a moment, because a chart with a null on it is a
     * chart with a gap in it.
     *
     * Postmark's timestamps have seven fractional digits, which are .NET ticks
     * rather than anything a standard asks for. A format nobody parsed would
     * read as zero here and be quietly stamped with the receiver's clock, and a
     * whole store's charts would then be wrong in a way nobody can see.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('samples')]
    public function testEverySampleCarriesAMomentThatWasActuallyRead(string $fixture): void
    {
        $event = (new PostmarkReports())->parse(self::request(self::body($fixture)))->events[0];

        self::assertGreaterThan(
            946684800,
            $event->at,
            "{$fixture}: the timestamp was not read, so the receiver's clock would stand in for it"
        );
    }

    /**
     * An event type this store does not act on is a note and no events, not a
     * refusal.
     *
     * Postmark sends `SubscriptionChange` as well as the five, and a store that
     * ticked every box in the dashboard should get a 200 and a quiet log line.
     * A refusal would be worse than useless here: Postmark treats any 4xx other
     * than 408 and 429 as permanent and drops the event outright.
     */
    public function testAnEventTypeThisStoreDoesNotActOnIsSkipped(): void
    {
        $payload = (new PostmarkReports())->parse(self::request(self::body('subscription-change')));

        self::assertTrue($payload->isEmpty());
        self::assertFalse($payload->unreadable, 'it was perfectly readable; it just was not wanted');
        self::assertStringContainsString('does not act on', $payload->note);
    }

    /** A body that is not JSON at all is a note and no events, never an exception. */
    public function testAnUnreadableBodyIsANoteRatherThanAnException(): void
    {
        $payload = (new PostmarkReports())->parse(self::request('this is not json'));

        self::assertTrue($payload->isEmpty());
        self::assertTrue($payload->unreadable);
        self::assertNotSame('', $payload->note);
    }

    /**
     * Postmark's only correlation path is `Metadata`, under the key its
     * `X-PM-Metadata-` header put there.
     */
    public function testTheSendIdIsReadOutOfMetadata(): void
    {
        $body = (string)json_encode([
            'RecordType' => 'Delivery',
            'MessageID' => 'postmarks-own-uuid',
            'Recipient' => 'a@example.com',
            'DeliveredAt' => '2026-09-04T10:00:00.0000000Z',
            'Metadata' => ['Grav-Send-Id' => '41'],
        ]);

        $event = (new PostmarkReports())->parse(self::request($body))->events[0];

        self::assertSame('41', $event->sendId, 'the contract carries it as the string the provider handed back');
        self::assertNull($event->messageId, 'Postmark never echoes ours');
        self::assertSame('postmarks-own-uuid', $event->providerId);
    }

    /**
     * The store's send id is whatever the store stamped, and this hands it back
     * untouched.
     *
     * The contract's `Event::$sendId` is a string on purpose: the Email plugin
     * has no idea what a store's send id looks like and should not pretend to.
     * Turning `41` into a row id is the store's job at the store's end, and a
     * provider that refused anything that was not a number would refuse a store
     * whose ids are ULIDs.
     */
    public function testAnySendIdTheStoreStampedComesBackAsItWas(): void
    {
        foreach (['41', '01JBQ2Z9N8VZ2K', 'campaign-7/send-3'] as $stamped) {
            $body = (string)json_encode([
                'RecordType' => 'Delivery',
                'Recipient' => 'a@example.com',
                'DeliveredAt' => '2026-09-04T10:00:00.0000000Z',
                'Metadata' => ['Grav-Send-Id' => $stamped],
            ]);

            $event = (new PostmarkReports())->parse(self::request($body))->events[0];

            self::assertSame($stamped, $event->sendId);
        }
    }

    /** A message that carried no metadata answers null rather than an empty string. */
    public function testAMessageWithNoMetadataHasNoSendId(): void
    {
        foreach ([null, [], ['PropA' => 'some value'], 'not an object'] as $metadata) {
            $body = (string)json_encode([
                'RecordType' => 'Delivery',
                'Recipient' => 'a@example.com',
                'DeliveredAt' => '2026-09-04T10:00:00.0000000Z',
                'Metadata' => $metadata,
            ]);

            self::assertNull((new PostmarkReports())->parse(self::request($body))->events[0]->sendId);
        }
    }

    /**
     * An address arriving as `Name <addr>` is the same person as `addr`.
     *
     * A suppression list keys on the address, so one spelling has to win, and
     * the contract's `Event::of()` settles it.
     */
    public function testAnAddressWithADisplayNameIsNormalised(): void
    {
        $body = (string)json_encode([
            'RecordType' => 'Delivery',
            'Recipient' => 'Jane Smith <Jane@Example.COM>',
            'DeliveredAt' => '2026-09-04T10:00:00.0000000Z',
        ]);

        $event = (new PostmarkReports())->parse(self::request($body))->events[0];

        self::assertSame('jane@example.com', $event->email);
    }

    /**
     * `TypeCode` is the only thing that says whether a bounce is permanent, and
     * Postmark's own sample handler is `TypeCode === 1`.
     *
     * The three that are permanent, and a spread of the ones that are not:
     * `2` Transient, `4096` SoftBounce, `256` DnsError, and `512`
     * SpamNotification — which is an ISP blocking the message rather than a
     * person complaining, and suppressing on it would take somebody off a list
     * for something their mail provider did.
     */
    public function testHardAndSoftAreToldApartByTheirTypeCode(): void
    {
        foreach ([1 => true, 100000 => true, 100009 => true, 2 => false, 4096 => false, 256 => false, 512 => false] as $code => $hard) {
            $body = (string)json_encode([
                'RecordType' => 'Bounce',
                'TypeCode' => $code,
                'Email' => 'a@example.com',
                'BouncedAt' => '2026-09-04T10:00:00.0000000Z',
            ]);

            $event = (new PostmarkReports())->parse(self::request($body))->events[0];

            self::assertSame(Event::BOUNCED, $event->type);
            self::assertSame($hard, $event->hard, "TypeCode {$code}");
        }
    }

    /**
     * An address Postmark has deactivated and will not reactivate is finished,
     * whatever its code says.
     *
     * The store's own suppression list agreeing with the provider's is the
     * point of having one; the alternative is a campaign that keeps queueing an
     * address the transport will never accept.
     */
    public function testAnAddressPostmarkWillNotTryAgainIsAHardBounce(): void
    {
        $soft = ['RecordType' => 'Bounce', 'TypeCode' => 4096, 'Email' => 'a@example.com', 'BouncedAt' => '2026-09-04T10:00:00.0000000Z'];

        $stillReachable = (new PostmarkReports())->parse(self::request((string)json_encode(
            $soft + ['Inactive' => true, 'CanActivate' => true]
        )))->events[0];
        self::assertFalse($stillReachable->hard, 'Postmark says it can be turned back on');

        $finished = (new PostmarkReports())->parse(self::request((string)json_encode(
            $soft + ['Inactive' => true, 'CanActivate' => false]
        )))->events[0];
        self::assertTrue($finished->hard, 'Postmark says it will not deliver there again');
    }

    /**
     * The reason is Postmark's own words, and both halves of them.
     *
     * "Hard bounce" and "unknown user" answer different halves of the
     * merchant's question, so both are kept when both are there.
     */
    public function testTheReasonCarriesPostmarksOwnWords(): void
    {
        $event = (new PostmarkReports())->parse(self::request(self::body('bounce')))->events[0];
        self::assertSame('Hard bounce: Test bounce details', $event->reason);

        $bare = (string)json_encode([
            'RecordType' => 'SpamComplaint',
            'Email' => 'a@example.com',
            'BouncedAt' => '2026-09-04T10:00:00.0000000Z',
        ]);
        self::assertSame('marked as spam', (new PostmarkReports())->parse(self::request($bare))->events[0]->reason);
    }

    /** The five Postmark can report, and nothing it cannot. */
    public function testItReportsTheFiveEventsPostmarkSends(): void
    {
        $events = (new PostmarkReports())->events();

        self::assertSame(
            [Event::DELIVERED, Event::BOUNCED, Event::COMPLAINED, Event::OPENED, Event::CLICKED],
            $events
        );

        foreach ($events as $event) {
            self::assertContains($event, Event::TYPES, 'every one has to be a type the contract knows');
        }
    }

    /** The header a store stamps a send id into, which for Postmark is a metadata header. */
    public function testTheSendHeaderIsPostmarksMetadataHeader(): void
    {
        self::assertSame(SendHeader::metadataHeader(), (new PostmarkReports())->sendHeader());
        self::assertSame('X-PM-Metadata-Grav-Send-Id', (new PostmarkReports())->sendHeader());
    }

    /**
     * A site that renames the header renames the metadata key with it.
     *
     * Both come out of the same call, so there is no second place to remember.
     */
    public function testRenamingTheHeaderRenamesTheMetadataKeyAndWhatIsReadBack(): void
    {
        SendHeader::override('X-Shop-Send');

        try {
            self::assertSame('X-PM-Metadata-Shop-Send', (new PostmarkReports())->sendHeader());

            $body = (string)json_encode([
                'RecordType' => 'Delivery',
                'Recipient' => 'a@example.com',
                'DeliveredAt' => '2026-09-05T10:00:00Z',
                'Metadata' => ['Shop-Send' => 'abc-123'],
            ]);

            $event = (new PostmarkReports())->parse(self::request($body))->events[0];

            self::assertSame('abc-123', $event->sendId);
        } finally {
            SendHeader::override(null);
        }
    }

    /**
     * Postmark never reports a message it refused to send, so `dropped` is not
     * among what it can report.
     *
     * A send to an address Postmark has suppressed is refused by the send
     * itself, with a 406 and an `InactiveRecipient` error; no message is
     * created and no webhook follows. There is nothing to map, and claiming
     * otherwise would tell a screen this provider can report something it
     * cannot.
     */
    public function testAMessagePostmarkRefusedToSendIsNotReportedAtAll(): void
    {
        self::assertNotContains(Event::DROPPED, (new PostmarkReports())->events());

        // Their own suppression list changing is not something that happened to
        // a message, and the setup button turns it off for that reason.
        $body = (string)json_encode([
            'RecordType' => 'SubscriptionChange',
            'Recipient' => 'a@example.com',
            'SuppressSending' => true,
            'SuppressionReason' => 'ManualSuppression',
        ]);

        $payload = (new PostmarkReports())->parse(self::request($body));

        self::assertTrue($payload->isEmpty());
        self::assertFalse($payload->unreadable);
        self::assertStringContainsString('does not act on', $payload->note);
    }

    // ------------------------------------------------------------- internals

    private static function request(string $body, array $headers = []): WebhookRequest
    {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string)$name)] = (string)$value;
        }

        return new WebhookRequest('POST', '/webhook', [], $lower + ['content-type' => 'application/json'], $body);
    }

    private static function body(string $fixture): string
    {
        $path = \dirname(__DIR__) . "/fixtures/webhooks/postmark/{$fixture}.json";
        self::assertFileExists($path, "there is no documented sample at {$path}");

        return (string)file_get_contents($path);
    }
}
