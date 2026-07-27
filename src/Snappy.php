<?php

declare(strict_types=1);

namespace DeltaSharing;

/**
 * Pure PHP implementation of the raw Snappy block format. Parquet data files
 * are usually snappy compressed and the php snappy extension is hard to get
 * on some platforms, Windows in particular, so this keeps the connector
 * working without it. When ext-snappy is loaded it is used instead.
 *
 * Format reference: https://github.com/google/snappy/blob/main/format_description.txt
 */
final class Snappy
{
    private function __construct()
    {
    }

    public static function uncompress(string $data): string|false
    {
        $len = strlen($data);
        $pos = 0;

        $expected = 0;
        $shift = 0;
        while (true) {
            if ($pos >= $len) {
                return false;
            }
            $byte = ord($data[$pos++]);
            $expected |= ($byte & 0x7f) << $shift;
            if (($byte & 0x80) === 0) {
                break;
            }
            $shift += 7;
            if ($shift > 35) {
                return false;
            }
        }

        $out = '';
        while ($pos < $len) {
            $tag = ord($data[$pos++]);
            $type = $tag & 0x03;

            if ($type === 0) {
                $size = $tag >> 2;
                if ($size >= 60) {
                    $lengthBytes = $size - 59;
                    if ($pos + $lengthBytes > $len) {
                        return false;
                    }
                    $size = 0;
                    for ($i = 0; $i < $lengthBytes; $i++) {
                        $size |= ord($data[$pos + $i]) << (8 * $i);
                    }
                    $pos += $lengthBytes;
                }
                $size++;
                if ($pos + $size > $len) {
                    return false;
                }
                $out .= substr($data, $pos, $size);
                $pos += $size;
                continue;
            }

            if ($type === 1) {
                if ($pos >= $len) {
                    return false;
                }
                $size = (($tag >> 2) & 0x07) + 4;
                $offset = (($tag >> 5) << 8) | ord($data[$pos++]);
            } elseif ($type === 2) {
                if ($pos + 2 > $len) {
                    return false;
                }
                $size = ($tag >> 2) + 1;
                $offset = ord($data[$pos]) | (ord($data[$pos + 1]) << 8);
                $pos += 2;
            } else {
                if ($pos + 4 > $len) {
                    return false;
                }
                $size = ($tag >> 2) + 1;
                $offset = ord($data[$pos])
                    | (ord($data[$pos + 1]) << 8)
                    | (ord($data[$pos + 2]) << 16)
                    | (ord($data[$pos + 3]) << 24);
                $pos += 4;
            }

            $outLen = strlen($out);
            if ($offset === 0 || $offset > $outLen) {
                return false;
            }

            if ($offset >= $size) {
                $out .= substr($out, $outLen - $offset, $size);
            } else {
                // Overlapping copy, the pattern repeats itself.
                $pattern = substr($out, $outLen - $offset);
                $out .= substr(str_repeat($pattern, intdiv($size, $offset) + 1), 0, $size);
            }
        }

        return strlen($out) === $expected ? $out : false;
    }

    /**
     * Produces a valid snappy stream using literal elements only. Good enough
     * for the rare write path; real compression needs ext-snappy.
     */
    public static function compress(string $data): string|false
    {
        $len = strlen($data);

        $out = '';
        $remaining = $len;
        while (true) {
            $out .= chr(($remaining & 0x7f) | ($remaining > 0x7f ? 0x80 : 0));
            $remaining >>= 7;
            if ($remaining === 0) {
                break;
            }
        }

        $pos = 0;
        while ($pos < $len) {
            $chunk = min(65536, $len - $pos);
            $n = $chunk - 1;
            if ($n < 60) {
                $out .= chr($n << 2);
            } else {
                $out .= chr(61 << 2) . chr($n & 0xff) . chr(($n >> 8) & 0xff);
            }
            $out .= substr($data, $pos, $chunk);
            $pos += $chunk;
        }

        return $out;
    }
}
