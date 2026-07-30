<?php

declare(strict_types=1);

namespace DeltaSharing\Exception;

/**
 * Raised on HTTP 401 and 403 responses. Usually means the bearer token is
 * wrong, revoked or expired, or the recipient has no access to the resource.
 */
class AuthenticationException extends HttpException
{
}
