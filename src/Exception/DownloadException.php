<?php

declare(strict_types=1);

namespace DeltaSharing\Exception;

/**
 * Raised when cloud storage answers a data file download with an HTTP error.
 * The status, error code, message and request id come from the storage
 * service, not the sharing server. The message never includes the
 * pre-signed url's query string, because that carries its signature.
 */
class DownloadException extends HttpException
{
    /**
     * @param bool $urlExpired Whether the pre-signed url had expired, either
     *     by its expirationTimestamp or according to the storage service.
     *     Re-running the query returns fresh urls.
     */
    public function __construct(
        int $statusCode,
        ?string $errorCode,
        string $message,
        public readonly string $fileId,
        public readonly bool $urlExpired = false,
        ?string $requestId = null
    ) {
        parent::__construct($statusCode, $errorCode, $message, $requestId);
    }
}
