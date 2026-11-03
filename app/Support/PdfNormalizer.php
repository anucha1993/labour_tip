<?php

namespace App\Support;

use RuntimeException;

/**
 * Rewrites a PDF 1.5+ file (cross-reference streams + compressed object streams, typical for scans) into a
 * classic PDF 1.4 layout that the free FPDI parser can open: every object is written out plainly and indexed
 * by a normal xref table. Page content is copied byte for byte — nothing is re-rendered.
 */
class PdfNormalizer
{
    public static function toClassic(string $pdf): string
    {
        if (preg_match('~/Encrypt\s+\d+\s+\d+\s+R~', $pdf)) {
            throw new RuntimeException('ไฟล์ PDF นี้มีรหัสผ่าน/เข้ารหัสไว้');
        }

        // 1. plain objects, in file order (a later definition — incremental update — replaces an earlier one)
        $objects = [];
        $pos = 0;
        while (preg_match('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $pdf, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $end = self::objectEnd($pdf, $start);
            if ($end === null) {
                break;
            }
            $objects[(int) $m[1][0]] = [(int) $m[2][0], substr($pdf, $start, $end - $start)];
            $pos = $end + 6;
        }

        // 2. objects packed inside object streams (only those not written out plainly)
        foreach ($objects as $body) {
            if (! preg_match('~/Type\s*/ObjStm\b~', $body[1])) {
                continue;
            }
            $data = self::streamData($body[1]);
            if ($data === null || ! preg_match('~/N\s+(\d+)~', $body[1], $n) || ! preg_match('~/First\s+(\d+)~', $body[1], $first)) {
                continue;
            }
            $head = preg_split('/\s+/', trim(substr($data, 0, (int) $first[1])));
            $index = [];
            for ($i = 0; $i + 1 < count($head) && count($index) < (int) $n[1]; $i += 2) {
                $index[] = [(int) $head[$i], (int) $head[$i + 1]];
            }
            foreach ($index as $k => [$num, $offset]) {
                $from = (int) $first[1] + $offset;
                $to = isset($index[$k + 1]) ? (int) $first[1] + $index[$k + 1][1] : strlen($data);
                $objects[$num] ??= [0, "\n" . trim(substr($data, $from, $to - $from)) . "\n"];
            }
        }

        // 3. drop the cross-reference / object streams themselves
        $objects = array_filter($objects, fn ($o) => ! preg_match('~/Type\s*/(XRef|ObjStm)\b~', self::dictOf($o[1])));

        $root = self::lastRef($pdf, 'Root');
        if (! $root || ! $objects) {
            throw new RuntimeException('อ่านโครงสร้างไฟล์ PDF ไม่ได้');
        }
        $info = self::lastRef($pdf, 'Info');

        // 4. write a classic file
        ksort($objects);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => [$gen, $body]) {
            $offsets[$num] = [strlen($out), $gen];
            $out .= "{$num} {$gen} obj{$body}endobj\n";
        }
        $size = max(array_keys($objects)) + 1;
        $xref = strlen($out);
        $out .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d %05d n \n", $offsets[$i][0], $offsets[$i][1]) : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size {$size} /Root {$root}" . ($info ? " /Info {$info}" : '') . " >>\nstartxref\n{$xref}\n%%EOF\n";

        return $out;
    }

    /** Offset of "endobj" for the object whose body starts at $start (skips over binary stream data). */
    private static function objectEnd(string $pdf, int $start): ?int
    {
        $endobj = strpos($pdf, 'endobj', $start);
        if ($endobj === false) {
            return null;
        }
        $stream = strpos($pdf, 'stream', $start);
        if ($stream === false || $stream > $endobj || substr($pdf, $stream - 3, 3) === 'end') {
            return $endobj;
        }
        $dataStart = $stream + 6 + (substr($pdf, $stream + 6, 2) === "\r\n" ? 2 : 1);
        if (preg_match('~/Length\s+(\d+)(?!\s+\d+\s+R)~', substr($pdf, $start, $stream - $start), $len)) {
            $after = $dataStart + (int) $len[1];
            if (preg_match('/\G\s*endstream/', $pdf, $x, 0, $after)) {
                return strpos($pdf, 'endobj', $after) ?: null;
            }
        }
        $endstream = strpos($pdf, 'endstream', $dataStart);

        return $endstream === false ? null : (strpos($pdf, 'endobj', $endstream) ?: null);
    }

    private static function dictOf(string $body): string
    {
        $s = strpos($body, 'stream');

        return $s === false ? $body : substr($body, 0, $s);
    }

    /** Decoded data of a FlateDecode stream object body (no predictors — object streams don't use them). */
    private static function streamData(string $body): ?string
    {
        $s = strpos($body, 'stream');
        $e = strrpos($body, 'endstream');
        if ($s === false || $e === false) {
            return null;
        }
        $s += 6 + (substr($body, $s + 6, 2) === "\r\n" ? 2 : 1);
        $raw = rtrim(substr($body, $s, $e - $s), "\r\n");
        if (! preg_match('~/Filter\s*/FlateDecode~', substr($body, 0, $s))) {
            return $raw;
        }
        $data = @gzuncompress($raw);
        if ($data === false) {
            $data = @gzinflate(substr($raw, 2));
        }

        return $data === false ? null : $data;
    }

    /** Last "/Name N G R" in the file (trailer or cross-reference stream dictionary). */
    private static function lastRef(string $pdf, string $name): ?string
    {
        return preg_match_all('~/' . $name . '\s+(\d+\s+\d+\s+R)~', $pdf, $m) ? end($m[1]) : null;
    }
}
