# Changelog

## 0.1.0

Initial release.

- Profile file support (shareCredentialsVersion 1)
- List shares, schemas, tables and all-tables with pagination
- Table metadata, table version (with fallback for older servers)
- Query table data with predicate hints, limit hints and time travel
- Change data feed
- Optional decoding of parquet data files into PHP arrays via codename/parquet
- Pure PHP snappy decoder used when ext-snappy is not available
