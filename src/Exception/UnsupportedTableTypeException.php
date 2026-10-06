<?php

declare(strict_types=1);

namespace DeltaSharing\Exception;

/**
 * Raised when the server does not support a request for this kind of shared
 * object. Databricks answers version requests for shared views this way:
 * views have no table version or change data feed, but their metadata and
 * data can still be read.
 */
class UnsupportedTableTypeException extends HttpException
{
    /**
     * The error code Databricks uses, either as the error code itself or at
     * the start of the error message.
     */
    public const ERROR_CODE = 'DS_UNSUPPORTED_TABLE_TYPE';

    /**
     * Whether an error code or message reports an unsupported table type.
     */
    public static function matches(?string $errorCode, string $message): bool
    {
        return $errorCode === self::ERROR_CODE || str_contains($message, self::ERROR_CODE);
    }
}
