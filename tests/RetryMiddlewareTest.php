<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\RetryMiddleware;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryMiddlewareTest extends TestCase
{
    private const TIMEOUT_MESSAGE = 'cURL error 28: Operation timed out after 120001 milliseconds'
        . ' with 0 bytes received';

    private MockHandler $mock;

    private static function request(): Request
    {
        return new Request('GET', 'https://sharing.example.com/delta-sharing/shares');
    }

    private static function timeout(): ConnectException
    {
        return new ConnectException(self::TIMEOUT_MESSAGE, self::request(), null, ['errno' => 28]);
    }

    private function clientWithResponses(Response ...$responses): GuzzleClient
    {
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(RetryMiddleware::create(), 'retry');

        return new GuzzleClient(['handler' => $stack, 'http_errors' => false]);
    }

    public function testRetriesRateLimitedRequests(): void
    {
        // Retry-After 0 keeps the test fast.
        $client = $this->clientWithResponses(
            new Response(429, ['Retry-After' => '0']),
            new Response(200, [], 'ok')
        );

        $response = $client->get('https://example.com');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->mock->count());
    }

    public function testRetriesServerErrors(): void
    {
        $client = $this->clientWithResponses(
            new Response(502, ['Retry-After' => '0']),
            new Response(503, ['Retry-After' => '0']),
            new Response(200, [], 'ok')
        );

        $response = $client->get('https://example.com');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $client = $this->clientWithResponses(
            new Response(400),
            new Response(200)
        );

        $response = $client->get('https://example.com');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, $this->mock->count(), 'The second response must not have been consumed');
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $responses = array_fill(0, RetryMiddleware::DEFAULT_MAX_RETRIES + 1, new Response(500, ['Retry-After' => '0']));
        $client = $this->clientWithResponses(...$responses);

        $response = $client->get('https://example.com');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(0, $this->mock->count(), 'Every queued response should have been consumed');
    }

    public function testConnectionErrorsUseTheFullRetryBudget(): void
    {
        $decide = RetryMiddleware::decider(maxRetries: 4, maxTimeoutRetries: 0);
        $refused = new ConnectException('cURL error 7: Failed to connect', self::request(), null, ['errno' => 7]);

        foreach ([0, 1, 2, 3] as $retries) {
            $this->assertTrue($decide($retries, self::request(), null, $refused), "Retry {$retries} should happen");
        }
        $this->assertFalse($decide(4, self::request(), null, $refused));
    }

    public function testTimeoutIsRetriedOnceByDefault(): void
    {
        $decide = RetryMiddleware::decider();

        $this->assertTrue($decide(0, self::request(), null, self::timeout()), 'The first timeout is retried');
        $this->assertFalse($decide(1, self::request(), null, self::timeout()), 'A second timeout is final');
    }

    public function testTimeoutRetriesCanBeDisabled(): void
    {
        $decide = RetryMiddleware::decider(maxTimeoutRetries: 0);

        $this->assertFalse($decide(0, self::request(), null, self::timeout()));
    }

    public function testTimeoutAfterAnotherRetryUsesUpTheTimeoutBudget(): void
    {
        $decide = RetryMiddleware::decider();

        $this->assertTrue($decide(0, self::request(), new Response(503)));
        $this->assertFalse(
            $decide(1, self::request(), null, self::timeout()),
            'Timeouts are only retried within the first maxTimeoutRetries retries'
        );
    }

    public function testTimeoutBudgetNeverExceedsMaxRetries(): void
    {
        $decide = RetryMiddleware::decider(maxRetries: 1, maxTimeoutRetries: 3);

        $this->assertTrue($decide(0, self::request(), null, self::timeout()));
        $this->assertFalse($decide(1, self::request(), null, self::timeout()));
    }

    /**
     * @return array<string, array{0: \Throwable, 1: bool}>
     */
    public static function failures(): array
    {
        return [
            'cURL timeout' => [self::timeout(), true],
            'stream handler timeout' => [new ConnectException('Connection timed out', self::request()), true],
            'cURL connection refused' => [
                new ConnectException('cURL error 7: Failed to connect', self::request(), null, ['errno' => 7]),
                false,
            ],
            'request error' => [new RequestException('Operation timed out', self::request()), false],
            'other error' => [new \RuntimeException('timed out'), false],
        ];
    }

    #[DataProvider('failures')]
    public function testRecognisesTimeouts(\Throwable $failure, bool $isTimeout): void
    {
        $this->assertSame($isTimeout, RetryMiddleware::isTimeout($failure));
    }

    public function testDelayHonoursRetryAfterHeader(): void
    {
        $delay = RetryMiddleware::delay(1, new Response(429, ['Retry-After' => '3']));

        $this->assertSame(3000, $delay);
    }

    public function testDelayBacksOffExponentiallyWithinBounds(): void
    {
        $first = RetryMiddleware::delay(1);
        $third = RetryMiddleware::delay(3);

        $this->assertGreaterThanOrEqual(250, $first);
        $this->assertLessThanOrEqual(500, $first);
        $this->assertGreaterThanOrEqual(1000, $third);
        $this->assertLessThanOrEqual(1250, $third);
    }
}
