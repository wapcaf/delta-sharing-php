<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\Exception\AuthenticationException;
use DeltaSharing\Exception\HttpException;
use DeltaSharing\Exception\NotFoundException;
use DeltaSharing\Exception\RateLimitException;
use DeltaSharing\Exception\ServerException;
use DeltaSharing\Profile;
use DeltaSharing\RestClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
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
}
