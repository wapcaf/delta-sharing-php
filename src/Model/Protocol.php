<?php

declare(strict_types=1);

namespace DeltaSharing\Model;

final class Protocol
{
    public function __construct(
        public readonly int $minReaderVersion
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self((int) ($data['minReaderVersion'] ?? 1));
    }
}
