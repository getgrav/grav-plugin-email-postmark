<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailPostmark\Tests\Support;

use Grav\Plugin\EmailPostmark\Http\Http;

/**
 * {@see Http} with the answers written down in advance.
 *
 * Every call is recorded — method, URL, body and headers — so a test can say
 * both what came back and what went out, which is the half that matters for a
 * webhook: registering the wrong triggers, or leaving the basic auth off, is a
 * webhook that looks perfectly healthy in a dashboard and reports nothing a
 * store can use.
 *
 * An answer that runs out means the test asked for a call it did not plan for,
 * and that is a failure rather than a silent zero.
 */
final class FakeHttp implements Http
{
    /** @var list<array{status: int, body: array<string, mixed>|null, error: string}> */
    private array $answers;

    /** @var list<array{method: string, url: string, body: array<string, mixed>|null, headers: array<string, string>}> */
    public array $calls = [];

    /** @param list<array{status: int, body?: array<string, mixed>|null, error?: string}> $answers */
    public function __construct(array $answers = [])
    {
        $this->answers = array_map(static fn (array $a): array => [
            'status' => (int)$a['status'],
            'body' => $a['body'] ?? null,
            'error' => (string)($a['error'] ?? ''),
        ], $answers);
    }

    /** A client that answers one success, for the ordinary create. */
    public static function ok(array $body = [], int $status = 200): self
    {
        return new self([['status' => $status, 'body' => $body]]);
    }

    public function json(string $method, string $url, ?array $body = null, array $headers = []): array
    {
        $this->calls[] = [
            'method' => strtoupper($method),
            'url' => $url,
            'body' => $body,
            'headers' => $headers,
        ];

        $answer = array_shift($this->answers);

        if ($answer === null) {
            throw new \RuntimeException(sprintf(
                'The test planned no answer for %s %s, so something is asking for a call it did not mean to make.',
                strtoupper($method),
                $url
            ));
        }

        return $answer;
    }

    /** @return array{method: string, url: string, body: array<string, mixed>|null, headers: array<string, string>} */
    public function call(int $index): array
    {
        if (!isset($this->calls[$index])) {
            throw new \RuntimeException(sprintf('There was no call number %d; %d were made.', $index, \count($this->calls)));
        }

        return $this->calls[$index];
    }
}
