<?php

declare(strict_types=1);

namespace DeltaSharing\Model;

final class TableMetadata
{
    public function __construct(
        public readonly Protocol $protocol,
        public readonly Metadata $metadata,
        public readonly ?int $version = null
    ) {
    }
}
