<?php

declare(strict_types=1);

namespace DeltaSharing;

use DeltaSharing\Exception\DeltaSharingException;
use DeltaSharing\Exception\HttpException;
use DeltaSharing\Exception\ProtocolException;
use DeltaSharing\Exception\TimeoutException;
use DeltaSharing\Exception\UnsupportedTableTypeException;
use DeltaSharing\Model\FileAction;
use DeltaSharing\Model\Metadata;
use DeltaSharing\Model\Protocol;
use DeltaSharing\Model\QueryResult;
use DeltaSharing\Model\Schema;
use DeltaSharing\Model\Share;
use DeltaSharing\Model\Table;
use DeltaSharing\Model\TableMetadata;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;

/**
 * Low level client for the Delta Sharing REST protocol. Each method maps to
 * one endpoint. Most applications should use DeltaSharingClient instead,
 * which adds pagination handling and friendlier return types.
 *
 * @see https://github.com/delta-io/delta-sharing/blob/main/PROTOCOL.md
 */
final class RestClient
{
    public const USER_AGENT = 'delta-sharing-php/' . DeltaSharingClient::VERSION;

    /**
     * Sent on every request so servers that support multiple response formats
     * always answer with parquet file actions, which is what this client reads.
     */
    public const CAPABILITIES = 'responseformat=parquet';

    private readonly GuzzleClient $http;

    private readonly ClientOptions $options;

    /**
     * @param ?GuzzleClient $http Replaces the default HTTP client. REST calls
     *     then follow that client's own timeouts and retries, while $options
     *     still applies to data file downloads.
     */
    public function __construct(
        private readonly Profile $profile,
        ?GuzzleClient $http = null,
        ?ClientOptions $options = null
    ) {
        $this->options = $options ?? new ClientOptions();
        $this->http = $http ?? self::createHttpClient($profile, $this->options);
    }

    /**
     * Builds the HTTP client used when none is passed to the constructor:
     * the profile endpoint as base uri, the configured timeouts, retries for
     * transient failures and the system CA bundle. A custom handler replaces
     * the transport, for example a MockHandler in tests.
     */
    public static function createHttpClient(
        Profile $profile,
        ClientOptions $options,
        ?callable $handler = null
    ): GuzzleClient {
        $stack = HandlerStack::create($handler);
        $stack->push(RetryMiddleware::create($options->maxRetries, $options->maxTimeoutRetries), 'retry');

        return new GuzzleClient([
            'base_uri' => $profile->endpoint . '/',
            'handler' => $stack,
            'http_errors' => false,
            'timeout' => $options->timeout,
            'connect_timeout' => $options->connectTimeout,
            'verify' => \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath(),
        ]);
    }

    public function profile(): Profile
    {
        return $this->profile;
    }

    public function options(): ClientOptions
    {
        return $this->options;
    }

    /**
     * @return array{items: Share[], nextPageToken: ?string}
     */
    public function listShares(?int $maxResults = null, ?string $pageToken = null): array
    {
        $data = $this->getJson('shares', $this->paginationQuery($maxResults, $pageToken));

        return [
            'items' => array_map(fn (array $s) => Share::fromArray($s), $data['items'] ?? []),
            'nextPageToken' => $data['nextPageToken'] ?? null,
        ];
    }

    public function getShare(string $share): Share
    {
        $data = $this->getJson('shares/' . rawurlencode($share));

        return Share::fromArray($data['share'] ?? $data);
    }

    /**
     * @return array{items: Schema[], nextPageToken: ?string}
     */
    public function listSchemas(string $share, ?int $maxResults = null, ?string $pageToken = null): array
    {
        $path = 'shares/' . rawurlencode($share) . '/schemas';
        $data = $this->getJson($path, $this->paginationQuery($maxResults, $pageToken));

        return [
            'items' => array_map(fn (array $s) => Schema::fromArray($s), $data['items'] ?? []),
            'nextPageToken' => $data['nextPageToken'] ?? null,
        ];
    }

    /**
     * @return array{items: Table[], nextPageToken: ?string}
     */
    public function listTables(string $share, string $schema, ?int $maxResults = null, ?string $pageToken = null): array
    {
        $path = 'shares/' . rawurlencode($share) . '/schemas/' . rawurlencode($schema) . '/tables';
        $data = $this->getJson($path, $this->paginationQuery($maxResults, $pageToken));

        return [
            'items' => array_map(fn (array $t) => Table::fromArray($t), $data['items'] ?? []),
            'nextPageToken' => $data['nextPageToken'] ?? null,
        ];
    }

    /**
     * @return array{items: Table[], nextPageToken: ?string}
     */
    public function listAllTables(string $share, ?int $maxResults = null, ?string $pageToken = null): array
    {
        $path = 'shares/' . rawurlencode($share) . '/all-tables';
        $data = $this->getJson($path, $this->paginationQuery($maxResults, $pageToken));

        return [
            'items' => array_map(fn (array $t) => Table::fromArray($t), $data['items'] ?? []),
            'nextPageToken' => $data['nextPageToken'] ?? null,
        ];
    }

    /**
     * @throws UnsupportedTableTypeException when the shared object has no
     *     version, as with views shared from Databricks
     */
    public function getTableVersion(Table $table, ?string $startingTimestamp = null): int
    {
        $query = $startingTimestamp !== null ? ['startingTimestamp' => $startingTimestamp] : [];

        try {
            $response = $this->request('GET', $this->tablePath($table) . '/version', ['query' => $query]);
        } catch (HttpException $e) {
            if (!in_array($e->statusCode, [404, 405], true)) {
                throw $e;
            }
            // Older servers only support the deprecated HEAD request on the
            // table path itself.
            $response = $this->request('HEAD', $this->tablePath($table), ['query' => $query]);
        }

        $header = $response->getHeaderLine('Delta-Table-Version');
        if ($header === '') {
            throw new DeltaSharingException('Server response did not include a Delta-Table-Version header');
        }

        return (int) $header;
    }

    public function getTableMetadata(Table $table): TableMetadata
    {
        $response = $this->request('GET', $this->tablePath($table) . '/metadata');
        $lines = $this->parseNdjson($response);

        [$protocol, $metadata] = $this->extractProtocolAndMetadata($lines);

        return new TableMetadata($protocol, $metadata, $this->versionHeader($response));
    }

    /**
     * Reads the list of data files for a table. Predicate and limit hints are
     * best effort on the server side, so callers still need to apply their
     * own filtering to the returned rows.
     *
     * @param string[] $predicateHints
     */
    public function queryTable(
        Table $table,
        array $predicateHints = [],
        ?string $jsonPredicateHints = null,
        ?int $limitHint = null,
        ?int $version = null,
        ?string $timestamp = null
    ): QueryResult {
        $body = [];
        if ($predicateHints !== []) {
            $body['predicateHints'] = $predicateHints;
        }
        if ($jsonPredicateHints !== null) {
            $body['jsonPredicateHints'] = $jsonPredicateHints;
        }
        if ($limitHint !== null) {
            $body['limitHint'] = $limitHint;
        }
        if ($version !== null) {
            $body['version'] = $version;
        }
        if ($timestamp !== null) {
            $body['timestamp'] = $timestamp;
        }

        $response = $this->request('POST', $this->tablePath($table) . '/query', [
            'json' => $body === [] ? new \stdClass() : $body,
        ]);
        $lines = $this->parseNdjson($response);

        [$protocol, $metadata] = $this->extractProtocolAndMetadata($lines);
        $files = $this->extractFileActions($lines, ['file']);

        return new QueryResult($protocol, $metadata, $files, $this->versionHeader($response));
    }

    /**
     * Reads the change data feed between two versions or timestamps. Returns
     * the protocol, metadata and every add, cdf and remove action.
     *
     * @return array{protocol: Protocol, metadata: Metadata, actions: FileAction[], version: ?int}
     */
    public function getTableChanges(
        Table $table,
        ?int $startingVersion = null,
        ?int $endingVersion = null,
        ?string $startingTimestamp = null,
        ?string $endingTimestamp = null,
        bool $includeHistoricalMetadata = false
    ): array {
        $query = array_filter([
            'startingVersion' => $startingVersion,
            'endingVersion' => $endingVersion,
            'startingTimestamp' => $startingTimestamp,
            'endingTimestamp' => $endingTimestamp,
        ], fn ($v) => $v !== null);

        if ($includeHistoricalMetadata) {
            $query['includeHistoricalMetadata'] = 'true';
        }

        $response = $this->request('GET', $this->tablePath($table) . '/changes', ['query' => $query]);
        $lines = $this->parseNdjson($response);

        [$protocol, $metadata] = $this->extractProtocolAndMetadata($lines);
        $actions = $this->extractFileActions($lines, ['add', 'cdf', 'remove']);

        return [
            'protocol' => $protocol,
            'metadata' => $metadata,
            'actions' => $actions,
            'version' => $this->versionHeader($response),
        ];
    }

    private function tablePath(Table $table): string
    {
        return 'shares/' . rawurlencode($table->share)
            . '/schemas/' . rawurlencode($table->schema)
            . '/tables/' . rawurlencode($table->name);
    }

    private function paginationQuery(?int $maxResults, ?string $pageToken): array
    {
        $query = [];
        if ($maxResults !== null) {
            $query['maxResults'] = $maxResults;
        }
        if ($pageToken !== null) {
            $query['pageToken'] = $pageToken;
        }

        return $query;
    }

    private function getJson(string $path, array $query = []): array
    {
        $response = $this->request('GET', $path, ['query' => $query]);
        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data)) {
            throw new DeltaSharingException("Unexpected response body from {$path}");
        }

        return $data;
    }

    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $this->profile->bearerToken,
            'User-Agent' => self::USER_AGENT,
            'delta-sharing-capabilities' => self::CAPABILITIES,
        ]);

        try {
            $response = $this->http->request($method, $path, $options);
        } catch (\Throwable $e) {
            if (RetryMiddleware::isTimeout($e)) {
                throw new TimeoutException(
                    "Request to {$path} timed out. If the server needs longer to respond, raise the timeout"
                    . " in ClientOptions. {$e->getMessage()}",
                    0,
                    $e
                );
            }

            throw new DeltaSharingException("Request to {$path} failed: {$e->getMessage()}", 0, $e);
        }

        if ($response->getStatusCode() >= 400) {
            $error = ErrorResponse::fromResponse($response);
            $retryAfter = $response->getHeaderLine('Retry-After');

            throw HttpException::fromStatus(
                $error->statusCode,
                $error->errorCode,
                "Request to {$path} failed with {$error->summary()}",
                is_numeric($retryAfter) ? (int) $retryAfter : null,
                $error->requestId
            );
        }

        return $response;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseNdjson(ResponseInterface $response): array
    {
        $lines = [];
        foreach (explode("\n", (string) $response->getBody()) as $number => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                throw new ProtocolException(
                    sprintf('Server returned a malformed NDJSON line %d: %s', $number + 1, substr($line, 0, 200))
                );
            }

            $lines[] = $decoded;
        }

        return $lines;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array{0: Protocol, 1: Metadata}
     */
    private function extractProtocolAndMetadata(array $lines): array
    {
        $protocol = null;
        $metadata = null;

        foreach ($lines as $line) {
            if (isset($line['protocol'])) {
                $protocol = Protocol::fromArray($line['protocol']);
            } elseif (isset($line['metaData'])) {
                $metadata = Metadata::fromArray($line['metaData']);
            }
        }

        if ($protocol === null || $metadata === null) {
            throw new ProtocolException('Server response is missing the protocol or metaData action');
        }

        if ($protocol->minReaderVersion > 1) {
            throw new ProtocolException(
                "Table requires minReaderVersion {$protocol->minReaderVersion}, this client supports version 1"
            );
        }

        return [$protocol, $metadata];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param string[] $types
     * @return FileAction[]
     */
    private function extractFileActions(array $lines, array $types): array
    {
        $actions = [];
        foreach ($lines as $line) {
            foreach ($types as $type) {
                if (isset($line[$type])) {
                    $actions[] = FileAction::fromArray($type, $line[$type]);
                }
            }
        }

        return $actions;
    }

    private function versionHeader(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Delta-Table-Version');

        return $header === '' ? null : (int) $header;
    }
}
