<?php

declare(strict_types=1);

namespace DeltaSharing;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * The details of an HTTP error response, read from the shapes servers use:
 *
 * - Delta Sharing JSON: {"errorCode": "...", "message": "..."}
 * - Databricks JSON: {"error_code": "...", "message": "...", "details": [...]},
 *   where a google.rpc.ErrorInfo detail carries the specific reason
 * - Cloud storage XML (Azure Blob Storage, Amazon S3, Google Cloud Storage):
 *   <Error><Code>...</Code><Message>...</Message></Error>, with Azure also
 *   sending the code in an x-ms-error-code header
 *
 * Bodies in any other format are kept as the message, shortened.
 *
 * @internal
 */
final class ErrorResponse
{
    /**
     * Headers that carry the server's request id, in order of preference.
     */
    private const REQUEST_ID_HEADERS = ['x-request-id', 'x-ms-request-id', 'x-amz-request-id'];

    /**
     * Error bodies are small, but a failed download may stream something
     * else entirely, so only this much is read.
     */
    private const MAX_BODY_BYTES = 65536;

    private const MAX_MESSAGE_CHARS = 500;

    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
        public readonly ?string $requestId = null
    ) {
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $body = self::readBody($response);
        $requestId = self::firstHeader($response, self::REQUEST_ID_HEADERS);

        $json = json_decode($body, true);
        if (is_array($json)) {
            [$errorCode, $message] = self::fromJson($json);
        } elseif (preg_match('~<Error[\s>]~', $body) === 1) {
            // Matched anywhere, as Azure starts its XML with a byte order mark.
            [$errorCode, $message, $bodyRequestId] = self::fromXml($body);
            $requestId ??= $bodyRequestId;
        } else {
            [$errorCode, $message] = [null, null];
        }

        $errorCode ??= self::firstHeader($response, ['x-ms-error-code']);
        if ($message === null && trim($body) !== '') {
            $message = self::clean($body);
        }

        return new self($response->getStatusCode(), $errorCode, $message, $requestId);
    }

    /**
     * A one line description such as
     * "HTTP 400 FILES_API_AZURE_FORBIDDEN: Access to the storage container is
     * forbidden by Azure. (request id 0f6d...)". The error code is left out
     * when the message already contains it.
     */
    public function summary(): string
    {
        $summary = "HTTP {$this->statusCode}";

        if ($this->errorCode !== null && !str_contains($this->message ?? '', $this->errorCode)) {
            $summary .= " {$this->errorCode}";
        }
        if ($this->message !== null) {
            $summary .= ": {$this->message}";
        }
        if ($this->requestId !== null) {
            $summary .= " (request id {$this->requestId})";
        }

        return $summary;
    }

    /**
     * Whether the error says that the request or its signature has expired,
     * as storage services do for pre-signed urls past their expiry.
     */
    public function indicatesExpiry(): bool
    {
        return preg_match('/expir/i', "{$this->errorCode} {$this->message}") === 1;
    }

    private static function readBody(ResponseInterface $response): string
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return Utils::copyToString($body, self::MAX_BODY_BYTES);
    }

    /**
     * @param array<mixed> $data
     * @return array{0: ?string, 1: ?string}
     */
    private static function fromJson(array $data): array
    {
        $errorCode = self::stringOrNull($data['errorCode'] ?? $data['error_code'] ?? null);

        // A google.rpc.ErrorInfo reason is more specific than the error code,
        // which Databricks often sets to a generic value such as BAD_REQUEST.
        foreach (is_array($data['details'] ?? null) ? $data['details'] : [] as $detail) {
            if (
                is_array($detail)
                && str_ends_with((string) ($detail['@type'] ?? ''), 'google.rpc.ErrorInfo')
                && is_string($detail['reason'] ?? null)
            ) {
                $errorCode = $detail['reason'];
                break;
            }
        }

        $message = self::stringOrNull($data['message'] ?? null);

        return [$errorCode, $message === null ? null : self::clean($message)];
    }

    /**
     * Reads the flat error documents of cloud storage services. Only the
     * elements below are used: others, such as the SignatureProvided element
     * of Amazon S3, can echo the url's signature.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private static function fromXml(string $body): array
    {
        $errorCode = self::xmlElement($body, 'Code');
        $message = self::xmlElement($body, 'Message');
        $requestId = null;

        // Azure ends every message with "RequestId:<id> Time:<timestamp>".
        // The id is reported separately, so it is lifted out of the text.
        if ($message !== null && preg_match('/\s*RequestId:(\S+)\s+Time:\S+$/', $message, $match) === 1) {
            $requestId = $match[1];
            $message = substr($message, 0, -strlen($match[0]));
        }

        // Azure and Google Cloud Storage explain authentication failures,
        // expiry included, in a separate element.
        $detail = self::xmlElement($body, 'AuthenticationErrorDetail') ?? self::xmlElement($body, 'Details');
        if ($detail !== null) {
            $message = $message === null ? $detail : "{$message} {$detail}";
        }

        return [$errorCode, $message === null ? null : self::clean($message), $requestId];
    }

    private static function xmlElement(string $xml, string $name): ?string
    {
        if (preg_match("~<{$name}>(.*?)</{$name}>~s", $xml, $match) !== 1) {
            return null;
        }

        $text = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));

        return $text === '' ? null : self::clean($text);
    }

    /**
     * @param string[] $names
     */
    private static function firstHeader(ResponseInterface $response, array $names): ?string
    {
        foreach ($names as $name) {
            $value = trim($response->getHeaderLine($name));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Collapses whitespace so the text fits on one line, and shortens it to
     * MAX_MESSAGE_CHARS characters. Text that is not valid UTF-8 is cut by
     * bytes instead.
     */
    private static function clean(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        $longer = preg_match('/^.{' . self::MAX_MESSAGE_CHARS . '}(?=.)/su', $text, $match);
        if ($longer === 1) {
            return rtrim($match[0]) . '...';
        }
        if ($longer === false && strlen($text) > self::MAX_MESSAGE_CHARS) {
            return rtrim(substr($text, 0, self::MAX_MESSAGE_CHARS)) . '...';
        }

        return $text;
    }
}
