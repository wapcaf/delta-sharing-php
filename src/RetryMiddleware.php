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
 *
 * Timeouts count as connection errors but have a smaller budget, because
 * the server may still be working on a request that timed out (for example
 * materialising a shared view) and a retry starts that work again.
 */
final class RetryMiddleware
{
    public const DEFAULT_MAX_RETRIES = 4;

    public const DEFAULT_MAX_TIMEOUT_RETRIES = 1;

    private const BASE_DELAY_MS = 250;

    private const MAX_DELAY_MS = 15000;

    /**
     * cURL's CURLE_OPERATION_TIMEDOUT, used for connect and transfer timeouts.
     * Spelled out because the constant only exists when ext-curl is loaded.
     */
    private const CURL_TIMEOUT_ERRNO = 28;

    /**
     * Returns a middleware callable ready to push onto a Guzzle handler stack.
     */
    public static function create(
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $maxTimeoutRetries = self::DEFAULT_MAX_TIMEOUT_RETRIES
    ): callable {
        return Middleware::retry(
            self::decider($maxRetries, $maxTimeoutRetries),
            self::delay(...)
        );
    }

    /**
     * The retry decision used by create(), for building a retry middleware
     * with a different delay. A timed out attempt is only retried while
     * fewer than $maxTimeoutRetries retries have happened, which bounds the
     * total wait at roughly ($maxTimeoutRetries + 1) times the timeout.
     */
    public static function decider(
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $maxTimeoutRetries = self::DEFAULT_MAX_TIMEOUT_RETRIES
    ): callable {
        return static function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?\Throwable $exception = null
        ) use ($maxRetries, $maxTimeoutRetries): bool {
            if ($retries >= $maxRetries) {
                return false;
            }

            if ($exception instanceof ConnectException) {
                return !self::isTimeout($exception) || $retries < $maxTimeoutRetries;
            }

            if ($response === null) {
                return false;
            }

            $status = $response->getStatusCode();

            return $status === 429 || $status >= 500;
        };
    }

    /**
     * Whether a request failed because a connect or transfer timeout ran out.
     * The cURL handler reports this as error 28; the stream handler, used
     * when ext-curl is missing, only says so in the message.
     */
    public static function isTimeout(\Throwable $exception): bool
    {
        if (!$exception instanceof ConnectException) {
            return false;
        }

        $errno = $exception->getHandlerContext()['errno'] ?? null;

        return $errno === self::CURL_TIMEOUT_ERRNO || stripos($exception->getMessage(), 'timed out') !== false;
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
