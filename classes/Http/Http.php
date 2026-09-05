<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailPostmark\Http;

/**
 * The three calls this plugin makes to Postmark, behind one seam.
 *
 * Listing webhooks, creating or updating one, and reading a sending domain's
 * DNS facts. All of them run behind a button or a cached settings read, and all
 * of them have to be answerable by a test without a network — a suite that
 * reached out to `api.postmarkapp.com` would be a suite that failed on a train
 * and a suite that created real webhooks in somebody's account.
 *
 * Deliberately one method. There is no redirect policy here, no streaming and
 * no header manipulation, because none of the three needs any of it and every
 * one of them would be another thing to get wrong in the class that talks to
 * the outside.
 */
interface Http
{
    /**
     * Send a JSON request and read a JSON answer.
     *
     * Never throws. Every failure — a refused connection, a certificate that
     * did not check out, a body that was not JSON — is a status of zero and a
     * sentence in `error`, because the callers all treat those the same way.
     *
     * @param string $method 'GET', 'POST' or 'PUT'
     * @param array<string, mixed>|null $body null sends no body at all
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>|null, error: string}
     */
    public function json(string $method, string $url, ?array $body = null, array $headers = []): array;
}
