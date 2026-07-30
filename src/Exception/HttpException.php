<?php

declare(strict_types=1);

namespace DeltaSharing\Exception;

class HttpException extends DeltaSharingException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $errorCode,
        string $message
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * Builds the most specific exception type for a status code.
     */
    public static function fromStatus(
        int $statusCode,
        ?string $errorCode,
        string $message,
        ?int $retryAfterSeconds = null
    ): self {
        return match (true) {
            $statusCode === 401 || $statusCode === 403 => new AuthenticationException($statusCode, $errorCode, $message),
            $statusCode === 404 => new NotFoundException($statusCode, $errorCode, $message),
            $statusCode === 429 => new RateLimitException($statusCode, $errorCode, $message, $retryAfterSeconds),
            $statusCode >= 500 => new ServerException($statusCode, $errorCode, $message),
            default => new self($statusCode, $errorCode, $message),
        };
    }
}
