<?php

declare(strict_types=1);

namespace DeltaSharing;

use DeltaSharing\Exception\UnsupportedTableTypeException;
use DeltaSharing\Model\FileAction;
use DeltaSharing\Model\QueryResult;
use DeltaSharing\Model\Schema;
use DeltaSharing\Model\Share;
use DeltaSharing\Model\Table;
use DeltaSharing\Model\TableMetadata;

/**
 * High level Delta Sharing client. Wraps RestClient with automatic
 * pagination and accepts either model objects or plain names.
 *
 * $client = DeltaSharingClient::fromProfileFile('config.share');
 * foreach ($client->listShares() as $share) { ... }
 */
final class DeltaSharingClient
{
    /**
     * The package version, sent in the User-Agent header. Bump it together
     * with the CHANGELOG for every release.
     */
    public const VERSION = '0.3.0';

    public function __construct(private readonly RestClient $rest)
    {
    }

    public static function fromProfileFile(string $path, ?ClientOptions $options = null): self
    {
        return self::fromProfile(Profile::fromFile($path), $options);
    }

    public static function fromProfile(Profile $profile, ?ClientOptions $options = null): self
    {
        return new self(new RestClient($profile, null, $options));
    }

    public function rest(): RestClient
    {
        return $this->rest;
    }

    /**
     * @return Share[]
     */
    public function listShares(): array
    {
        return $this->drainPages(fn (?string $token) => $this->rest->listShares(null, $token));
    }

    /**
     * @return Schema[]
     */
    public function listSchemas(Share|string $share): array
    {
        $shareName = $share instanceof Share ? $share->name : $share;

        return $this->drainPages(fn (?string $token) => $this->rest->listSchemas($shareName, null, $token));
    }

    /**
     * @return Table[]
     */
    public function listTables(Schema $schema): array
    {
        return $this->drainPages(
            fn (?string $token) => $this->rest->listTables($schema->share, $schema->name, null, $token)
        );
    }

    /**
     * Lists every table in a share across all of its schemas.
     *
     * @return Table[]
     */
    public function listAllTables(Share|string $share): array
    {
        $shareName = $share instanceof Share ? $share->name : $share;

        return $this->drainPages(fn (?string $token) => $this->rest->listAllTables($shareName, null, $token));
    }

    /**
     * @throws UnsupportedTableTypeException when the shared object has no
     *     version, as with views shared from Databricks
     */
    public function getTableVersion(Table|string $table, ?string $startingTimestamp = null): int
    {
        return $this->rest->getTableVersion($this->resolveTable($table), $startingTimestamp);
    }

    public function getTableMetadata(Table|string $table): TableMetadata
    {
        return $this->rest->getTableMetadata($this->resolveTable($table));
    }

    /**
     * Lists the pre-signed data file URLs for a table.
     *
     * @param string[] $predicateHints
     * @return FileAction[]
     */
    public function listFilesInTable(
        Table|string $table,
        array $predicateHints = [],
        ?int $limitHint = null,
        ?int $version = null
    ): array {
        return $this->queryTable($table, $predicateHints, $limitHint, $version)->files;
    }

    /**
     * @param string[] $predicateHints
     */
    public function queryTable(
        Table|string $table,
        array $predicateHints = [],
        ?int $limitHint = null,
        ?int $version = null,
        ?string $timestamp = null
    ): QueryResult {
        return $this->rest->queryTable(
            $this->resolveTable($table),
            $predicateHints,
            null,
            $limitHint,
            $version,
            $timestamp
        );
    }

    /**
     * @return array{protocol: Model\Protocol, metadata: Model\Metadata, actions: FileAction[], version: ?int}
     */
    public function getTableChanges(
        Table|string $table,
        ?int $startingVersion = null,
        ?int $endingVersion = null,
        ?string $startingTimestamp = null,
        ?string $endingTimestamp = null
    ): array {
        return $this->rest->getTableChanges(
            $this->resolveTable($table),
            $startingVersion,
            $endingVersion,
            $startingTimestamp,
            $endingTimestamp
        );
    }

    /**
     * Streams the rows of a table, downloading and decoding its parquet
     * files one at a time. See TableReader::readRows() for the details.
     *
     * @param string[] $predicateHints
     * @return \Generator<int, array<string, mixed>>
     */
    public function readTable(
        Table|string $table,
        ?int $limit = null,
        array $predicateHints = [],
        ?int $version = null
    ): \Generator {
        $reader = new TableReader($this->rest, $this->resolveTable($table));

        return $reader->readRows($limit, $predicateHints, $version);
    }

    private function resolveTable(Table|string $table): Table
    {
        return $table instanceof Table ? $table : Table::fromFullyQualifiedName($table);
    }

    /**
     * @param callable(?string): array{items: array, nextPageToken: ?string} $page
     */
    private function drainPages(callable $page): array
    {
        $items = [];
        $token = null;

        do {
            $result = $page($token);
            $items = array_merge($items, $result['items']);
            $token = $result['nextPageToken'];
        } while ($token !== null && $token !== '');

        return $items;
    }
}
