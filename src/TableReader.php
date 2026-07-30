<?php

declare(strict_types=1);

namespace DeltaSharing;

use DeltaSharing\Exception\DeltaSharingException;
use DeltaSharing\Model\FileAction;
use DeltaSharing\Model\Metadata;
use DeltaSharing\Model\QueryResult;
use Flow\Parquet\Reader as ParquetReader;
use GuzzleHttp\Client as GuzzleClient;

/**
 * Downloads the parquet files behind a shared table and decodes them into
 * associative arrays using flow-php/parquet, which is pure PHP and needs no
 * extensions beyond bcmath and zlib.
 *
 * Partition column values are not stored inside the parquet files, so they
 * are merged into every row from the partitionValues of each file action.
 */
final class TableReader
{
    private GuzzleClient $downloader;

    public function __construct(
        private readonly RestClient $rest,
        private readonly Model\Table $table,
        ?GuzzleClient $downloader = null,
        private readonly ?string $tempDir = null
    ) {
        // Pre-signed URLs carry their own auth, so no bearer token here.
        $this->downloader = $downloader ?? new GuzzleClient([
            'timeout' => 300,
            'verify' => \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath(),
        ]);
    }

    public static function forTableUrl(string $tableUrl): self
    {
        $path = TablePath::parse($tableUrl);
        $rest = new RestClient(Profile::fromFile($path->profilePath));

        return new self($rest, $path->table);
    }

    public function metadata(): Model\TableMetadata
    {
        return $this->rest->getTableMetadata($this->table);
    }

    public function version(): int
    {
        return $this->rest->getTableVersion($this->table);
    }

    /**
     * @param string[] $predicateHints
     */
    public function query(array $predicateHints = [], ?int $limitHint = null, ?int $version = null): QueryResult
    {
        return $this->rest->queryTable($this->table, $predicateHints, null, $limitHint, $version);
    }

    /**
     * Streams the table row by row. Files are downloaded one at a time and
     * removed as soon as they have been read, so memory use stays flat no
     * matter how large the table is. The limit is enforced client side, on
     * top of the limitHint sent to the server.
     *
     * @param string[] $predicateHints
     * @return \Generator<int, array<string, mixed>>
     */
    public function readRows(?int $limit = null, array $predicateHints = [], ?int $version = null): \Generator
    {
        $result = $this->query($predicateHints, $limit, $version);
        $partitionTypes = $this->partitionColumnTypes($result->metadata);

        $emitted = 0;
        foreach ($result->files as $file) {
            if ($limit !== null && $emitted >= $limit) {
                return;
            }

            $remaining = $limit === null ? null : $limit - $emitted;
            foreach ($this->readFile($file, $partitionTypes, $remaining) as $row) {
                yield $row;
                $emitted++;
            }
        }
    }

    /**
     * Loads the table into an array of associative rows. Prefer readRows()
     * for large tables, this method holds everything in memory.
     *
     * @param string[] $predicateHints
     * @return array<int, array<string, mixed>>
     */
    public function rows(?int $limit = null, array $predicateHints = [], ?int $version = null): array
    {
        return iterator_to_array($this->readRows($limit, $predicateHints, $version), false);
    }

    /**
     * @param array<string, string> $partitionTypes
     * @return \Generator<int, array<string, mixed>>
     */
    private function readFile(FileAction $file, array $partitionTypes, ?int $limit): \Generator
    {
        $localPath = tempnam($this->tempDir ?? sys_get_temp_dir(), 'delta-sharing-');
        if ($localPath === false) {
            throw new DeltaSharingException('Unable to create a temporary file for the parquet download');
        }

        try {
            $response = $this->downloader->get($file->url, ['sink' => $localPath, 'http_errors' => false]);
            if ($response->getStatusCode() >= 400) {
                throw new DeltaSharingException(
                    "Download of data file {$file->id} failed with HTTP {$response->getStatusCode()}. "
                    . 'Pre-signed URLs expire quickly, re-run the query to get fresh ones.'
                );
            }

            $partitionValues = [];
            foreach ($file->partitionValues as $column => $value) {
                $partitionValues[$column] = $this->castPartitionValue($value, $partitionTypes[$column] ?? 'string');
            }

            $parquet = (new ParquetReader())->read($localPath);
            foreach ($parquet->values([], $limit) as $row) {
                yield $row + $partitionValues;
            }
        } finally {
            @unlink($localPath);
        }
    }

    /**
     * Maps partition column names to their type in the table schema so the
     * string values from partitionValues can be cast to sensible PHP types.
     *
     * @return array<string, string>
     */
    private function partitionColumnTypes(Metadata $metadata): array
    {
        $schema = $metadata->schema();
        if ($schema === null) {
            return [];
        }

        $types = [];
        foreach ($schema['fields'] ?? [] as $field) {
            if (is_array($field) && isset($field['name']) && is_string($field['type'] ?? null)) {
                $types[$field['name']] = $field['type'];
            }
        }

        return array_intersect_key($types, array_flip($metadata->partitionColumns));
    }

    private function castPartitionValue(?string $value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'byte', 'short', 'integer', 'long' => (int) $value,
            'float', 'double' => (float) $value,
            'boolean' => $value === 'true',
            default => $value,
        };
    }
}
