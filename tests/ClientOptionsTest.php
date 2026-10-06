<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\ClientOptions;
use DeltaSharing\DeltaSharing;
use DeltaSharing\DeltaSharingClient;
use DeltaSharing\Exception\DeltaSharingException;
use DeltaSharing\Profile;
use DeltaSharing\RestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientOptionsTest extends TestCase
{
    private function profile(): Profile
    {
        return Profile::fromArray([
            'endpoint' => 'https://sharing.example.com/delta-sharing',
            'bearerToken' => 'test-token',
        ]);
    }

    public function testDefaults(): void
    {
        $options = new ClientOptions();

        $this->assertSame(120.0, $options->timeout);
        $this->assertSame(30.0, $options->connectTimeout);
        $this->assertSame(300.0, $options->downloadTimeout);
        $this->assertSame(4, $options->maxRetries);
        $this->assertSame(1, $options->maxTimeoutRetries);
    }

    public function testAcceptsWholeSeconds(): void
    {
        $options = new ClientOptions(timeout: 300, downloadTimeout: 900);

        $this->assertSame(300.0, $options->timeout);
        $this->assertSame(900.0, $options->downloadTimeout);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function optionNames(): array
    {
        return [
            'timeout' => ['timeout'],
            'connectTimeout' => ['connectTimeout'],
            'downloadTimeout' => ['downloadTimeout'],
            'maxRetries' => ['maxRetries'],
            'maxTimeoutRetries' => ['maxTimeoutRetries'],
        ];
    }

    #[DataProvider('optionNames')]
    public function testRejectsNegativeValues(string $name): void
    {
        $this->expectException(DeltaSharingException::class);
        $this->expectExceptionMessage("Client option {$name} must not be negative");

        new ClientOptions(...[$name => -1]);
    }

    public function testRestClientFallsBackToDefaultOptions(): void
    {
        $this->assertEquals(new ClientOptions(), (new RestClient($this->profile()))->options());
    }

    public function testEntryPointsPassOptionsThrough(): void
    {
        $options = new ClientOptions(timeout: 300);
        $path = tempnam(sys_get_temp_dir(), 'share');
        file_put_contents($path, json_encode([
            'endpoint' => 'https://sharing.example.com/delta-sharing',
            'bearerToken' => 'test-token',
        ]));

        try {
            $this->assertSame($options, DeltaSharingClient::fromProfile($this->profile(), $options)->rest()->options());
            $this->assertSame($options, DeltaSharingClient::fromProfileFile($path, $options)->rest()->options());
            $this->assertSame($options, DeltaSharing::client($path, $options)->rest()->options());
        } finally {
            unlink($path);
        }
    }
}
