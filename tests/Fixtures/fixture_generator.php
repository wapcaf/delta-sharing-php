<?php

// Regenerates tests/Fixtures/sample.parquet. Run once after changing the
// fixture schema: php tests/Fixtures/fixture_generator.php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Flow\Parquet\ParquetFile\Schema;
use Flow\Parquet\ParquetFile\Schema\FlatColumn;
use Flow\Parquet\Writer;

$schema = Schema::with(
    FlatColumn::int64('id'),
    FlatColumn::string('destination'),
    FlatColumn::double('price'),
    FlatColumn::boolean('confirmed'),
);

$rows = [
    ['id' => 1, 'destination' => 'Barcelona', 'price' => 799.50, 'confirmed' => true],
    ['id' => 2, 'destination' => 'Lisbon', 'price' => 649.00, 'confirmed' => false],
    ['id' => 3, 'destination' => 'Reykjavik', 'price' => 1249.99, 'confirmed' => true],
    ['id' => 4, 'destination' => 'Athens', 'price' => 899.00, 'confirmed' => true],
    ['id' => 5, 'destination' => 'Bergen', 'price' => 1099.00, 'confirmed' => false],
];

$target = __DIR__ . '/sample.parquet';
if (file_exists($target)) {
    unlink($target);
}

(new Writer())->write($target, $schema, $rows);

echo "Wrote {$target} with " . count($rows) . " rows\n";
