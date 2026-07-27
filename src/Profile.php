<?php

declare(strict_types=1);

namespace DeltaSharing;

use DeltaSharing\Exception\DeltaSharingException;

/**
 * A Delta Sharing profile holds the endpoint and credentials needed to talk
 * to a sharing server. Profiles are usually distributed as small JSON files
 * with a .share extension.
 */
final class Profile
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $bearerToken,
        public readonly ?string $expirationTime = null,
        public readonly int $shareCredentialsVersion = 1
    ) {
    }

    public static function fromFile(string $path): self
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new DeltaSharingException("Unable to read profile file: {$path}");
        }

        return self::fromJson($json);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new DeltaSharingException('Profile is not valid JSON');
        }

        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        $version = (int) ($data['shareCredentialsVersion'] ?? 1);
        if ($version > 1) {
            throw new DeltaSharingException(
                "Unsupported shareCredentialsVersion {$version}, this client supports version 1"
            );
        }

        foreach (['endpoint', 'bearerToken'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
                throw new DeltaSharingException("Profile is missing required field \"{$key}\"");
            }
        }

        return new self(
            rtrim($data['endpoint'], '/'),
            $data['bearerToken'],
            isset($data['expirationTime']) ? (string) $data['expirationTime'] : null,
            $version
        );
    }

    public function isExpired(): bool
    {
        if ($this->expirationTime === null) {
            return false;
        }

        $expiresAt = strtotime($this->expirationTime);

        return $expiresAt !== false && $expiresAt <= time();
    }
}
