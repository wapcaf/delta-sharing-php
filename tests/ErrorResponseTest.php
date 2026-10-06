<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\ErrorResponse;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorResponseTest extends TestCase
{
    /**
     * The answer of the Databricks Files API when it cannot read the storage
     * container behind a shared view.
     */
    public const FILES_API_FORBIDDEN = '{"error_code":"BAD_REQUEST","message":"Access to the storage container is'
        . ' forbidden by Azure.","details":[{"@type":"type.googleapis.com/google.rpc.ErrorInfo",'
        . '"reason":"FILES_API_AZURE_FORBIDDEN","domain":"filesystem.databricks.com"}]}';

    /**
     * An Azure Blob Storage error, byte order mark included.
     */
    public const AZURE_AUTHORIZATION_FAILURE = "\u{FEFF}<?xml version=\"1.0\" encoding=\"utf-8\"?>"
        . '<Error><Code>AuthorizationFailure</Code><Message>This request is not authorized to perform this'
        . " operation.\nRequestId:7d0e5a8c-a01e-0042-1f3c-9b4d2e000000\nTime:2026-10-05T15:20:00.0000000Z"
        . '</Message></Error>';

    public const AZURE_EXPIRED_SAS = '<?xml version="1.0" encoding="utf-8"?><Error><Code>AuthenticationFailed'
        . '</Code><Message>Server failed to authenticate the request. Make sure the value of Authorization header'
        . " is formed correctly including the signature.\nRequestId:2b6f9c1d-801e-0042-7a5b-3c4d5e000000\n"
        . 'Time:2026-10-05T10:05:00.0000000Z</Message><AuthenticationErrorDetail>Signature not valid in the'
        . ' specified time frame: Start [Mon, 05 Oct 2026 09:00:00 GMT] - Expiry [Mon, 05 Oct 2026 10:00:00 GMT]'
        . ' - Current [Mon, 05 Oct 2026 10:05:00 GMT]</AuthenticationErrorDetail></Error>';

    public const S3_EXPIRED = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>AccessDenied</Code>'
        . '<Message>Request has expired</Message><X-Amz-Expires>3600</X-Amz-Expires>'
        . '<Expires>2026-10-05T10:00:00Z</Expires><ServerTime>2026-10-05T10:05:00Z</ServerTime>'
        . '<RequestId>8XKZ2P1EXAMPLE</RequestId><HostId>ZXhhbXBsZS1ob3N0LWlk</HostId></Error>';

    public function testDatabricksFilesApiError(): void
    {
        $error = ErrorResponse::fromResponse(new Response(
            400,
            ['Content-Type' => 'application/json', 'x-request-id' => '0f6d3c1e-5b2a-4c8d-9e7f-1a2b3c4d5e6f'],
            self::FILES_API_FORBIDDEN
        ));

        $this->assertSame(400, $error->statusCode);
        $this->assertSame('FILES_API_AZURE_FORBIDDEN', $error->errorCode, 'The ErrorInfo reason beats BAD_REQUEST');
        $this->assertSame('Access to the storage container is forbidden by Azure.', $error->message);
        $this->assertSame('0f6d3c1e-5b2a-4c8d-9e7f-1a2b3c4d5e6f', $error->requestId);
        $this->assertFalse($error->indicatesExpiry());
        $this->assertSame(
            'HTTP 400 FILES_API_AZURE_FORBIDDEN: Access to the storage container is forbidden by Azure.'
            . ' (request id 0f6d3c1e-5b2a-4c8d-9e7f-1a2b3c4d5e6f)',
            $error->summary()
        );
    }

    public function testDeltaSharingProtocolError(): void
    {
        $error = ErrorResponse::fromResponse(new Response(
            404,
            [],
            json_encode(['errorCode' => 'RESOURCE_DOES_NOT_EXIST', 'message' => 'Table not found'])
        ));

        $this->assertSame('RESOURCE_DOES_NOT_EXIST', $error->errorCode);
        $this->assertSame('Table not found', $error->message);
        $this->assertNull($error->requestId);
        $this->assertSame('HTTP 404 RESOURCE_DOES_NOT_EXIST: Table not found', $error->summary());
    }

    public function testAzureStorageError(): void
    {
        $error = ErrorResponse::fromResponse(new Response(403, [
            'Content-Type' => 'application/xml',
            'x-ms-error-code' => 'AuthorizationFailure',
            'x-ms-request-id' => '7d0e5a8c-a01e-0042-1f3c-9b4d2e000000',
        ], self::AZURE_AUTHORIZATION_FAILURE));

        $this->assertSame('AuthorizationFailure', $error->errorCode);
        $this->assertSame('This request is not authorized to perform this operation.', $error->message);
        $this->assertSame('7d0e5a8c-a01e-0042-1f3c-9b4d2e000000', $error->requestId);
        $this->assertFalse($error->indicatesExpiry());
    }

    public function testAzureRequestIdIsReadFromTheMessageWhenTheHeaderIsMissing(): void
    {
        $error = ErrorResponse::fromResponse(new Response(403, [], self::AZURE_AUTHORIZATION_FAILURE));

        $this->assertSame('7d0e5a8c-a01e-0042-1f3c-9b4d2e000000', $error->requestId);
        $this->assertSame('This request is not authorized to perform this operation.', $error->message);
    }

    public function testAzureExpiredSignatureKeepsTheAuthenticationDetail(): void
    {
        $error = ErrorResponse::fromResponse(new Response(403, [], self::AZURE_EXPIRED_SAS));

        $this->assertSame('AuthenticationFailed', $error->errorCode);
        $this->assertStringContainsString('Signature not valid in the specified time frame', (string) $error->message);
        $this->assertStringNotContainsString('RequestId:', (string) $error->message);
        $this->assertTrue($error->indicatesExpiry());
    }

    public function testAmazonS3ExpiredRequest(): void
    {
        $error = ErrorResponse::fromResponse(
            new Response(403, ['x-amz-request-id' => '8XKZ2P1EXAMPLE'], self::S3_EXPIRED)
        );

        $this->assertSame('AccessDenied', $error->errorCode);
        $this->assertSame('Request has expired', $error->message);
        $this->assertSame('8XKZ2P1EXAMPLE', $error->requestId);
        $this->assertTrue($error->indicatesExpiry());
    }

    public function testGoogleCloudStorageExpiredToken(): void
    {
        $error = ErrorResponse::fromResponse(new Response(400, [], "<?xml version='1.0' encoding='UTF-8'?>"
            . '<Error><Code>ExpiredToken</Code><Message>Invalid argument.</Message><Details>The provided token'
            . ' has expired. Request signature expired at: 2026-10-05T10:00:00+00:00</Details></Error>'));

        $this->assertSame('ExpiredToken', $error->errorCode);
        $this->assertSame(
            'Invalid argument. The provided token has expired. Request signature expired at: 2026-10-05T10:00:00+00:00',
            $error->message
        );
        $this->assertTrue($error->indicatesExpiry());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bodiesEchoingTheSignature(): array
    {
        return [
            'Amazon S3 SignatureDoesNotMatch' => ['<Error><Code>SignatureDoesNotMatch</Code><Message>The request'
                . ' signature we calculated does not match the signature you provided.</Message>'
                . '<SignatureProvided>c2VjcmV0LXNpZ25hdHVyZQ</SignatureProvided></Error>'],
            'Azure InvalidQueryParameterValue' => ['<Error><Code>InvalidQueryParameterValue</Code><Message>Value'
                . ' for one of the query parameters specified in the request URI is invalid.</Message>'
                . '<QueryParameterName>sig</QueryParameterName>'
                . '<QueryParameterValue>c2VjcmV0LXNpZ25hdHVyZQ</QueryParameterValue></Error>'],
        ];
    }

    #[DataProvider('bodiesEchoingTheSignature')]
    public function testOnlyKnownXmlElementsAreRead(string $body): void
    {
        $error = ErrorResponse::fromResponse(new Response(403, [], $body));

        $this->assertNotNull($error->message);
        $this->assertStringNotContainsString('c2VjcmV0LXNpZ25hdHVyZQ', $error->summary());
    }

    public function testXmlEntitiesAreDecoded(): void
    {
        $error = ErrorResponse::fromResponse(new Response(400, [], '<Error><Code>InvalidUri</Code>'
            . '<Message>The requested URI does not represent any resource on the server &amp; was'
            . ' rejected: &quot;part-0.parquet&quot;</Message></Error>'));

        $this->assertSame(
            'The requested URI does not represent any resource on the server & was rejected: "part-0.parquet"',
            $error->message
        );
    }

    public function testErrorCodeHeaderWithoutBody(): void
    {
        $error = ErrorResponse::fromResponse(new Response(403, ['x-ms-error-code' => 'AuthorizationFailure']));

        $this->assertSame('AuthorizationFailure', $error->errorCode);
        $this->assertNull($error->message);
        $this->assertSame('HTTP 403 AuthorizationFailure', $error->summary());
    }

    public function testUnstructuredBodyIsShortenedToOneLine(): void
    {
        $body = "<html>\n<body>\n<h1>502 Bad Gateway</h1>\n"
            . str_repeat('<p>upstream unavailable</p> ', 50)
            . '</html>';

        $error = ErrorResponse::fromResponse(new Response(502, [], $body));

        $this->assertNull($error->errorCode);
        $this->assertStringStartsWith('<html> <body> <h1>502 Bad Gateway</h1>', (string) $error->message);
        $this->assertStringEndsWith('...', (string) $error->message);
        $this->assertLessThanOrEqual(503, strlen((string) $error->message));
    }

    public function testBodyThatIsNotUtf8IsShortenedByBytes(): void
    {
        $error = ErrorResponse::fromResponse(new Response(500, [], str_repeat("\xFF", 2000)));

        $this->assertSame(503, strlen((string) $error->message));
    }

    public function testSummaryLeavesOutAnErrorCodeTheMessageAlreadyNames(): void
    {
        $error = new ErrorResponse(400, 'DS_EXAMPLE_ERROR', 'DS_EXAMPLE_ERROR: something went wrong');

        $this->assertSame('HTTP 400: DS_EXAMPLE_ERROR: something went wrong', $error->summary());
    }

    public function testSummaryWithStatusOnly(): void
    {
        $this->assertSame('HTTP 502', (new ErrorResponse(502))->summary());
    }
}
