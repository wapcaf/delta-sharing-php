<?php

declare(strict_types=1);

namespace DeltaSharing\Model;

final class Share
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $id = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['id'] ?? null);
    }
}
