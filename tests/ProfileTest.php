<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\Exception\DeltaSharingException;
use DeltaSharing\Profile;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    public function testFromArrayParsesAllFields(): void
    {
        $profile = Profile::fromArray([
            'shareCredentialsVersion' => 1,
            'endpoint' => 'https://sharing.example.com/delta-sharing/',
            'bearerToken' => 'token123',
            'expirationTime' => '2030-01-01T00:00:00Z',
        ]);

        $this->assertSame('https://sharing.example.com/delta-sharing', $profile->endpoint);
        $this->assertSame('token123', $profile->bearerToken);
        $this->assertSame('2030-01-01T00:00:00Z', $profile->expirationTime);
        $this->assertSame(1, $profile->shareCredentialsVersion);
        $this->assertFalse($profile->isExpired());
    }

    public function testFromFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'share');
        file_put_contents($path, json_encode([
            'shareCredentialsVersion' => 1,
            'endpoint' => 'https://sharing.example.com',
            'bearerToken' => 'abc',
        ]));

        try {
            $profile = Profile::fromFile($path);
            $this->assertSame('abc', $profile->bearerToken);
        } finally {
            unlink($path);
        }
    }

    public function testExpiredProfile(): void
    {
        $profile = Profile::fromArray([
            'endpoint' => 'https://sharing.example.com',
            'bearerToken' => 'abc',
            'expirationTime' => '2020-01-01T00:00:00Z',
        ]);

        $this->assertTrue($profile->isExpired());
    }

    public function testRejectsUnsupportedVersion(): void
    {
        $this->expectException(DeltaSharingException::class);
        $this->expectExceptionMessage('Unsupported shareCredentialsVersion 2');

        Profile::fromArray([
            'shareCredentialsVersion' => 2,
            'endpoint' => 'https://sharing.example.com',
            'bearerToken' => 'abc',
        ]);
    }

    public function testRejectsMissingEndpoint(): void
    {
        $this->expectException(DeltaSharingException::class);

        Profile::fromArray(['bearerToken' => 'abc']);
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(DeltaSharingException::class);

        Profile::fromJson('not json');
    }
}
