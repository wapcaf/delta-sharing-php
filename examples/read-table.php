<?php

// Reads table metadata and the first rows of a demo table.
// Run with: php examples/read-table.php
// Decoding rows needs the codename/parquet package (see README).

require __DIR__ . '/../vendor/autoload.php';

use DeltaSharing\DeltaSharing;
use DeltaSharing\DeltaSharingClient;
use DeltaSharing\TableReader;

$table = 'delta_sharing.default.owid-covid-data';
$profile = __DIR__ . '/open-datasets.share';

$client = DeltaSharingClient::fromProfileFile($profile);

$meta = $client->getTableMetadata($table);
echo "table id: {$meta->metadata->id}\n";
echo "version: {$client->getTableVersion($table)}\n";

$result = $client->queryTable($table, limitHint: 10);
echo count($result->files), " data file(s)\n";

if (!TableReader::parquetSupportAvailable()) {
    echo "codename/parquet is not installed, skipping row decoding\n";
    exit;
}

$rows = DeltaSharing::loadAsArray("{$profile}#{$table}", limit: 5);
foreach ($rows as $row) {
    echo json_encode($row), "\n";
}
