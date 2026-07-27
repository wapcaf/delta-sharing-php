<?php

declare(strict_types=1);

namespace DeltaSharing;

use DeltaSharing\Exception\DeltaSharingException;
use DeltaSharing\Model\FileAction;
use DeltaSharing\Model\Metadata;
use DeltaSharing\Model\QueryResult;
use GuzzleHttp\Client as GuzzleClient;

/**
 * Downloads the parquet files behind a shared table and decodes them into
 * associative arrays. Decoding needs the optional codename/parquet package.
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
        ?GuzzleClient $downloader = null
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
     * Loads the table into an array of associative rows. The limit is
     * enforced client side, on top of the limitHint sent to the server.
     *
     * @param string[] $predicateHints
     * @return array<int, array<string, mixed>>
     */
    public function rows(?int $limit = null, array $predicateHints = [], ?int $version = null): array
    {
        self::assertParquetSupport();

        $result = $this->query($predicateHints, $limit, $version);
        $partitionTypes = $this->partitionColumnTypes($result->metadata);

        $rows = [];
        foreach ($result->files as $file) {
            if ($limit !== null && count($rows) >= $limit) {
                break;
            }

            foreach ($this->readFile($file, $partitionTypes) as $row) {
                $rows[] = $row;
                if ($limit !== null && count($rows) >= $limit) {
                    break;
                }
            }
        }

        return $rows;
    }

    public static function parquetSupportAvailable(): bool
    {
        return class_exists(\codename\parquet\ParquetReader::class);
    }

    private static function assertParquetSupport(): void
    {
        if (!self::parquetSupportAvailable()) {
            throw new DeltaSharingException(
                'Decoding table data requires the codename/parquet package. '
                . 'Install it with "composer require codename/parquet" (needs ext-gmp). '
                . 'Without it you can still use query() to get the pre-signed file URLs.'
            );
        }
    }

    /**
     * @param array<string, string> $partitionTypes
     * @return array<int, array<string, mixed>>
     */
    private function readFile(FileAction $file, array $partitionTypes): array
    {
        $localPath = tempnam(sys_get_temp_dir(), 'delta-sharing-');
        if ($localPath === false) {
            throw new DeltaSharingException('Unable to create a temporary file for the parquet download');
        }

        try {
            $response = $this->downloader->get($file->url, ['sink' => $localPath]);
            if ($response->getStatusCode() >= 400) {
                throw new DeltaSharingException(
                    "Download of data file {$file->id} failed with HTTP {$response->getStatusCode()}"
                );
            }

            $partitionValues = [];
            foreach ($file->partitionValues as $column => $value) {
                $partitionValues[$column] = $this->castPartitionValue($value, $partitionTypes[$column] ?? 'string');
            }

            return $this->decodeParquet($localPath, $partitionValues);
        } finally {
            @unlink($localPath);
        }
    }

    /**
     * @param array<string, mixed> $partitionValues
     * @return array<int, array<string, mixed>>
     */
    private function decodeParquet(string $localPath, array $partitionValues): array
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new DeltaSharingException('Unable to open the downloaded parquet file');
        }

        try {
            $reader = new \codename\parquet\ParquetReader($stream);
            $fields = $reader->schema->getDataFields();

            $rows = [];
            for ($group = 0; $group < $reader->getRowGroupCount(); $group++) {
                $groupReader = $reader->openRowGroupReader($group);

                $columns = [];
                $rowCount = 0;
                foreach ($fields as $field) {
                    $data = $groupReader->readColumn($field)->getData();
                    $columns[$field->name] = $data;
                    $rowCount = max($rowCount, count($data));
                }

                for ($i = 0; $i < $rowCount; $i++) {
                    $row = [];
                    foreach ($columns as $name => $values) {
                        $row[$name] = $values[$i] ?? null;
                    }
                    $rows[] = $row + $partitionValues;
                }
            }

            return $rows;
        } finally {
            fclose($stream);
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
