# Changelog

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
