<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\ClientOptions;
use DeltaSharing\DeltaSharingClient;
use DeltaSharing\Exception\DeltaSharingException;
use DeltaSharing\Exception\HttpException;
use DeltaSharing\Exception\TimeoutException;
use DeltaSharing\Model\Table;
use DeltaSharing\Profile;
use DeltaSharing\RestClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class RestClientTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function profile(): Profile
    {
        return Profile::fromArray([
            'endpoint' => 'https://sharing.example.com/delta-sharing',
            'bearerToken' => 'test-token',
        ]);
    }

    private function clientWithResponses(Response|\Throwable ...$responses): RestClient
    {
        $this->history = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $http = new GuzzleClient([
            'handler' => $stack,
            'base_uri' => 'https://sharing.example.com/delta-sharing/',
            'http_errors' => false,
        ]);

        return new RestClient($this->profile(), $http);
    }

    /**
     * A transport failure shaped like the ones Guzzle's cURL handler raises.
     */
    private function connectError(int $errno, string $error): ConnectException
    {
        $url = 'https://sharing.example.com/delta-sharing/shares';

        return new ConnectException(
            "cURL error {$errno}: {$error} (see https://curl.se/libcurl/c/libcurl-errors.html) for {$url}",
            new Request('GET', $url),
            null,
            ['errno' => $errno]
        );
    }

    public function testListSharesSendsBearerToken(): void
    {
        $rest = $this->clientWithResponses(
            new Response(200, [], json_encode(['items' => [['name' => 'share1', 'id' => 'id1']]]))
        );

        $result = $rest->listShares();

        $this->assertCount(1, $result['items']);
        $this->assertSame('share1', $result['items'][0]->name);
        $this->assertSame('id1', $result['items'][0]->id);
        $this->assertNull($result['nextPageToken']);

        $request = $this->history[0]['request'];
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('/delta-sharing/shares', $request->getUri()->getPath());
        $this->assertSame('responseformat=parquet', $request->getHeaderLine('delta-sharing-capabilities'));
        $this->assertStringStartsWith('delta-sharing-php/', $request->getHeaderLine('User-Agent'));
    }

    public function testPaginationIsDrainedByHighLevelClient(): void
    {
        $rest = $this->clientWithResponses(
            new Response(200, [], json_encode([
                'items' => [['name' => 'a']],
                'nextPageToken' => 'page2',
            ])),
            new Response(200, [], json_encode([
                'items' => [['name' => 'b']],
            ]))
        );

        $shares = (new DeltaSharingClient($rest))->listShares();

        $this->assertSame(['a', 'b'], array_map(fn ($s) => $s->name, $shares));
        $this->assertStringContainsString(
            'pageToken=page2',
            $this->history[1]['request']->getUri()->getQuery()
        );
    }

    public function testGetTableVersionReadsHeader(): void
    {
        $rest = $this->clientWithResponses(new Response(200, ['Delta-Table-Version' => '42']));

        $version = $rest->getTableVersion(new Table('t', 's', 'sh'));

        $this->assertSame(42, $version);
    }

    public function testGetTableMetadataParsesNdjson(): void
    {
        $body = implode("\n", [
            json_encode(['protocol' => ['minReaderVersion' => 1]]),
            json_encode(['metaData' => [
                'id' => 'table-id',
                'format' => ['provider' => 'parquet'],
                'schemaString' => '{"type":"struct","fields":[{"name":"id","type":"long","nullable":true,"metadata":{}}]}',
                'partitionColumns' => ['date'],
            ]]),
        ]);

        $rest = $this->clientWithResponses(new Response(200, ['Delta-Table-Version' => '3'], $body));

        $meta = $rest->getTableMetadata(new Table('t', 's', 'sh'));

        $this->assertSame(1, $meta->protocol->minReaderVersion);
        $this->assertSame('table-id', $meta->metadata->id);
        $this->assertSame(['date'], $meta->metadata->partitionColumns);
        $this->assertSame(3, $meta->version);
        $this->assertSame('id', $meta->metadata->schema()['fields'][0]['name']);
    }

    public function testQueryTableParsesFileActions(): void
    {
        $body = implode("\n", [
            json_encode(['protocol' => ['minReaderVersion' => 1]]),
            json_encode(['metaData' => [
                'id' => 'table-id',
                'format' => ['provider' => 'parquet'],
                'schemaString' => '{}',
                'partitionColumns' => [],
            ]]),
            json_encode(['file' => [
                'url' => 'https://bucket.s3.amazonaws.com/part-0.parquet?sig=abc',
                'id' => 'file-1',
                'partitionValues' => ['date' => '2021-04-28'],
                'size' => 573,
                'stats' => '{"numRecords":10}',
            ]]),
            json_encode(['file' => [
                'url' => 'https://bucket.s3.amazonaws.com/part-1.parquet?sig=def',
                'id' => 'file-2',
                'partitionValues' => [],
                'size' => 1024,
            ]]),
        ]);

        $rest = $this->clientWithResponses(new Response(200, [], $body));

        $result = $rest->queryTable(new Table('t', 's', 'sh'), ['date >= "2021-01-01"'], null, 100);

        $this->assertCount(2, $result->files);
        $this->assertSame('file-1', $result->files[0]->id);
        $this->assertSame(['date' => '2021-04-28'], $result->files[0]->partitionValues);
        $this->assertSame(10, $result->files[0]->numRecords());
        $this->assertNull($result->files[1]->numRecords());

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $sent = json_decode((string) $request->getBody(), true);
        $this->assertSame(['date >= "2021-01-01"'], $sent['predicateHints']);
        $this->assertSame(100, $sent['limitHint']);
    }

    public function testQueryTableSendsEmptyJsonObjectWhenNoHints(): void
    {
        $body = implode("\n", [
            json_encode(['protocol' => ['minReaderVersion' => 1]]),
            json_encode(['metaData' => ['id' => 'x', 'schemaString' => '{}']]),
        ]);

        $rest = $this->clientWithResponses(new Response(200, [], $body));
        $rest->queryTable(new Table('t', 's', 'sh'));

        $this->assertSame('{}', (string) $this->history[0]['request']->getBody());
    }

    public function testTableNamesAreUrlEncoded(): void
    {
        $rest = $this->clientWithResponses(new Response(200, ['Delta-Table-Version' => '1']));

        $rest->getTableVersion(new Table('my table', 'sch ema', 'sh are'));

        $path = $this->history[0]['request']->getUri()->getPath();
        $this->assertStringContainsString('sh%20are', $path);
        $this->assertStringContainsString('sch%20ema', $path);
        $this->assertStringContainsString('my%20table', $path);
    }

    public function testGetTableChangesParsesActions(): void
    {
        $body = implode("\n", [
            json_encode(['protocol' => ['minReaderVersion' => 1]]),
            json_encode(['metaData' => ['id' => 'x', 'schemaString' => '{}']]),
            json_encode(['add' => ['url' => 'https://x/1.parquet', 'id' => 'a1', 'version' => 1]]),
            json_encode(['cdf' => ['url' => 'https://x/2.parquet', 'id' => 'c1', 'version' => 2]]),
            json_encode(['remove' => ['url' => 'https://x/3.parquet', 'id' => 'r1', 'version' => 3]]),
        ]);

        $rest = $this->clientWithResponses(new Response(200, [], $body));

        $changes = $rest->getTableChanges(new Table('t', 's', 'sh'), 0, 3);

        $this->assertSame(['add', 'cdf', 'remove'], array_map(fn ($a) => $a->type, $changes['actions']));
        $query = $this->history[0]['request']->getUri()->getQuery();
        $this->assertStringContainsString('startingVersion=0', $query);
        $this->assertStringContainsString('endingVersion=3', $query);
    }

    public function testTimeoutRaisesTimeoutException(): void
    {
        $rest = $this->clientWithResponses(
            $this->connectError(28, 'Operation timed out after 120001 milliseconds with 0 bytes received')
        );

        try {
            $rest->listShares();
            $this->fail('Expected a TimeoutException');
        } catch (TimeoutException $e) {
            $this->assertStringStartsWith('Request to shares timed out.', $e->getMessage());
            $this->assertStringContainsString('ClientOptions', $e->getMessage());
            $this->assertStringContainsString('Operation timed out after 120001 milliseconds', $e->getMessage());
            $this->assertInstanceOf(ConnectException::class, $e->getPrevious());
        }
    }

    public function testOtherTransportErrorsAreNotTimeouts(): void
    {
        $rest = $this->clientWithResponses($this->connectError(6, 'Could not resolve host: sharing.example.com'));

        try {
            $rest->listShares();
            $this->fail('Expected a DeltaSharingException');
        } catch (DeltaSharingException $e) {
            $this->assertNotInstanceOf(TimeoutException::class, $e);
            $this->assertStringContainsString('Could not resolve host', $e->getMessage());
        }
    }

    public function testDefaultClientAppliesTimeoutsAndRetriesATimeoutOnce(): void
    {
        $timeout = $this->connectError(28, 'Operation timed out after 300001 milliseconds with 0 bytes received');
        $mock = new MockHandler([$timeout, $timeout, new Response(200, [], json_encode(['items' => []]))]);
        $options = new ClientOptions(timeout: 300, connectTimeout: 5);
        $profile = $this->profile();

        $rest = new RestClient($profile, RestClient::createHttpClient($profile, $options, $mock), $options);

        try {
            $rest->listShares();
            $this->fail('Expected a TimeoutException');
        } catch (TimeoutException) {
            // Expected: the first timeout was retried, the second one was final.
        }

        $this->assertSame(1, $mock->count(), 'Exactly two attempts, the queued success is never reached');
        $this->assertSame(300.0, $mock->getLastOptions()['timeout']);
        $this->assertSame(5.0, $mock->getLastOptions()['connect_timeout']);
    }

    public function testDefaultClientTargetsTheProfileEndpoint(): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode(['items' => []]))]);
        $profile = $this->profile();
        $rest = new RestClient($profile, RestClient::createHttpClient($profile, new ClientOptions(), $mock));

        $rest->listShares();

        $this->assertSame(
            'https://sharing.example.com/delta-sharing/shares',
            (string) $mock->getLastRequest()->getUri()
        );
    }

    public function testHttpErrorsRaiseTypedException(): void
    {
        $rest = $this->clientWithResponses(
            new Response(403, [], json_encode([
                'errorCode' => 'PERMISSION_DENIED',
                'message' => 'You are not allowed to access this share',
            ]))
        );

        try {
            $rest->listShares();
            $this->fail('Expected an HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->statusCode);
            $this->assertSame('PERMISSION_DENIED', $e->errorCode);
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }
    }
}
