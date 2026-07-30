<?php

declare(strict_types=1);

namespace DeltaSharing\Exception;

/**
 * Raised on HTTP 404 responses, for example an unknown share, schema or table.
 */
class NotFoundException extends HttpException
{
}
