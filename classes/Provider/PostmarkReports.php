<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailPostmark\Provider;

use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\Payload;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\Email\Providers\WebhookRequest;

/**
 * Postmark's webhooks, read.
 *
 * Moved out of the KahunaCart newsletter add-on, where it was one of six
 * parsers a store had to carry to record a bounce. The reading is unchanged;
 * only the namespace, the event vocabulary and the type of the send id are new.
 *
 * Documentation: `postmarkapp.com/developer/webhooks/webhooks-overview` and
 * the five per-type pages under it, plus `developer/api/bounce-api` for the
 * type table. Read 2026-09-04.
 *
 * ## Five payloads that are not quite the same as each other
 *
 * `RecordType` names which: `Bounce`, `SpamComplaint`, `Delivery`, `Open`,
 * `Click`. Two things vary across them and both are the sort of thing that
 * silently reads as null:
 *
 * - **The recipient key.** `Email` on a bounce and a spam complaint,
 *   `Recipient` on a delivery, an open and a click.
 * - **The timestamp key.** `BouncedAt`, `DeliveredAt`, `ReceivedAt`, one each.
 *
 * Both are read as either, which costs two `??` and saves a bug that would only
 * turn up on the one record type nobody tested.
 *
 * ## Hard and soft
 *
 * There is no boolean. `TypeCode` is the answer and Postmark's own sample
 * handler is `TypeCode === 1`. This treats `1` (HardBounce), `100000`
 * (BadEmailAddress) and `100009` (DMARCPolicy) as permanent, and everything
 * else that arrives as a bounce as temporary — `2` (Transient), `4096`
 * (SoftBounce), `256` (DnsError), `512` (SpamNotification, which is an ISP
 * blocking the message rather than a person complaining) and the rest.
 *
 * `Inactive` is a better fact than the type alone, and it is used: Postmark
 * sets it when *it* has deactivated the address, which is Postmark saying it
 * will not deliver there again. An inactive address that cannot be reactivated
 * is treated as a hard bounce whatever its code says, because the store's own
 * suppression list should say what the provider's already does.
 *
 * ## Correlation
 *
 * Postmark's `MessageID` is Postmark's own UUID. It is not the store's
 * `Message-ID` header, it is never that header, and no outbound Postmark
 * webhook carries a headers array at all. `Metadata` is the only way back to a
 * send, and {@see SendId} is the whole of that decision.
 *
 * ## No signature, so basic auth or the path secret
 *
 * Postmark does not sign webhooks and says so. What it offers is HTTP basic
 * auth on the webhook — written into the URL as `https://user:pass@example.com/…`
 * or set through the Webhooks API — which arrives here as an `Authorization`
 * header. It is checked when the store has configured a user and password and
 * skipped when it has not, because the secret in the URL's own path is already
 * long and random and is the same protection.
 *
 * One operational note that decides how a caller should answer: Postmark treats
 * any 4xx other than 408 and 429 as permanent and **drops the event**. So a
 * payload this cannot read still deserves a 200, and the log line is where a
 * merchant finds out about it.
 */
final class PostmarkReports implements DeliveryReports
{
    /** @var array<string, string> their record types to the contract's */
    public const TYPES = [
        'bounce' => Event::BOUNCED,
        'spamcomplaint' => Event::COMPLAINED,
        'delivery' => Event::DELIVERED,
        'open' => Event::OPENED,
        'click' => Event::CLICKED,
    ];

    /**
     * The bounce codes that mean the address is finished.
     *
     * `1` HardBounce, `100000` invalid address, `100009` refused by the
     * recipient domain's DMARC policy. Everything else that arrives as a bounce
     * is temporary until a store's own counting says otherwise.
     *
     * @var list<int>
     */
    public const PERMANENT_CODES = [1, 100000, 100009];

    public function events(): array
    {
        return [Event::DELIVERED, Event::BOUNCED, Event::COMPLAINED, Event::OPENED, Event::CLICKED];
    }

    public function verificationKeys(): array
    {
        return ['basic_user', 'basic_password'];
    }

    public function verify(WebhookRequest $request, array $config): Verdict
    {
        $user = trim((string)($config['basic_user'] ?? ''));
        $password = (string)($config['basic_password'] ?? '');

        if ($user === '') {
            // No basic auth configured. The caller's own URL secret already
            // matched and Postmark has nothing to sign with, so there is
            // nothing else to check. This is the ordinary case, and `unsigned`
            // rather than `verified` is the honest word for it.
            return Verdict::unsigned();
        }

        $expected = 'Basic ' . base64_encode($user . ':' . $password);
        $given = trim($request->header('authorization'));

        return \strlen($given) === \strlen($expected) && hash_equals($expected, $given)
            ? Verdict::verified()
            : Verdict::refused('the basic-auth credentials did not match');
    }

    public function parse(WebhookRequest $request): Payload
    {
        $body = $request->json();
        if ($body === null) {
            return Payload::unreadable('the body was not a JSON object');
        }

        $record = strtolower(trim((string)($body['RecordType'] ?? '')));
        $type = self::TYPES[$record] ?? null;

        if ($type === null) {
            return Payload::nothing(sprintf('Postmark reported "%s", which this store does not act on', $record));
        }

        $hard = $type === Event::BOUNCED ? self::isPermanent($body) : null;

        return Payload::of([Event::of(
            $type,
            $hard,
            (string)($body['Email'] ?? $body['Recipient'] ?? ''),
            // Postmark never echoes the store's Message-ID. Left null rather
            // than filled with their UUID, which would join against nothing and
            // would look like a correlation that had been tried and failed.
            null,
            (string)($body['MessageID'] ?? ''),
            Moment::parse($body['BouncedAt'] ?? $body['DeliveredAt'] ?? $body['ReceivedAt'] ?? null) ?? 0,
            self::reason($body, $type),
            SendId::in($body['Metadata'] ?? null),
        )]);
    }

    public function sendHeader(): string
    {
        return SendId::HEADER;
    }

    // ------------------------------------------------------------- internals

    /** @param array<string, mixed> $body */
    private static function isPermanent(array $body): bool
    {
        $code = (int)($body['TypeCode'] ?? 0);

        if (\in_array($code, self::PERMANENT_CODES, true)) {
            return true;
        }

        // Postmark has deactivated the address and will not try it again.
        // Whatever the code says, the store's list should agree with the
        // provider's — the alternative is a campaign that keeps queueing an
        // address the transport will never accept.
        return (bool)($body['Inactive'] ?? false) && !(bool)($body['CanActivate'] ?? true);
    }

    /**
     * The provider's own words about why.
     *
     * `Details` is the receiving server's answer where there was one and
     * `Description` is Postmark's own sentence about the type. Both, when both
     * are there, because "unknown user" and "The server was unable to deliver
     * your message" answer different halves of the merchant's question.
     *
     * @param array<string, mixed> $body
     */
    private static function reason(array $body, string $type): ?string
    {
        $parts = array_filter([
            trim((string)($body['Name'] ?? '')),
            trim((string)($body['Details'] ?? '')),
        ], static fn (string $part): bool => $part !== '');

        if ($parts !== []) {
            return implode(': ', $parts);
        }

        $description = trim((string)($body['Description'] ?? ''));
        if ($description !== '') {
            return $description;
        }

        return $type === Event::COMPLAINED ? 'marked as spam' : null;
    }
}
