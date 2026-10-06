<?php

declare(strict_types=1);

namespace DeltaSharing\Exception;

class HttpException extends DeltaSharingException
{
    /**
     * @param ?string $requestId The request id the server sent back, which
     *     providers ask for when investigating a failed request.
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $errorCode,
        string $message,
        public readonly ?string $requestId = null
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * Builds the most specific exception type for a status and error code.
     */
    public static function fromStatus(
        int $statusCode,
        ?string $errorCode,
        string $message,
        ?int $retryAfterSeconds = null,
        ?string $requestId = null
    ): self {
        return match (true) {
            UnsupportedTableTypeException::matches($errorCode, $message)
                => new UnsupportedTableTypeException($statusCode, $errorCode, $message, $requestId),
            $statusCode === 401 || $statusCode === 403
                => new AuthenticationException($statusCode, $errorCode, $message, $requestId),
            $statusCode === 404 => new NotFoundException($statusCode, $errorCode, $message, $requestId),
            $statusCode === 429
                => new RateLimitException($statusCode, $errorCode, $message, $retryAfterSeconds, $requestId),
            $statusCode >= 500 => new ServerException($statusCode, $errorCode, $message, $requestId),
            default => new self($statusCode, $errorCode, $message, $requestId),
        };
    }
}
