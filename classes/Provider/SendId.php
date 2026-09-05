<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailPostmark\Provider;

use Grav\Plugin\Email\Providers\SendHeader;

/**
 * The store's send id, going out on a message and coming back in a webhook.
 *
 * ## Postmark echoes no headers, ever
 *
 * No outbound Postmark webhook carries a headers array, on any record type, and
 * there is no setting that turns one on. That is the whole reason this class
 * exists rather than a one-line header read.
 *
 * What every one of those payloads does carry is `Metadata`, and the way to
 * attach metadata to a message sent over **SMTP** is a header named
 * `X-PM-Metadata-<key>`. Postmark strips its own metadata header before the
 * message reaches the recipient and hands the value back under
 * `Metadata.<key>`. So a store that wants a bounce tied to the exact message it
 * came from sets {@see SendHeader::metadataHeader()} beside the ordinary
 * {@see SendHeader::name()} that the providers which do echo headers read, and
 * this reads it back out of `Metadata`.
 *
 * ## The name is the Email plugin's, not this plugin's
 *
 * `SendHeader::name()` is `X-Grav-Send-Id` unless the site says otherwise, and
 * the metadata key is that name with its leading `X-` taken off and capped at
 * the twenty characters Postmark allows — `Grav-Send-Id`, comfortably inside
 * it. Both ends derive it from the same call, so a site that renames the header
 * renames the metadata key with it and nothing has to be told twice.
 *
 * ## The API transport is not the SMTP transport
 *
 * `X-PM-Metadata-<key>` is Postmark's SMTP convention. A message sent through
 * this plugin's **API** transport has its headers put into the request's
 * `Headers` array, where Postmark treats them as ordinary headers and does not
 * turn them into metadata. A store on the API transport therefore gets no send
 * id back, and {@see PostmarkProvider::capabilities()} says so in `echoNote`
 * rather than leaving a merchant to find out from an empty column.
 *
 * ## Why the value comes back as a string
 *
 * The contract's `Event::$sendId` is `?string` because the Email plugin has no
 * idea what a store's send id looks like and should not pretend to. A provider
 * echoes back whatever the store stamped, trimmed, and the store turns it into
 * whatever it is at its own end.
 */
final class SendId
{
    private function __construct()
    {
    }

    /** The metadata key Postmark hands back, without its `X-PM-Metadata-` prefix. */
    public static function metadataKey(): string
    {
        return SendHeader::metadataKey();
    }

    /** The header that puts it there on a message sent over SMTP. */
    public static function header(): string
    {
        return SendHeader::metadataHeader();
    }

    /**
     * A send id out of a webhook's `Metadata` object, under either spelling.
     *
     * Postmark keeps metadata keys as they were written, but a store that set
     * the header by hand may well have lower-cased it, so both are tried before
     * giving up. The plain header name is tried too, for a store whose metadata
     * was set by something that used it. Anything that is not an array — the
     * key absent, or `Metadata` arriving as `[]` from a message that carried
     * none — answers null.
     *
     * @param mixed $metadata anything; a non-array answers null
     */
    public static function in(mixed $metadata): ?string
    {
        return SendHeader::idIn($metadata, self::metadataKey())
            ?? SendHeader::idIn($metadata);
    }
}
