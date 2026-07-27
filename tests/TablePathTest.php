<?php

declare(strict_types=1);

namespace DeltaSharing\Tests;

use DeltaSharing\Exception\DeltaSharingException;
use DeltaSharing\Model\Table;
use DeltaSharing\TablePath;
use PHPUnit\Framework\TestCase;

final class TablePathTest extends TestCase
{
    public function testParsesTableUrl(): void
    {
        $path = TablePath::parse('C:\\data\\profile.share#my_share.my_schema.my_table');

        $this->assertSame('C:\\data\\profile.share', $path->profilePath);
        $this->assertSame('my_share', $path->table->share);
        $this->assertSame('my_schema', $path->table->schema);
        $this->assertSame('my_table', $path->table->name);
    }

    public function testRejectsUrlWithoutFragment(): void
    {
        $this->expectException(DeltaSharingException::class);

        TablePath::parse('C:\\data\\profile.share');
    }

    public function testRejectsMalformedTableName(): void
    {
        $this->expectException(DeltaSharingException::class);

        TablePath::parse('profile.share#share.table');
    }

    public function testFullyQualifiedNameRoundTrip(): void
    {
        $table = Table::fromFullyQualifiedName('a.b.c');

        $this->assertSame('a.b.c', $table->fullyQualifiedName());
    }
}
