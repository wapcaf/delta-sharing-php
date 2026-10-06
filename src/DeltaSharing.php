<?php

declare(strict_types=1);

namespace DeltaSharing;

/**
 * Convenience entry points that mirror the top level helpers of the official
 * Python connector. Table URLs use the same format as the other connectors:
 *
 *   <path-to-profile-file>#<share>.<schema>.<table>
 */
final class DeltaSharing
{
    private function __construct()
    {
    }

    public static function client(string $profilePath, ?ClientOptions $options = null): DeltaSharingClient
    {
        return DeltaSharingClient::fromProfileFile($profilePath, $options);
    }

    /**
     * Loads a shared table as an array of associative rows.
     *
     * $rows = DeltaSharing::loadAsArray('open-datasets.share#delta_sharing.default.owid-covid-data', limit: 100);
     *
     * @param string[] $predicateHints
     * @return array<int, array<string, mixed>>
     */
    public static function loadAsArray(
        string $tableUrl,
        ?int $limit = null,
        array $predicateHints = [],
        ?int $version = null,
        ?ClientOptions $options = null
    ): array {
        return TableReader::forTableUrl($tableUrl, $options)->rows($limit, $predicateHints, $version);
    }

    /**
     * Lists the pre-signed parquet file URLs for a table without decoding
     * them. Useful when another system will do the actual download.
     *
     * @return Model\FileAction[]
     */
    public static function listFiles(string $tableUrl, ?int $limitHint = null, ?ClientOptions $options = null): array
    {
        return TableReader::forTableUrl($tableUrl, $options)->query([], $limitHint)->files;
    }
}
