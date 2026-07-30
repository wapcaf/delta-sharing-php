<?php

declare(strict_types=1);

namespace DeltaSharing\Exception;

/**
 * Raised on HTTP 429 responses once the automatic retries are exhausted.
 */
class RateLimitException extends HttpException
{
    public function __construct(
        int $statusCode,
        ?string $errorCode,
        string $message,
        public readonly ?int $retryAfterSeconds = null
    ) {
        parent::__construct($statusCode, $errorCode, $message);
    }
}
