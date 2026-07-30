<?php

declare(strict_types=1);

namespace DeltaSharing;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware that retries transient failures: connection errors,
 * HTTP 429 and HTTP 5xx. Uses exponential backoff with jitter and honours
 * the Retry-After header when the server sends one.
 */
final class RetryMiddleware
{
    public const DEFAULT_MAX_RETRIES = 4;

    private const BASE_DELAY_MS = 250;

    private const MAX_DELAY_MS = 15000;

    /**
     * Returns a middleware callable ready to push onto a Guzzle handler stack.
     */
    public static function create(int $maxRetries = self::DEFAULT_MAX_RETRIES): callable
    {
        return Middleware::retry(
            self::decider($maxRetries),
            self::delay(...)
        );
    }

    private static function decider(int $maxRetries): callable
    {
        return static function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?\Throwable $exception = null
        ) use ($maxRetries): bool {
            if ($retries >= $maxRetries) {
                return false;
            }

            if ($exception instanceof ConnectException) {
                return true;
            }

            if ($response === null) {
                return false;
            }

            $status = $response->getStatusCode();

            return $status === 429 || $status >= 500;
        };
    }

    /**
     * Delay in milliseconds before the given (1-based) retry attempt.
     */
    public static function delay(int $retries, ?ResponseInterface $response = null): int
    {
        if ($response !== null) {
            $retryAfter = $response->getHeaderLine('Retry-After');
            if ($retryAfter !== '' && is_numeric($retryAfter)) {
                return min((int) $retryAfter * 1000, self::MAX_DELAY_MS);
            }
        }

        $backoff = self::BASE_DELAY_MS * (2 ** ($retries - 1));
        $jitter = random_int(0, self::BASE_DELAY_MS);

        return min($backoff + $jitter, self::MAX_DELAY_MS);
    }
}
