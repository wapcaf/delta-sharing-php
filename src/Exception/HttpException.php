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
}
