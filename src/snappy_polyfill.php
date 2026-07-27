<?php

// Global polyfills for the php snappy extension, used by the parquet reader
// when ext-snappy is not loaded. See DeltaSharing\Snappy for details.

if (!function_exists('snappy_uncompress')) {
    function snappy_uncompress(string $data): string|false
    {
        return \DeltaSharing\Snappy::uncompress($data);
    }
}

if (!function_exists('snappy_compress')) {
    function snappy_compress(string $data): string|false
    {
        return \DeltaSharing\Snappy::compress($data);
    }
}
