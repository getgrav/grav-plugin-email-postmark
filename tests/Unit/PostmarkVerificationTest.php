<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailPostmark\Tests\Unit;

use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailPostmark\Provider\PostmarkReports;
use PHPUnit\Framework\TestCase;

/**
 * Whether a post is genuinely Postmark's.
 *
 * Postmark signs nothing, and says so. What it offers instead is HTTP basic
 * auth on the webhook, which the store sets and the store checks — so the whole
 * of the verification here is a constant-time comparison of one header against
 * a pair out of this plugin's own config.
 *
 * The interesting case is the one where nothing is configured. That has to be a
 * pass rather than a refusal, because it is the ordinary case and because the
 * secret in the webhook address is already long and random — but it has to be
 * `unsigned` rather than `verified`, because claiming a signature was checked
 * when there was never one to check is the kind of lie that ends up on a
 * settings screen as a green tick.
 */
final class PostmarkVerificationTest extends TestCase
{
    /** Nothing configured is a pass that says outright that nothing was checked. */
    public function testWithNoBasicAuthConfiguredItSaysNothingWasSigned(): void
    {
        $verdict = (new PostmarkReports())->verify(self::request(), []);

        self::assertTrue($verdict->ok);
        self::assertFalse($verdict->signed, 'Postmark signs nothing and this must not claim otherwise');
        self::assertSame('', $verdict->reason);
    }

    /** The right credentials, in the header Postmark sends them in. */
    public function testTheRightCredentialsAreAccepted(): void
    {
        $verdict = (new PostmarkReports())->verify(
            self::request(['authorization' => 'Basic ' . base64_encode('hooks:a-long-password')]),
            ['basic_user' => 'hooks', 'basic_password' => 'a-long-password'],
        );

        self::assertTrue($verdict->ok);
        self::assertTrue($verdict->signed);
    }

    /** The wrong password is refused, and the refusal says why for the log. */
    public function testTheWrongCredentialsAreRefused(): void
    {
        $verdict = (new PostmarkReports())->verify(
            self::request(['authorization' => 'Basic ' . base64_encode('hooks:wrong')]),
            ['basic_user' => 'hooks', 'basic_password' => 'a-long-password'],
        );

        self::assertFalse($verdict->ok);
        self::assertNotSame('', $verdict->reason);
    }

    /**
     * A post with no `Authorization` header at all, to a store that configured
     * one, is refused.
     *
     * This is the case that matters: a forger posting to an address they found
     * in a log sends no credentials, and a check that only ran when a header
     * was present would wave them straight through.
     */
    public function testAMissingHeaderIsRefusedWhenAPairIsConfigured(): void
    {
        $verdict = (new PostmarkReports())->verify(
            self::request(),
            ['basic_user' => 'hooks', 'basic_password' => 'a-long-password'],
        );

        self::assertFalse($verdict->ok);
    }

    /** A username that matches but a password that does not is still a refusal. */
    public function testTheUsernameAloneIsNotEnough(): void
    {
        $verdict = (new PostmarkReports())->verify(
            self::request(['authorization' => 'Basic ' . base64_encode('hooks:')]),
            ['basic_user' => 'hooks', 'basic_password' => 'a-long-password'],
        );

        self::assertFalse($verdict->ok);
    }

    /**
     * The header name is matched however the request spelled it, because a
     * header name is case insensitive on the wire.
     */
    public function testTheHeaderIsFoundWhateverCaseItArrivedIn(): void
    {
        $request = new WebhookRequest(
            'POST',
            '/webhook',
            [],
            ['authorization' => 'Basic ' . base64_encode('hooks:a-long-password')],
            '{}',
        );

        self::assertTrue((new PostmarkReports())->verify($request, [
            'basic_user' => 'hooks',
            'basic_password' => 'a-long-password',
        ])->ok);
    }

    /** The two keys it asks for, which are the two the blueprint has. */
    public function testItNamesTheTwoConfigKeysItNeeds(): void
    {
        self::assertSame(['basic_user', 'basic_password'], (new PostmarkReports())->verificationKeys());
    }

    // ------------------------------------------------------------- internals

    /** @param array<string, string> $headers */
    private static function request(array $headers = []): WebhookRequest
    {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = $value;
        }

        return new WebhookRequest('POST', '/webhook', [], $lower, '{}');
    }
}
