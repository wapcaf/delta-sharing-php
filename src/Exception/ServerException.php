<?php

declare(strict_types=1);

namespace DeltaSharing\Exception;

/**
 * Raised on HTTP 5xx responses once the automatic retries are exhausted.
 */
class ServerException extends HttpException
{
}
