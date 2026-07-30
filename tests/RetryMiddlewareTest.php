<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\RetryMiddleware;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class RetryMiddlewareTest extends TestCase
{
    private MockHandler $mock;

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
