<?php

declare(strict_types=1);

namespace DeltaSharing;

use DeltaSharing\Exception\DeltaSharingException;

/**
 * HTTP settings for the REST calls and data file downloads. Every entry point
 * that builds its own client accepts an instance:
 *
 * $client = DeltaSharingClient::fromProfileFile('config.share', new ClientOptions(timeout: 300));
 *
 * Timeouts are in seconds, and 0 waits indefinitely.
 */
final class ClientOptions
{
    public const DEFAULT_TIMEOUT = 120.0;

    public const DEFAULT_CONNECT_TIMEOUT = 30.0;

    public const DEFAULT_DOWNLOAD_TIMEOUT = 300.0;

    /**
     * @param float $timeout Longest wait for a REST call, including the time
     *     the server needs to prepare its answer. The first query of a shared
     *     view can take more than a minute while the server materialises it.
     * @param float $connectTimeout Longest wait for a connection to be
     *     established, for REST calls and downloads alike.
     * @param float $downloadTimeout Longest time allowed for downloading one
     *     data file.
     * @param int $maxRetries Retries for connection errors, timeouts, HTTP 429
     *     and HTTP 5xx on REST calls. Data file downloads are not retried.
     * @param int $maxTimeoutRetries How many of those retries may follow a
     *     timeout. A request that timed out may still be running on the
     *     server, and a retry starts that work again from scratch.
     */
    public function __construct(
        public readonly float $timeout = self::DEFAULT_TIMEOUT,
        public readonly float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        public readonly float $downloadTimeout = self::DEFAULT_DOWNLOAD_TIMEOUT,
        public readonly int $maxRetries = RetryMiddleware::DEFAULT_MAX_RETRIES,
        public readonly int $maxTimeoutRetries = RetryMiddleware::DEFAULT_MAX_TIMEOUT_RETRIES
    ) {
        $values = [
            'timeout' => $timeout,
            'connectTimeout' => $connectTimeout,
            'downloadTimeout' => $downloadTimeout,
            'maxRetries' => $maxRetries,
            'maxTimeoutRetries' => $maxTimeoutRetries,
        ];

        foreach ($values as $name => $value) {
            if ($value < 0) {
                throw new DeltaSharingException("Client option {$name} must not be negative, got {$value}");
            }
        }
    }
}
