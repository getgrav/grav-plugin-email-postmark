<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailPostmark\Provider;

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
 * came from sets `X-PM-Metadata-KahunaCart-Send` beside the plain
 * `X-KahunaCart-Send` that the five providers which do echo headers read, and
 * this reads it back out of `Metadata`.
 *
 * Postmark's metadata keys are capped at twenty characters, which
 * `KahunaCart-Send` at fifteen fits with room to spare.
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
    /**
     * The header the five providers that echo headers read.
     *
     * Postmark is not one of them. It is here because it is half of the pair
     * and because {@see PostmarkProvider::instructions()} names both.
     */
    public const PLAIN_HEADER = 'X-KahunaCart-Send';

    /** The metadata key Postmark hands back, without its `X-PM-Metadata-` prefix. */
    public const METADATA_KEY = 'KahunaCart-Send';

    /** The header that puts it there on a message sent over SMTP. */
    public const HEADER = 'X-PM-Metadata-' . self::METADATA_KEY;

    private function __construct()
    {
    }

    /**
     * A send id out of a webhook's `Metadata` object, under either spelling.
     *
     * Postmark keeps metadata keys as they were written, but a store that set
     * the header by hand may well have lower-cased it, so both are tried before
     * giving up. Anything that is not an array — the key absent, or `Metadata`
     * arriving as `[]` from a message that carried none — answers null.
     *
     * @param mixed $metadata anything; a non-array answers null
     */
    public static function in(mixed $metadata): ?string
    {
        if (!\is_array($metadata)) {
            return null;
        }

        foreach ([self::METADATA_KEY, strtolower(self::METADATA_KEY), self::PLAIN_HEADER, strtolower(self::PLAIN_HEADER)] as $key) {
            if (!\array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];

            if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
                continue;
            }

            $value = trim((string)$value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
