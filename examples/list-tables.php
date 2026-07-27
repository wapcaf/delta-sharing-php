<?php

// Lists every share, schema and table visible to the demo profile.
// Run with: php examples/list-tables.php

require __DIR__ . '/../vendor/autoload.php';

use DeltaSharing\DeltaSharingClient;

$client = DeltaSharingClient::fromProfileFile(__DIR__ . '/open-datasets.share');

foreach ($client->listShares() as $share) {
    echo "share: {$share->name}\n";

    foreach ($client->listSchemas($share) as $schema) {
        echo "  schema: {$schema->name}\n";

        foreach ($client->listTables($schema) as $table) {
            echo "    table: {$table->fullyQualifiedName()}\n";
        }
    }
}
