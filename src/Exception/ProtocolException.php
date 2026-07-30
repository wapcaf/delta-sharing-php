<?php

declare(strict_types=1);

namespace DeltaSharing\Exception;

/**
 * Raised when a server response does not follow the Delta Sharing protocol,
 * for example malformed NDJSON lines, a missing protocol or metaData action,
 * or a reader version this client does not support.
 */
class ProtocolException extends DeltaSharingException
{
}
