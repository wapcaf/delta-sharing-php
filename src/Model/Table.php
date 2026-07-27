<?php

declare(strict_types=1);

namespace DeltaSharing\Model;

use DeltaSharing\Exception\DeltaSharingException;

final class Table
{
    public function __construct(
        public readonly string $name,
        public readonly string $schema,
        public readonly string $share,
        public readonly ?string $id = null,
        public readonly ?string $shareId = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['schema'],
            $data['share'],
            $data['id'] ?? null,
            $data['shareId'] ?? null
        );
    }

    /**
     * Builds a table from a fully qualified "share.schema.table" name.
     */
    public static function fromFullyQualifiedName(string $name): self
    {
        $parts = explode('.', $name);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new DeltaSharingException(
                "Invalid table name \"{$name}\", expected the form share.schema.table"
            );
        }

        return new self($parts[2], $parts[1], $parts[0]);
    }

    public function fullyQualifiedName(): string
    {
        return "{$this->share}.{$this->schema}.{$this->name}";
    }
}
