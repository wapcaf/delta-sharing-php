<?php

declare(strict_types=1);

namespace DeltaSharing\Tests\Integration;

use DeltaSharing\DeltaSharingClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end checks against a real Delta Sharing server. Skipped unless the
 * DELTA_SHARING_PROFILE environment variable points to a profile file.
 *
 * Run with: vendor/bin/phpunit --group integration
 */
#[Group('integration')]
final class LiveEndpointTest extends TestCase
{
    private DeltaSharingClient $client;

    protected function setUp(): void
    {
        $profilePath = getenv('DELTA_SHARING_PROFILE');
        if ($profilePath === false || $profilePath === '' || !is_file($profilePath)) {
            $this->markTestSkipped('Set DELTA_SHARING_PROFILE to a profile file to run integration tests');
        }

        $this->client = DeltaSharingClient::fromProfileFile($profilePath);
    }

    public function testEndToEndDiscoveryAndRead(): void
    {
        $shares = $this->client->listShares();
        $this->assertNotEmpty($shares, 'The profile should expose at least one share');

        $tables = $this->client->listAllTables($shares[0]);
        $this->assertNotEmpty($tables, 'The first share should expose at least one table');

        $table = $tables[0];

        $version = $this->client->getTableVersion($table);
        $this->assertGreaterThanOrEqual(0, $version);

        $metadata = $this->client->getTableMetadata($table);
        $this->assertNotSame('', $metadata->metadata->id);
        $this->assertIsArray($metadata->metadata->schema());

        $result = $this->client->queryTable($table, limitHint: 10);

        if ($result->files === []) {
            $this->markTestIncomplete('Table has no data files, connectivity verified but nothing to decode');
        }

        $rows = iterator_to_array($this->client->readTable($table, limit: 5), false);
        $this->assertNotEmpty($rows);
        $this->assertIsArray($rows[0]);
    }
}
