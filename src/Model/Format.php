<?php

declare(strict_types=1);

namespace DeltaSharing\Model;

final class Format
{
    public function __construct(
        public readonly string $provider = 'parquet',
        public readonly array $options = []
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self($data['provider'] ?? 'parquet', $data['options'] ?? []);
    }
}
