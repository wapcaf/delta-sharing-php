<?php

declare(strict_types=1);

namespace DeltaSharing\Exception;

/**
 * Raised when a request or data file download does not finish within its
 * timeout, once any retries allowed for timeouts are used up. Some servers
 * need well over a minute to answer the first query of a shared view, so
 * raise the limits in ClientOptions if this happens regularly.
 */
class TimeoutException extends DeltaSharingException
{
}
