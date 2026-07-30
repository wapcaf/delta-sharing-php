<?php

// Reads table metadata and the first rows of a demo table.
// Run with: php examples/read-table.php

require __DIR__ . '/../vendor/autoload.php';

use DeltaSharing\DeltaSharing;
use DeltaSharing\DeltaSharingClient;

$table = 'delta_sharing.default.owid-covid-data';
$profile = __DIR__ . '/open-datasets.share';

$client = DeltaSharingClient::fromProfileFile($profile);

$meta = $client->getTableMetadata($table);
echo "table id: {$meta->metadata->id}\n";
echo "version: {$client->getTableVersion($table)}\n";

$result = $client->queryTable($table, limitHint: 10);
echo count($result->files), " data file(s)\n";

$rows = DeltaSharing::loadAsArray("{$profile}#{$table}", limit: 5);
foreach ($rows as $row) {
    echo json_encode($row), "\n";
}
