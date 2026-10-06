<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\ClientOptions;
use DeltaSharing\Exception\DeltaSharingException;
use DeltaSharing\Exception\DownloadException;
use DeltaSharing\Exception\TimeoutException;
use DeltaSharing\Model\Table;
use DeltaSharing\Profile;
use DeltaSharing\RestClient;
use DeltaSharing\TableReader;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
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

    /**
     * Shaped like an Azure SAS url. The signature must never reach a message.
     */
    private const SIGNATURE = 'c2VjcmV0LXNpZ25hdHVyZQ%3D%3D';

    private const FILE_URL = 'https://blob.example.com/container/part-0.parquet'
        . '?sv=2024-11-04&se=2099-01-01T00%3A00%3A00Z&sr=b&sp=r&sig=' . self::SIGNATURE;

    /**
     * @param array<string, mixed> $file Overrides for the file action.
     */
    private function queryResponseBody(array $partitionValues, array $file = []): string
    {
        return implode("\n", [
            json_encode(['protocol' => ['minReaderVersion' => 1]]),
            json_encode(['metaData' => [
                'id' => 'fixture-table',
                'format' => ['provider' => 'parquet'],
                'schemaString' => self::SCHEMA_STRING,
                'partitionColumns' => array_keys($partitionValues),
            ]]),
            json_encode(['file' => $file + [
                'url' => self::FILE_URL,
                'id' => 'file-1',
                'partitionValues' => $partitionValues,
                'size' => 1024,
            ]]),
        ]);
    }

    /**
     * Builds a TableReader whose REST calls and file downloads are both
     * mocked. By default the download returns the committed fixture parquet.
     */
    private function reader(
        string $queryBody,
        Response|\Throwable|null $download = null,
        ?string $tempDir = null
    ): TableReader {
        $restHttp = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], $queryBody)])),
            'base_uri' => 'https://sharing.example.com/delta-sharing/',
            'http_errors' => false,
        ]);

        $profile = Profile::fromArray([
            'endpoint' => 'https://sharing.example.com/delta-sharing',
            'bearerToken' => 'test-token',
        ]);

        $download ??= new Response(200, [], file_get_contents(__DIR__ . '/Fixtures/sample.parquet'));
        $downloader = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([$download])),
            'http_errors' => false,
        ]);

        return new TableReader(
            new RestClient($profile, $restHttp),
            new Table('trips', 'default', 'demo'),
            $downloader,
            $tempDir
        );
    }

    /**
     * Reads the table and returns the exception it fails with.
     *
     * @template T of \Throwable
     * @param class-string<T> $expected
     * @return T
     */
    private function readFailure(TableReader $reader, string $expected): \Throwable
    {
        try {
            $reader->rows();
        } catch (\Throwable $e) {
            $this->assertSame($expected, $e::class, 'Unexpected exception: ' . $e->getMessage());
            $this->assertStringNotContainsString(self::SIGNATURE, $e->getMessage());
            $this->assertStringNotContainsString('sig=', $e->getMessage());

            return $e;
        }

        $this->fail("Expected a {$expected}");
    }

    /**
     * A transport failure shaped like the ones Guzzle's cURL handler raises,
     * which quote the full url.
     */
    private function transportError(int $errno, string $error): ConnectException
    {
        return new ConnectException(
            "cURL error {$errno}: {$error} (see https://curl.se/libcurl/c/libcurl-errors.html) for " . self::FILE_URL,
            new Request('GET', self::FILE_URL),
            null,
            ['errno' => $errno]
        );
    }

    private function msFromNow(int $seconds): int
    {
        return (int) (microtime(true) * 1000) + $seconds * 1000;
    }

    /**
     * @param callable(string): void $test
     */
    private function withTempDir(callable $test): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'delta-reader-test-' . bin2hex(random_bytes(4));
        mkdir($tempDir);

        try {
            $test($tempDir);
        } finally {
            @array_map('unlink', glob($tempDir . DIRECTORY_SEPARATOR . '*') ?: []);
            @rmdir($tempDir);
        }
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
        $this->withTempDir(function (string $tempDir): void {
            $this->reader($this->queryResponseBody([]), null, $tempDir)->rows();

            $this->assertSame([], glob($tempDir . DIRECTORY_SEPARATOR . '*'), 'Downloaded files must be removed');
        });
    }

    public function testTemporaryFilesAreCleanedUpAfterAFailedDownload(): void
    {
        $this->withTempDir(function (string $tempDir): void {
            $reader = $this->reader(
                $this->queryResponseBody([]),
                new Response(400, [], ErrorResponseTest::FILES_API_FORBIDDEN),
                $tempDir
            );

            $this->readFailure($reader, DownloadException::class);

            $this->assertSame([], glob($tempDir . DIRECTORY_SEPARATOR . '*'), 'Downloaded files must be removed');
        });
    }

    public function testRefusedDownloadReportsTheStorageError(): void
    {
        $reader = $this->reader(
            $this->queryResponseBody([], ['expirationTimestamp' => $this->msFromNow(3600)]),
            new Response(
                400,
                ['Content-Type' => 'application/json', 'x-request-id' => '0f6d3c1e-5b2a-4c8d-9e7f-1a2b3c4d5e6f'],
                ErrorResponseTest::FILES_API_FORBIDDEN
            )
        );

        $e = $this->readFailure($reader, DownloadException::class);

        $this->assertSame(400, $e->statusCode);
        $this->assertSame('FILES_API_AZURE_FORBIDDEN', $e->errorCode);
        $this->assertSame('0f6d3c1e-5b2a-4c8d-9e7f-1a2b3c4d5e6f', $e->requestId);
        $this->assertSame('file-1', $e->fileId);
        $this->assertFalse($e->urlExpired, 'The url had an hour left, expiry must not be blamed');
        $this->assertSame(
            'Download of data file file-1 from blob.example.com failed with HTTP 400 FILES_API_AZURE_FORBIDDEN:'
            . ' Access to the storage container is forbidden by Azure.'
            . ' (request id 0f6d3c1e-5b2a-4c8d-9e7f-1a2b3c4d5e6f)',
            $e->getMessage()
        );
    }

    public function testAzureSasErrorIsReadFromXml(): void
    {
        $reader = $this->reader(
            $this->queryResponseBody([], ['expirationTimestamp' => $this->msFromNow(3600)]),
            new Response(403, [
                'Content-Type' => 'application/xml',
                'x-ms-error-code' => 'AuthorizationFailure',
                'x-ms-request-id' => '7d0e5a8c-a01e-0042-1f3c-9b4d2e000000',
            ], ErrorResponseTest::AZURE_AUTHORIZATION_FAILURE)
        );

        $e = $this->readFailure($reader, DownloadException::class);

        $this->assertSame(403, $e->statusCode);
        $this->assertSame('AuthorizationFailure', $e->errorCode);
        $this->assertSame('7d0e5a8c-a01e-0042-1f3c-9b4d2e000000', $e->requestId);
        $this->assertFalse($e->urlExpired);
        $this->assertStringContainsString(
            'HTTP 403 AuthorizationFailure: This request is not authorized to perform this operation.',
            $e->getMessage()
        );
        $this->assertStringNotContainsString('re-run the query', $e->getMessage());
    }

    public function testExpiryIsReportedWhenTheExpirationTimestampHasPassed(): void
    {
        // 1,000,000 ms after the epoch is 1970-01-01T00:16:40Z.
        $reader = $this->reader(
            $this->queryResponseBody([], ['expirationTimestamp' => 1000000]),
            new Response(403, ['x-ms-error-code' => 'AuthenticationFailed'], ErrorResponseTest::AZURE_EXPIRED_SAS)
        );

        $e = $this->readFailure($reader, DownloadException::class);

        $this->assertTrue($e->urlExpired);
        $this->assertStringEndsWith(
            'The pre-signed URL expired at 1970-01-01T00:16:40Z, re-run the query to get fresh URLs.',
            $e->getMessage()
        );
    }

    public function testExpiryIsReportedWhenTheStorageServiceSaysSo(): void
    {
        $reader = $this->reader(
            $this->queryResponseBody([]),
            new Response(403, ['x-amz-request-id' => '8XKZ2P1EXAMPLE'], ErrorResponseTest::S3_EXPIRED)
        );

        $e = $this->readFailure($reader, DownloadException::class);

        $this->assertTrue($e->urlExpired);
        $this->assertSame(
            'Download of data file file-1 from blob.example.com failed with HTTP 403 AccessDenied: Request has'
            . ' expired (request id 8XKZ2P1EXAMPLE). The storage service reports that the pre-signed URL has'
            . ' expired, re-run the query to get fresh URLs.',
            $e->getMessage()
        );
    }

    public function testTransportErrorsDoNotLeakTheSignature(): void
    {
        $reader = $this->reader(
            $this->queryResponseBody([]),
            $this->transportError(6, 'Could not resolve host: blob.example.com')
        );

        $e = $this->readFailure($reader, DeltaSharingException::class);

        $this->assertStringStartsWith(
            'Download of data file file-1 from blob.example.com failed: cURL error 6: Could not resolve host',
            $e->getMessage()
        );
        $this->assertStringContainsString(
            'for https://blob.example.com/container/part-0.parquet?[redacted]',
            $e->getMessage()
        );
        $this->assertNull($e->getPrevious(), 'The original exception quotes the signed url');
    }

    public function testDownloadTimeoutRaisesTimeoutException(): void
    {
        $reader = $this->reader(
            $this->queryResponseBody([]),
            $this->transportError(28, 'Operation timed out after 300001 milliseconds with 512 out of 1024 bytes')
        );

        $e = $this->readFailure($reader, TimeoutException::class);

        $this->assertStringStartsWith(
            'Download of data file file-1 from blob.example.com timed out.',
            $e->getMessage()
        );
        $this->assertStringContainsString('downloadTimeout', $e->getMessage());
        $this->assertNull($e->getPrevious(), 'The original exception quotes the signed url');
    }

    public function testDefaultDownloaderUsesTheConfiguredTimeouts(): void
    {
        $mock = new MockHandler([new Response(200)]);

        TableReader::createDownloader(new ClientOptions(connectTimeout: 5, downloadTimeout: 600), $mock)
            ->get('https://blob.example.com/container/part-0.parquet');

        $this->assertSame(600.0, $mock->getLastOptions()['timeout']);
        $this->assertSame(5.0, $mock->getLastOptions()['connect_timeout']);
    }
}
