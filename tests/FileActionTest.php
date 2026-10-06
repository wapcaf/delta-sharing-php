<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\Model\FileAction;
use PHPUnit\Framework\TestCase;

final class FileActionTest extends TestCase
{
    private function file(?int $expirationTimestamp): FileAction
    {
        return FileAction::fromArray('file', array_filter([
            'url' => 'https://blob.example.com/part-0.parquet?sig=abc',
            'id' => 'file-1',
            'expirationTimestamp' => $expirationTimestamp,
        ], fn ($value) => $value !== null));
    }

    public function testParsesExpirationTimestamp(): void
    {
        $this->assertSame(1791100800000, $this->file(1791100800000)->expirationTimestamp);
    }

    public function testIsExpired(): void
    {
        $nowMs = (int) (microtime(true) * 1000);

        $this->assertTrue($this->file($nowMs - 1000)->isExpired());
        $this->assertFalse($this->file($nowMs + 60000)->isExpired());
    }

    public function testWithoutExpirationTimestampIsNeverExpired(): void
    {
        $this->assertFalse($this->file(null)->isExpired());
    }
}
