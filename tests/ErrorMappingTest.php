<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\DeltaSharingClient;
use DeltaSharing\Exception\AuthenticationException;
use DeltaSharing\Exception\HttpException;
use DeltaSharing\Exception\NotFoundException;
use DeltaSharing\Exception\RateLimitException;
use DeltaSharing\Exception\ServerException;
use DeltaSharing\Exception\UnsupportedTableTypeException;
use DeltaSharing\Profile;
use DeltaSharing\RestClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorMappingTest extends TestCase
{
    private function clientWithResponse(Response $response): RestClient
    {
        $http = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([$response])),
            'base_uri' => 'https://sharing.example.com/delta-sharing/',
            'http_errors' => false,
        ]);

        $profile = Profile::fromArray([
            'endpoint' => 'https://sharing.example.com/delta-sharing',
            'bearerToken' => 'test-token',
        ]);

        return new RestClient($profile, $http);
    }

    private function errorBody(string $code, string $message): string
    {
        return json_encode(['errorCode' => $code, 'message' => $message]);
    }

    public function testUnauthorizedMapsToAuthenticationException(): void
    {
        $rest = $this->clientWithResponse(
            new Response(401, [], $this->errorBody('UNAUTHENTICATED', 'Invalid token'))
        );

        $this->expectException(AuthenticationException::class);

        $rest->listShares();
    }

    public function testForbiddenMapsToAuthenticationException(): void
    {
        $rest = $this->clientWithResponse(
            new Response(403, [], $this->errorBody('PERMISSION_DENIED', 'No access'))
        );

        try {
            $rest->listShares();
            $this->fail('Expected an AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame(403, $e->statusCode);
            $this->assertSame('PERMISSION_DENIED', $e->errorCode);
        }
    }

    public function testNotFoundMapsToNotFoundException(): void
    {
        $rest = $this->clientWithResponse(
            new Response(404, [], $this->errorBody('RESOURCE_DOES_NOT_EXIST', 'Unknown table'))
        );

        try {
            $rest->listShares();
            $this->fail('Expected a NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('RESOURCE_DOES_NOT_EXIST', $e->errorCode);
            $this->assertStringContainsString('Unknown table', $e->getMessage());
        }
    }

    public function testTooManyRequestsMapsToRateLimitException(): void
    {
        $rest = $this->clientWithResponse(
            new Response(429, ['Retry-After' => '30'], $this->errorBody('RESOURCE_EXHAUSTED', 'Slow down'))
        );

        try {
            $rest->listShares();
            $this->fail('Expected a RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertSame(30, $e->retryAfterSeconds);
        }
    }

    public function testServerErrorMapsToServerException(): void
    {
        $rest = $this->clientWithResponse(
            new Response(503, [], $this->errorBody('UNAVAILABLE', 'Try later'))
        );

        $this->expectException(ServerException::class);

        $rest->listShares();
    }

    public function testOtherClientErrorsStayHttpException(): void
    {
        $rest = $this->clientWithResponse(
            new Response(400, [], $this->errorBody('INVALID_ARGUMENT', 'Bad request'))
        );

        try {
            $rest->listShares();
            $this->fail('Expected an HttpException');
        } catch (HttpException $e) {
            $this->assertSame(HttpException::class, $e::class);
            $this->assertSame(400, $e->statusCode);
        }
    }

    public function testNonJsonErrorBodyIsHandled(): void
    {
        $rest = $this->clientWithResponse(
            new Response(500, [], '<html>Gateway error</html>')
        );

        try {
            $rest->listShares();
            $this->fail('Expected a ServerException');
        } catch (ServerException $e) {
            $this->assertNull($e->errorCode);
            $this->assertStringContainsString('Gateway error', $e->getMessage());
        }
    }

    public function testDatabricksErrorShapeAndRequestIdAreRead(): void
    {
        $rest = $this->clientWithResponse(new Response(
            400,
            ['x-request-id' => '5d0c7a3e-0000-4000-8000-000000000001'],
            json_encode(['error_code' => 'INVALID_PARAMETER_VALUE', 'message' => 'Unknown parameter'])
        ));

        try {
            $rest->listShares();
            $this->fail('Expected an HttpException');
        } catch (HttpException $e) {
            $this->assertSame('INVALID_PARAMETER_VALUE', $e->errorCode);
            $this->assertSame('5d0c7a3e-0000-4000-8000-000000000001', $e->requestId);
            $this->assertSame(
                'Request to shares failed with HTTP 400 INVALID_PARAMETER_VALUE: Unknown parameter'
                . ' (request id 5d0c7a3e-0000-4000-8000-000000000001)',
                $e->getMessage()
            );
        }
    }

    public function testRateLimitExceptionKeepsTheRequestId(): void
    {
        $rest = $this->clientWithResponse(new Response(
            429,
            ['Retry-After' => '30', 'x-request-id' => 'req-429'],
            $this->errorBody('RESOURCE_EXHAUSTED', 'Slow down')
        ));

        try {
            $rest->listShares();
            $this->fail('Expected a RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(30, $e->retryAfterSeconds);
            $this->assertSame('req-429', $e->requestId);
        }
    }

    /**
     * Databricks reports the error class at the start of the message; other
     * servers may use it as the error code or as a google.rpc.ErrorInfo reason.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function sharedViewVersionErrors(): array
    {
        $message = 'table with type [VIEW] is currently unsupported in version queries.';

        return [
            'error class in the message' => [
                ['errorCode' => 'BAD_REQUEST', 'message' => "DS_UNSUPPORTED_TABLE_TYPE: {$message}"],
            ],
            'error class as the error code' => [
                ['errorCode' => 'DS_UNSUPPORTED_TABLE_TYPE', 'message' => $message],
            ],
            'error class as an ErrorInfo reason' => [[
                'error_code' => 'BAD_REQUEST',
                'message' => $message,
                'details' => [[
                    '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                    'reason' => 'DS_UNSUPPORTED_TABLE_TYPE',
                    'domain' => 'sharing.example.com',
                ]],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('sharedViewVersionErrors')]
    public function testVersionOfASharedViewRaisesUnsupportedTableTypeException(array $body): void
    {
        // Only one response is queued, so falling back to the HEAD request
        // older servers need would fail the test with an empty mock queue.
        $client = new DeltaSharingClient($this->clientWithResponse(
            new Response(400, ['Content-Type' => 'application/json'], json_encode($body))
        ));

        try {
            $client->getTableVersion('share.schema.bookings_view');
            $this->fail('Expected an UnsupportedTableTypeException');
        } catch (UnsupportedTableTypeException $e) {
            $this->assertInstanceOf(HttpException::class, $e);
            $this->assertSame(400, $e->statusCode);
            $this->assertStringContainsString('DS_UNSUPPORTED_TABLE_TYPE', $e->getMessage());
            $this->assertStringContainsString('[VIEW] is currently unsupported in version queries', $e->getMessage());
        }
    }

    public function testOtherBadRequestsAreNotMistakenForUnsupportedTableTypes(): void
    {
        $this->assertNotInstanceOf(
            UnsupportedTableTypeException::class,
            HttpException::fromStatus(400, 'BAD_REQUEST', 'Request to shares failed with HTTP 400: Invalid page token')
        );
    }
}
