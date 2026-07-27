<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\Snappy;
use PHPUnit\Framework\TestCase;

final class SnappyTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $samples = [
            '',
            'a',
            'hello world',
            str_repeat('abc', 10000),
            random_bytes(70000),
        ];

        foreach ($samples as $sample) {
            $compressed = Snappy::compress($sample);
            $this->assertNotFalse($compressed);
            $this->assertSame($sample, Snappy::uncompress($compressed));
        }
    }

    public function testDecodesCopyWithOneByteOffset(): void
    {
        // Literal "a" followed by a 7 byte copy at offset 1 expands to 8 a's.
        $stream = "\x08\x00a\x0d\x01";

        $this->assertSame('aaaaaaaa', Snappy::uncompress($stream));
    }

    public function testDecodesOverlappingCopy(): void
    {
        // Literal "ab" followed by a 6 byte copy at offset 2.
        $stream = "\x08\x04ab" . chr(((6 - 4) << 2) | 1) . "\x02";

        $this->assertSame('abababab', Snappy::uncompress($stream));
    }

    public function testRejectsTruncatedInput(): void
    {
        $this->assertFalse(Snappy::uncompress("\x08\x00"));
        $this->assertFalse(Snappy::uncompress(''));
    }

    public function testRejectsInvalidOffset(): void
    {
        // Copy back 5 bytes when only 1 byte has been produced.
        $stream = "\x08\x00a\x0d\x05";

        $this->assertFalse(Snappy::uncompress($stream));
    }

    public function testPolyfillIsRegistered(): void
    {
        $this->assertTrue(function_exists('snappy_uncompress'));
        $this->assertTrue(function_exists('snappy_compress'));
    }
}
