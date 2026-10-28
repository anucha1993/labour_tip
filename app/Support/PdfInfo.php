<?php

namespace App\Support;

/**
 * Page count of a PDF without a parser library: counts /Type /Page objects in the raw file
 * and inside FlateDecode object streams (PDF 1.5+ keeps page objects compressed there).
 */
class PdfInfo
{
    public static function pageCount(string $bytes): ?int
    {
        $count = self::countPages($bytes);

        // Object streams: inflate every FlateDecode stream that declares /Type /ObjStm.
        if (preg_match_all('~/ObjStm(.{0,300}?)stream\r?\n~s', $bytes, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$match, $offset]) {
                $start = $offset + strlen($match);
                $end = strpos($bytes, 'endstream', $start);
                if ($end === false) {
                    continue;
                }
                $raw = substr($bytes, $start, $end - $start);
                $inflated = @gzuncompress($raw);
                if ($inflated === false) {
                    $inflated = @gzinflate(substr($raw, 2)); // zlib header stripped
                }
                if (is_string($inflated)) {
                    $count += self::countPages($inflated);
                }
            }
        }

        // Fallback: the page tree root carries /Count.
        if ($count === 0 && preg_match_all('~/Type\s*/Pages\b[^>]*?/Count\s+(\d+)~s', $bytes, $c)) {
            $count = (int) max($c[1]);
        }

        return $count > 0 ? $count : null;
    }

    private static function countPages(string $s): int
    {
        return preg_match_all('~/Type\s*/Page(?![a-zA-Z])~', $s);
    }
}
