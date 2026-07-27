<?php

declare(strict_types=1);

namespace DeltaSharing\Model;

final class Metadata
{
    public function __construct(
        public readonly string $id,
        public readonly Format $format,
        public readonly string $schemaString,
        public readonly array $partitionColumns = [],
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly array $configuration = [],
        public readonly ?int $version = null,
        public readonly ?int $size = null,
        public readonly ?int $numFiles = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            Format::fromArray($data['format'] ?? []),
            $data['schemaString'] ?? '',
            $data['partitionColumns'] ?? [],
            $data['name'] ?? null,
            $data['description'] ?? null,
            $data['configuration'] ?? [],
            isset($data['version']) ? (int) $data['version'] : null,
            isset($data['size']) ? (int) $data['size'] : null,
            isset($data['numFiles']) ? (int) $data['numFiles'] : null
        );
    }

    /**
     * The table schema decoded from schemaString. Returns the struct type as
     * an associative array with a "fields" list, or null when the server did
     * not provide a schema.
     */
    public function schema(): ?array
    {
        if ($this->schemaString === '') {
            return null;
        }

        $decoded = json_decode($this->schemaString, true);

        return is_array($decoded) ? $decoded : null;
    }
}
