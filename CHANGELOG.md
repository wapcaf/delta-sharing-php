# Changelog

## 0.3.0

Field findings from reading a view shared from Databricks.

- New `ClientOptions` for the HTTP behaviour: REST timeout (default 120 s,
  as before), connect timeout (30 s, previously unset), data file download
  timeout (300 s, as before) and retry limits. Pass it to
  `DeltaSharingClient::fromProfile()` or `fromProfileFile()`,
  `DeltaSharing::client()`, `loadAsArray()` or `listFiles()`,
  `TableReader::forTableUrl()` or the `RestClient` constructor, instead of
  building a Guzzle handler stack. `RestClient::createHttpClient()` and
  `TableReader::createDownloader()` build the default clients.
- Timeouts now have their own retry budget, `maxTimeoutRetries`, one retry
  by default where they previously got up to four like any connection
  error. A request that timed out may still be running on the server
  (Databricks materialises a shared view on its first query after a data
  refresh), and every retry starts that work again. The final timeout
  raises the new `TimeoutException`.
- Failed data file downloads raise the new `DownloadException`, an
  `HttpException` carrying the storage service's status, error code,
  message and request id. Databricks Files API errors report their
  `google.rpc.ErrorInfo` reason (such as `FILES_API_AZURE_FORBIDDEN`) and
  the XML errors of Azure Blob Storage, Amazon S3 and Google Cloud Storage
  are understood. The message used to blame URL expiry for every failure;
  expiry is now only suggested when the URL's `expirationTimestamp` has
  passed or the storage service says so, and `urlExpired` tells callers
  when re-running the query will help.
- Exception messages never include the query string of a pre-signed URL,
  which carries its signature. This includes connection errors during
  downloads, which previously escaped as raw Guzzle exceptions quoting the
  full URL; they now raise `TimeoutException` or `DeltaSharingException`.
- Databricks rejects version requests for shared views with
  `DS_UNSUPPORTED_TABLE_TYPE`, now raised as the new
  `UnsupportedTableTypeException`. The README covers reading views.
- REST errors also read Databricks style `error_code` fields and prefer a
  `google.rpc.ErrorInfo` reason over the generic error code. Every
  `HttpException` exposes the server's request id (`x-request-id`,
  `x-ms-request-id` or `x-amz-request-id`) as `requestId` and names it in
  its message.
- `FileAction::isExpired()` tells whether a pre-signed URL has passed its
  expiry.
- `DeltaSharingClient::VERSION`, sent in the User-Agent header, still read
  0.2.0 in the 0.2.1 release. It now matches, and a test keeps it in step
  with this changelog.

## 0.2.1

Field findings from reading a Databricks (Azure) share.

- A missing compression extension (zstd, lz4 or brotli) now raises a
  `DeltaSharingException` naming the extension and the affected data file,
  instead of flow-php's bare `RuntimeException`. Databricks writers commonly
  produce zstd-compressed parquet, which pure PHP cannot decode; install
  ext-zstd where Databricks shares are read.
- Documented and shipped a workaround for INT96 (legacy Spark) timestamps on
  flow-php/parquet 0.28, the last release supporting PHP 8.2: the bundled
  `patches/flow-php-parquet-0.28-int96-flatvalue.patch` lets the INT96 bytes
  reach the reader's DateTime converter instead of dying on a typehint in the
  Dremel layer. See the README for composer-patches wiring.

## 0.2.0

Parquet decoding is now built in and pure PHP.

- Replaced the optional codename/parquet integration (which needed ext-gmp)
  with a required flow-php/parquet dependency. Decoding now works out of the
  box on any PHP 8.2+ install with bcmath and zlib.
- New streaming API: `TableReader::readRows()` and
  `DeltaSharingClient::readTable()` return generators that download and
  decode one data file at a time.
- Typed exceptions: `AuthenticationException` (401/403),
  `NotFoundException` (404), `RateLimitException` (429),
  `ServerException` (5xx) and `ProtocolException` for malformed responses.
  All extend the existing `HttpException`/`DeltaSharingException` hierarchy.
- Automatic retries with exponential backoff and jitter for connection
  errors, HTTP 429 and HTTP 5xx. Honours the Retry-After header.
- The `delta-sharing-capabilities: responseformat=parquet` header is sent on
  every request so servers always answer in the format this client reads.
- `Profile::expiresWithin()` for warning about tokens that are about to
  lapse, in addition to the existing `Profile::isExpired()`.
- The client now rejects tables that need `minReaderVersion` above 1 with a
  clear error instead of failing while decoding.
- Removed the bundled snappy polyfill, flow-php/snappy covers this now.
- PHP 8.2 is the minimum supported version (was 8.1).

## 0.1.0

Initial release.

- Profile file support (shareCredentialsVersion 1)
- List shares, schemas, tables and all-tables with pagination
- Table metadata, table version (with fallback for older servers)
- Query table data with predicate hints, limit hints and time travel
- Change data feed
- Optional decoding of parquet data files into PHP arrays via codename/parquet
- Pure PHP snappy decoder used when ext-snappy is not available
