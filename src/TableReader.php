<?php

declare(strict_types=1);

namespace DeltaSharing;

use DeltaSharing\Exception\DeltaSharingException;
use DeltaSharing\Exception\DownloadException;
use DeltaSharing\Exception\TimeoutException;
use DeltaSharing\Exception\UnsupportedTableTypeException;
use DeltaSharing\Model\FileAction;
use DeltaSharing\Model\Metadata;
use DeltaSharing\Model\QueryResult;
use Flow\Parquet\Reader as ParquetReader;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;

/**
 * Downloads the parquet files behind a shared table and decodes them into
 * associative arrays using flow-php/parquet, which is pure PHP and needs no
 * extensions beyond bcmath and zlib for snappy- or gzip-compressed files.
 * Files compressed with zstd, lz4 or brotli additionally need the matching
 * PECL extension; Databricks writers commonly produce zstd.
 *
 * Partition column values are not stored inside the parquet files, so they
 * are merged into every row from the partitionValues of each file action.
 */
final class TableReader
{
    private GuzzleClient $downloader;

    /**
     * @param ?GuzzleClient $downloader Replaces the default download client,
     *     which follows the RestClient's ClientOptions.
     */
    public function __construct(
        private readonly RestClient $rest,
        private readonly Model\Table $table,
        ?GuzzleClient $downloader = null,
        private readonly ?string $tempDir = null
    ) {
        $this->downloader = $downloader ?? self::createDownloader($rest->options());
    }

    public static function forTableUrl(string $tableUrl, ?ClientOptions $options = null): self
    {
        $path = TablePath::parse($tableUrl);
        $rest = new RestClient(Profile::fromFile($path->profilePath), null, $options);

        return new self($rest, $path->table);
    }

    /**
     * Builds the HTTP client used for data file downloads when none is
     * passed to the constructor. A custom handler replaces the transport,
     * for example a MockHandler in tests.
     */
    public static function createDownloader(ClientOptions $options, ?callable $handler = null): GuzzleClient
    {
        // Pre-signed URLs carry their own auth, so no bearer token here.
        return new GuzzleClient([
            'handler' => HandlerStack::create($handler),
            'timeout' => $options->downloadTimeout,
            'connect_timeout' => $options->connectTimeout,
            'verify' => \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath(),
        ]);
    }

    public function metadata(): Model\TableMetadata
    {
        return $this->rest->getTableMetadata($this->table);
    }

    /**
     * @throws UnsupportedTableTypeException when the shared object has no
     *     version, as with views shared from Databricks
     */
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
            $this->download($file, $localPath);

            $partitionValues = [];
            foreach ($file->partitionValues as $column => $value) {
                $partitionValues[$column] = $this->castPartitionValue($value, $partitionTypes[$column] ?? 'string');
            }

            $parquet = (new ParquetReader())->read($localPath);
            try {
                foreach ($parquet->values([], $limit) as $row) {
                    yield $row + $partitionValues;
                }
            } catch (\RuntimeException $e) {
                // flow-php stubs zstd/lz4/brotli functions to throw when the
                // extension is missing; surface that as an actionable error.
                if (str_contains($e->getMessage(), 'extension is not available')) {
                    throw new DeltaSharingException(
                        "Data file {$file->id} uses a compression codec whose PHP extension is not installed"
                        . " ({$e->getMessage()}). Databricks writers commonly produce zstd-compressed parquet;"
                        . ' install the matching PECL extension (for zstd: kjdev/php-ext-zstd) and retry.',
                        0,
                        $e
                    );
                }

                throw $e;
            }
        } finally {
            @unlink($localPath);
        }
    }

    /**
     * Downloads a data file to a local path. Errors name the file and the
     * storage host but never the pre-signed url's query string, because that
     * carries its signature.
     */
    private function download(FileAction $file, string $localPath): void
    {
        $host = parse_url($file->url, PHP_URL_HOST);
        $source = "data file {$file->id}" . (is_string($host) ? " from {$host}" : '');

        try {
            $response = $this->downloader->get($file->url, ['sink' => $localPath, 'http_errors' => false]);
        } catch (\Throwable $e) {
            // Transport errors quote the full url. The text is redacted and
            // the original exception is not chained, since loggers print
            // every exception in the chain.
            $detail = self::redact($file, $e->getMessage());

            if (RetryMiddleware::isTimeout($e)) {
                throw new TimeoutException(
                    "Download of {$source} timed out. For large files or slow connections, raise the"
                    . " downloadTimeout in ClientOptions. {$detail}"
                );
            }

            throw new DeltaSharingException("Download of {$source} failed: {$detail}");
        }

        if ($response->getStatusCode() >= 400) {
            throw self::downloadError($file, $source, ErrorResponse::fromResponse($response));
        }
    }

    /**
     * Describes a refused download with the storage service's own error, and
     * only suggests re-running the query when the url has really expired.
     */
    private static function downloadError(FileAction $file, string $source, ErrorResponse $error): DownloadException
    {
        $hint = match (true) {
            $file->isExpired() => 'The pre-signed URL expired at '
                . gmdate('Y-m-d\TH:i:s\Z', intdiv((int) $file->expirationTimestamp, 1000)),
            $error->indicatesExpiry() => 'The storage service reports that the pre-signed URL has expired',
            default => null,
        };

        $message = "Download of {$source} failed with {$error->summary()}";
        if ($hint !== null) {
            $message = rtrim($message, '.') . ". {$hint}, re-run the query to get fresh URLs.";
        }

        return new DownloadException(
            $error->statusCode,
            $error->errorCode,
            self::redact($file, $message),
            $file->id,
            $hint !== null,
            $error->requestId
        );
    }

    /**
     * Removes the pre-signed url's query string, which carries its signature,
     * from text bound for an exception message. Matches the exact query and,
     * in case a client re-encoded the url, the query of any url in the text.
     */
    private static function redact(FileAction $file, string $text): string
    {
        $query = parse_url($file->url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $text = str_replace($query, '[redacted]', $text);
        }

        return preg_replace('~(https?://[^\s?#]+)\?\S+~i', '$1?[redacted]', $text) ?? $text;
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
