<?php

declare(strict_types=1);

namespace DeltaSharing\Model;

/**
 * A single data file returned by the server. The url is a pre-signed link
 * that can be downloaded without extra credentials. Depending on the API the
 * action type is "file" (query), or "add", "cdf", "remove" (change data feed).
 */
final class FileAction
{
    public function __construct(
        public readonly string $type,
        public readonly string $url,
        public readonly string $id,
        public readonly array $partitionValues = [],
        public readonly ?int $size = null,
        public readonly ?string $stats = null,
        public readonly ?int $version = null,
        public readonly ?int $timestamp = null,
        public readonly ?int $expirationTimestamp = null
    ) {
    }

    public static function fromArray(string $type, array $data): self
    {
        return new self(
            $type,
            $data['url'],
            $data['id'] ?? '',
            $data['partitionValues'] ?? [],
            isset($data['size']) ? (int) $data['size'] : null,
            $data['stats'] ?? null,
            isset($data['version']) ? (int) $data['version'] : null,
            isset($data['timestamp']) ? (int) $data['timestamp'] : null,
            isset($data['expirationTimestamp']) ? (int) $data['expirationTimestamp'] : null
        );
    }

    /**
     * Number of records in the file when the server included stats.
     */
    public function numRecords(): ?int
    {
        if ($this->stats === null) {
            return null;
        }

        $stats = json_decode($this->stats, true);

        return is_array($stats) && isset($stats['numRecords']) ? (int) $stats['numRecords'] : null;
    }
}
