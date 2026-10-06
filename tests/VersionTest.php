<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\DeltaSharingClient;
use DeltaSharing\RestClient;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testVersionMatchesTheLatestChangelogRelease(): void
    {
        $changelog = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');

        $this->assertSame(1, preg_match('/^## (\d+\.\d+\.\d+)\s*$/m', $changelog, $match));
        $this->assertSame(
            $match[1],
            DeltaSharingClient::VERSION,
            'Bump DeltaSharingClient::VERSION together with the CHANGELOG'
        );
    }

    public function testUserAgentCarriesTheVersion(): void
    {
        $this->assertSame('delta-sharing-php/' . DeltaSharingClient::VERSION, RestClient::USER_AGENT);
    }
}
