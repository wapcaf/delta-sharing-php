# Delta Sharing connector for PHP

A PHP client for the [Delta Sharing](https://github.com/delta-io/delta-sharing) open protocol. It lets PHP applications discover and read tables that a Delta Sharing server exposes, in the same spirit as the official Python connector and the community connectors for Node.js, Java, Rust, Go, R and others.

## Requirements

- PHP 8.2, 8.3 or 8.4
- ext-json, ext-bcmath and ext-zlib (all bundled with PHP on most platforms)

Parquet decoding is handled by [flow-php/parquet](https://packagist.org/packages/flow-php/parquet), a pure PHP implementation installed automatically with this package. Snappy compressed files are decoded with a pure PHP snappy implementation, so no extra extensions are needed on any platform, including Windows.

### Compression codecs

Snappy and gzip parquet files decode with no extra extensions. Files compressed with **zstd**, lz4 or brotli need the matching PECL extension ([kjdev/php-ext-zstd](https://github.com/kjdev/php-ext-zstd) publishes Windows DLLs for zstd). Databricks writers commonly produce zstd, so plan for that extension when reading Databricks shares; without it the connector raises a `DeltaSharingException` naming the missing extension.

### Known issue: INT96 timestamps on PHP 8.2

Some writers (Spark and Databricks in legacy timestamp mode) store timestamps as the deprecated INT96 physical type. flow-php/parquet 0.28, the last release that supports PHP 8.2, fails on such files with `FlatValue::__construct(): Argument #4 ($value) must be of type string|int|float|bool|null, Bytes given` because the INT96 bytes reach the Dremel layer before the DateTime converter runs. On PHP 8.3+ simply use a newer flow-php/parquet. On PHP 8.2, apply [patches/flow-php-parquet-0.28-int96-flatvalue.patch](patches/flow-php-parquet-0.28-int96-flatvalue.patch) with [cweagans/composer-patches](https://github.com/cweagans/composer-patches) from your project's composer.json:

```json
{
    "require": { "cweagans/composer-patches": "^1.7" },
    "config": { "allow-plugins": { "cweagans/composer-patches": true } },
    "extra": {
        "patches": {
            "flow-php/parquet": {
                "INT96 timestamps on flow-php 0.28": "vendor/wapcaf/delta-sharing-php/patches/flow-php-parquet-0.28-int96-flatvalue.patch"
            }
        }
    }
}
```

(Or copy the patch file into your own repo and point the entry there, which avoids depending on vendor paths.) With the patch applied, INT96 values decode to `DateTimeImmutable` as normal.

## Installation

```
composer require wapcaf/delta-sharing-php
```

## Getting a profile

Access to a Delta Sharing server is granted through a profile file, a small JSON document that the data provider sends you:

```json
{
  "shareCredentialsVersion": 1,
  "endpoint": "https://sharing.delta.io/delta-sharing/",
  "bearerToken": "<token>"
}
```

The examples below use [examples/open-datasets.share](examples/open-datasets.share), the public demo profile published by the Delta Sharing project.

## Quick start

Load a whole table (or the first N rows) as an array of associative rows. Table URLs use the same format as the other connectors: `<profile-file>#<share>.<schema>.<table>`.

```php
use DeltaSharing\DeltaSharing;

$rows = DeltaSharing::loadAsArray(
    'examples/open-datasets.share#delta_sharing.default.owid-covid-data',
    limit: 100
);

print_r($rows[0]);
```

For large tables, stream rows with a generator instead. Files are downloaded and decoded one at a time, so memory use stays flat:

```php
use DeltaSharing\DeltaSharingClient;

$client = DeltaSharingClient::fromProfileFile('examples/open-datasets.share');

foreach ($client->readTable('delta_sharing.default.owid-covid-data', limit: 1000) as $row) {
    // each $row is an associative array, partition columns included
}
```

## Discovering shares, schemas and tables

```php
use DeltaSharing\DeltaSharingClient;

$client = DeltaSharingClient::fromProfileFile('examples/open-datasets.share');

foreach ($client->listShares() as $share) {
    foreach ($client->listSchemas($share) as $schema) {
        foreach ($client->listTables($schema) as $table) {
            echo $table->fullyQualifiedName(), PHP_EOL;
        }
    }
}

// Or in one call per share:
$tables = $client->listAllTables('delta_sharing');
```

## Table metadata and versions

```php
$meta = $client->getTableMetadata('delta_sharing.default.owid-covid-data');

echo $meta->metadata->id, PHP_EOL;
print_r($meta->metadata->partitionColumns);
print_r($meta->metadata->schema());   // decoded schemaString

$version = $client->getTableVersion('delta_sharing.default.owid-covid-data');
```

Shared views have no version, see [Shared views](#shared-views).

## Reading data files without decoding them

`queryTable` returns the pre-signed URLs of the parquet files that make up the table. The URLs are short lived but need no extra credentials, so any downstream tool can download them.

```php
$result = $client->queryTable(
    'delta_sharing.default.owid-covid-data',
    predicateHints: ["date >= '2021-01-01'"],
    limitHint: 1000
);

foreach ($result->files as $file) {
    echo $file->url, ' (', $file->size, " bytes)\n";
}
```

Predicate and limit hints are best effort on the server side. The server may return files containing rows that do not match, so apply your own filtering after reading.

Each file's `expirationTimestamp` (milliseconds since the Unix epoch, when the server sends it) says when its URL stops working, and `$file->isExpired()` checks it.

## Change data feed

For tables with CDF enabled, read the changes between two versions or timestamps:

```php
$changes = $client->getTableChanges(
    'my_share.my_schema.my_table',
    startingVersion: 5,
    endingVersion: 10
);

foreach ($changes['actions'] as $action) {
    echo $action->type, ' ', $action->url, PHP_EOL;   // add, cdf or remove
}
```

## Time travel

Both `queryTable` and `DeltaSharing::loadAsArray` accept a version number to read a snapshot of the table as of that version. `queryTable` also accepts an ISO 8601 timestamp.

## Shared views

Databricks can share views as well as tables. Views are read the same way, through `getTableMetadata`, `queryTable` and `readTable`, but they have no table version or change data feed. Databricks rejects version requests for a view with `DS_UNSUPPORTED_TABLE_TYPE`, which this connector raises as `UnsupportedTableTypeException`:

```php
use DeltaSharing\Exception\UnsupportedTableTypeException;

try {
    $version = $client->getTableVersion('my_share.my_schema.my_view');
} catch (UnsupportedTableTypeException) {
    $version = null;   // a shared view
}
```

The server materialises a view when it is queried, so the first query after the underlying data changes can take a minute or more, while later queries are much faster. If those first queries time out, raise the timeout as shown below.

## Timeouts and retries

`ClientOptions` sets the HTTP behaviour. Every entry point that builds its own client accepts it:

```php
use DeltaSharing\ClientOptions;
use DeltaSharing\DeltaSharing;
use DeltaSharing\DeltaSharingClient;

$options = new ClientOptions(timeout: 300);

$client = DeltaSharingClient::fromProfileFile('config.share', $options);
$rows = DeltaSharing::loadAsArray('config.share#my_share.my_schema.my_view', options: $options);
```

| Option | Default | Purpose |
| --- | --- | --- |
| `timeout` | 120 | Seconds to wait for a REST response, including the time the server needs to prepare it |
| `connectTimeout` | 30 | Seconds to wait for a connection, for REST calls and downloads |
| `downloadTimeout` | 300 | Seconds allowed for downloading one data file |
| `maxRetries` | 4 | Retries for connection errors, timeouts, HTTP 429 and HTTP 5xx |
| `maxTimeoutRetries` | 1 | How many of those retries may follow a timeout |

Transient failures on REST calls are retried with exponential backoff and jitter, honouring any Retry-After header. Timeouts have a smaller budget: a request that timed out may still be running on the server, and a retry starts that work again from scratch, so by default a timeout is retried once and a second timeout is final. The last timeout raises a `TimeoutException`. Data file downloads are not retried.

## Error handling

Once retries are exhausted, or for errors that are not retried, a typed exception is thrown:

```php
use DeltaSharing\Exception\AuthenticationException;        // 401 / 403
use DeltaSharing\Exception\NotFoundException;              // 404
use DeltaSharing\Exception\RateLimitException;             // 429, exposes retryAfterSeconds
use DeltaSharing\Exception\ServerException;                // 5xx
use DeltaSharing\Exception\UnsupportedTableTypeException;  // e.g. the version of a shared view
use DeltaSharing\Exception\DownloadException;              // storage refused a data file download
use DeltaSharing\Exception\HttpException;                  // any other HTTP error, base of the above
use DeltaSharing\Exception\TimeoutException;               // no response within the timeout
use DeltaSharing\Exception\ProtocolException;              // malformed server response
use DeltaSharing\Exception\DeltaSharingException;          // base class of everything above

try {
    $client->getTableMetadata('my_share.my_schema.missing_table');
} catch (NotFoundException $e) {
    echo $e->statusCode, ' ', $e->errorCode, ' ', $e->getMessage();
}
```

Every `HttpException` carries the server's request id in `$e->requestId` when one was sent (`x-request-id`, `x-ms-request-id` or `x-amz-request-id`). Data providers ask for it when investigating a failed request.

Data files are downloaded from cloud storage rather than from the sharing server, so a refused download raises `DownloadException` with the storage service's own status, error code and message, for example `FILES_API_AZURE_FORBIDDEN` when Databricks cannot read the storage behind a share. `$e->urlExpired` is true when the pre-signed URL had expired, in which case re-running the query returns fresh URLs. Otherwise the cause lies elsewhere, often storage permissions or network rules on the provider's side. Messages never include a pre-signed URL's query string, because it carries the signature.

Profiles expose their expiry so applications can warn before a token lapses:

```php
$profile = DeltaSharing\Profile::fromFile('config.share');

if ($profile->isExpired()) { /* request new credentials */ }
if ($profile->expiresWithin(new DateInterval('P7D'))) { /* warn */ }
```

## Protocol coverage

| Endpoint | Supported |
| --- | --- |
| List Shares | yes, with pagination |
| Get Share | yes |
| List Schemas | yes, with pagination |
| List Tables / List All Tables | yes, with pagination |
| Query Table Version | yes, except for shared views |
| Query Table Metadata | yes |
| Query Table (read data) | yes, parquet response format |
| Read Change Data Feed | yes |
| Delta response format, deletion vectors | not yet |
| Temporary table credentials (dir access) | not yet |

## Running the tests

```
composer install
composer test
```

The unit tests mock the HTTP layer and do not need network access. See [examples/](examples/) for scripts that run against the public demo server at sharing.delta.io.

## License

MIT
