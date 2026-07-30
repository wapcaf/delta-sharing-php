<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\Model\Table;
use DeltaSharing\Profile;
use DeltaSharing\RestClient;
use DeltaSharing\TableReader;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class TableReaderTest extends TestCase
{
    private const SCHEMA_STRING = '{"type":"struct","fields":['
        . '{"name":"id","type":"long","nullable":true,"metadata":{}},'
        . '{"name":"destination","type":"string","nullable":true,"metadata":{}},'
        . '{"name":"price","type":"double","nullable":true,"metadata":{}},'
        . '{"name":"confirmed","type":"boolean","nullable":true,"metadata":{}},'
        . '{"name":"region","type":"string","nullable":true,"metadata":{}},'
        . '{"name":"year","type":"integer","nullable":true,"metadata":{}}'
        . ']}';

    private function queryResponseBody(array $partitionValues): string
    {
        return implode("\n", [
            json_encode(['protocol' => ['minReaderVersion' => 1]]),
            json_encode(['metaData' => [
                'id' => 'fixture-table',
                'format' => ['provider' => 'parquet'],
                'schemaString' => self::SCHEMA_STRING,
                'partitionColumns' => array_keys($partitionValues),
            ]]),
            json_encode(['file' => [
                'url' => 'https://blob.example.com/part-0.parquet?sig=abc',
                'id' => 'file-1',
                'partitionValues' => $partitionValues,
                'size' => 1024,
            ]]),
        ]);
    }

    /**
     * Builds a TableReader whose REST calls and file downloads are both
     * mocked. The download response body is the committed fixture parquet.
     */
    private function reader(string $queryBody): TableReader
    {
        $restHttp = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], $queryBody)])),
            'base_uri' => 'https://sharing.example.com/delta-sharing/',
            'http_errors' => false,
        ]);

        $profile = Profile::fromArray([
            'endpoint' => 'https://sharing.example.com/delta-sharing',
            'bearerToken' => 'test-token',
        ]);

        $parquetBytes = file_get_contents(__DIR__ . '/Fixtures/sample.parquet');
        $downloader = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], $parquetBytes)])),
            'http_errors' => false,
        ]);

        return new TableReader(
            new RestClient($profile, $restHttp),
            new Table('trips', 'default', 'demo'),
            $downloader
        );
    }

    public function testReadsRowsFromParquetFile(): void
    {
        $reader = $this->reader($this->queryResponseBody([]));

        $rows = $reader->rows();

        $this->assertCount(5, $rows);
        $this->assertSame(1, $rows[0]['id']);
        $this->assertSame('Barcelona', $rows[0]['destination']);
        $this->assertSame(799.50, $rows[0]['price']);
        $this->assertTrue($rows[0]['confirmed']);
    }

    public function testMergesTypedPartitionValuesIntoEveryRow(): void
    {
        $reader = $this->reader($this->queryResponseBody(['region' => 'EU', 'year' => '2026']));

        $rows = $reader->rows();

        foreach ($rows as $row) {
            $this->assertSame('EU', $row['region']);
            $this->assertSame(2026, $row['year'], 'Partition values must be cast using the schema type');
        }
    }

    public function testLimitStopsEarly(): void
    {
        $reader = $this->reader($this->queryResponseBody([]));

        $rows = iterator_to_array($reader->readRows(limit: 2), false);

        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], array_column($rows, 'id'));
    }

    public function testTemporaryFilesAreCleanedUp(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'delta-reader-test-' . bin2hex(random_bytes(4));
        mkdir($tempDir);

        try {
            $restHttp = new GuzzleClient([
                'handler' => HandlerStack::create(
                    new MockHandler([new Response(200, [], $this->queryResponseBody([]))])
                ),
                'base_uri' => 'https://sharing.example.com/delta-sharing/',
                'http_errors' => false,
            ]);

            $profile = Profile::fromArray([
                'endpoint' => 'https://sharing.example.com/delta-sharing',
                'bearerToken' => 'test-token',
            ]);

            $downloader = new GuzzleClient([
                'handler' => HandlerStack::create(
                    new MockHandler([new Response(200, [], file_get_contents(__DIR__ . '/Fixtures/sample.parquet'))])
                ),
                'http_errors' => false,
            ]);

            $reader = new TableReader(
                new RestClient($profile, $restHttp),
                new Table('trips', 'default', 'demo'),
                $downloader,
                $tempDir
            );

            $reader->rows();

            $this->assertSame([], glob($tempDir . DIRECTORY_SEPARATOR . '*'), 'Downloaded files must be removed');
        } finally {
            @array_map('unlink', glob($tempDir . DIRECTORY_SEPARATOR . '*') ?: []);
            @rmdir($tempDir);
        }
    }
}
