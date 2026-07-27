<?php

declare(strict_types=1);

namespace DeltaSharing\Model;

final class QueryResult
{
    /**
     * @param FileAction[] $files
     */
    public function __construct(
        public readonly Protocol $protocol,
        public readonly Metadata $metadata,
        public readonly array $files = [],
        public readonly ?int $version = null
    ) {
    }
}
