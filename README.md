# Delta Sharing connector for PHP

A PHP client for the [Delta Sharing](https://github.com/delta-io/delta-sharing) open protocol. It lets PHP applications discover and read tables that a Delta Sharing server exposes, in the same spirit as the official Python connector and the community connectors for Node.js, Java, Rust, Go, R and others.

## Requirements

- PHP 8.1 or newer with ext-json
- [codename/parquet](https://packagist.org/packages/codename/parquet) (optional) to decode table data into PHP arrays; it needs ext-gmp and ext-bcmath

Without the parquet package you can still list shares, schemas and tables, read table metadata and versions, and fetch the pre-signed data file URLs to hand off to another system.

Most Delta tables store their parquet files with snappy compression. The php snappy extension is awkward to install on Windows, so this package ships a pure PHP snappy decoder that is used automatically when ext-snappy is not loaded. On Windows, enable `extension=gmp` in php.ini (the DLL ships with PHP) before installing codename/parquet.

## Installation

```
composer require deltasharing/client
```

To read table rows directly in PHP, also install the parquet decoder:

```
composer require codename/parquet
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

## Protocol coverage

| Endpoint | Supported |
| --- | --- |
| List Shares | yes, with pagination |
| Get Share | yes |
| List Schemas | yes, with pagination |
| List Tables / List All Tables | yes, with pagination |
| Query Table Version | yes |
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
