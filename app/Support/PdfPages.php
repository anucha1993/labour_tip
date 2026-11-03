<?php

namespace App\Support;

use Mpdf\Mpdf;
use RuntimeException;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Split / merge PDFs without AI: copies whole pages into a new file (mPDF + FPDI), so the pages keep their
 * original quality and text. Images (JPG/PNG/WEBP) become one page each, fitted on A4.
 */
class PdfPages
{
    private Mpdf $pdf;

    private int $pages = 0;

    public function __construct()
    {
        $tmp = storage_path('app/mpdf-tmp');
        if (! is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }
        $this->pdf = new Mpdf(['tempDir' => $tmp, 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0]);
    }

    /** Number of pages of a PDF. */
    public static function count(string $bytes): int
    {
        return (new self())->open($bytes);
    }

    /**
     * "2" · "1-3" · "1,3,5-6" → [2] · [1,2,3] · [1,3,5,6] (in the order given, within 1..$max)
     *
     * @return int[]
     */
    public static function parseRange(string $spec, int $max): array
    {
        $out = [];
        foreach (preg_split('/\s*,\s*/', trim($spec)) as $part) {
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                $range = range((int) $m[1], (int) $m[2]);
            } elseif (ctype_digit($part)) {
                $range = [(int) $part];
            } else {
                throw new RuntimeException('รูปแบบเลขหน้าไม่ถูกต้อง: "' . $part . '" (ใช้ เช่น 2 หรือ 1-3 หรือ 1,3,5-6)');
            }
            foreach ($range as $n) {
                if ($n < 1 || $n > $max) {
                    throw new RuntimeException("ไม่มีหน้า {$n} (ไฟล์นี้มี {$max} หน้า)");
                }
                $out[] = $n;
            }
        }
        if (! $out) {
            throw new RuntimeException('ไม่ได้ระบุเลขหน้า');
        }

        return $out;
    }

    /** Append pages of a PDF (null = all pages). */
    public function addPdf(string $bytes, ?array $pages = null): self
    {
        $count = $this->open($bytes);
        foreach ($pages ?? range(1, $count) as $n) {
            $tpl = $this->pdf->importPage($n);
            $size = $this->pdf->getTemplateSize($tpl);
            // exact width × height as "P" (mPDF swaps the two for "L")
            $this->pdf->AddPageByArray(['orientation' => 'P', 'sheet-size' => [$size['width'], $size['height']]]);
            $this->pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
            $this->pages++;
        }

        return $this;
    }

    /** Append an image as one A4 page (fitted, centered, 10 mm margin). */
    public function addImage(string $bytes): self
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info) {
            throw new RuntimeException('อ่านไฟล์รูปไม่ได้');
        }
        [$w, $h] = $info;
        $landscape = $w > $h;
        [$pw, $ph] = $landscape ? [297, 210] : [210, 297];
        $scale = min(($pw - 20) / $w, ($ph - 20) / $h);
        [$iw, $ih] = [$w * $scale, $h * $scale];

        $this->pdf->AddPageByArray(['orientation' => $landscape ? 'L' : 'P', 'sheet-size' => 'A4']);
        $this->pdf->imageVars['img' . $this->pages] = $bytes;
        $this->pdf->Image('var:img' . $this->pages, ($pw - $iw) / 2, ($ph - $ih) / 2, $iw, $ih, '', '', true, false);
        $this->pages++;

        return $this;
    }

    public function pageCount(): int
    {
        return $this->pages;
    }

    public function output(): string
    {
        if (! $this->pages) {
            throw new RuntimeException('ไม่มีหน้าที่จะบันทึก');
        }

        return $this->pdf->Output('', 'S');
    }

    private function open(string $bytes): int
    {
        try {
            return $this->pdf->setSourceFile(StreamReader::createByString($bytes));
        } catch (\Throwable $e) {
            // PDF 1.5+ (compressed cross-reference) — the free FPDI parser needs the classic layout
            try {
                return $this->pdf->setSourceFile(StreamReader::createByString(PdfNormalizer::toClassic($bytes)));
            } catch (\Throwable $e2) {
                throw new RuntimeException('เปิดไฟล์ PDF ไม่ได้ (ไฟล์อาจมีรหัสผ่านหรือเสียหาย): ' . $e2->getMessage());
            }
        }
    }
}
