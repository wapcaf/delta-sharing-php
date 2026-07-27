<?php

declare(strict_types=1);

namespace DeltaSharing;

use DeltaSharing\Exception\DeltaSharingException;
use DeltaSharing\Model\Table;

/**
 * Parses table URLs of the form used by the other Delta Sharing connectors:
 *
 *   <path-to-profile-file>#<share>.<schema>.<table>
 */
final class TablePath
{
    public function __construct(
        public readonly string $profilePath,
        public readonly Table $table
    ) {
    }

    public static function parse(string $url): self
    {
        $hashPos = strrpos($url, '#');
        if ($hashPos === false) {
            throw new DeltaSharingException(
                "Invalid table URL \"{$url}\", expected the form /path/to/profile.share#share.schema.table"
            );
        }

        $profilePath = substr($url, 0, $hashPos);
        $tableName = substr($url, $hashPos + 1);

        if ($profilePath === '') {
            throw new DeltaSharingException("Invalid table URL \"{$url}\", the profile path is empty");
        }

        return new self($profilePath, Table::fromFullyQualifiedName($tableName));
    }
}
