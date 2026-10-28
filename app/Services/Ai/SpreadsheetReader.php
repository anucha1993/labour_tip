<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Turns an attached Excel/CSV file into a compact text table the chat model can work with
 * (e.g. "update these workers from this sheet"). Dates come out as Y-m-d.
 */
class SpreadsheetReader
{
    public const MAX_ROWS = 200;
    public const MAX_COLS = 25;
    public const MAX_CHARS = 24000;

    public static function supports(string $ext): bool
    {
        return in_array(strtolower($ext), ['xlsx', 'xls', 'csv'], true);
    }

    /** @return array{sheets:int, rows:int, columns:int, truncated:bool, text:string} */
    public function read(string $disk, string $path): array
    {
        $full = Storage::disk($disk)->path($path);
        if (! is_file($full)) {
            throw new RuntimeException('ไม่พบไฟล์ตาราง');
        }

        try {
            $reader = IOFactory::createReaderForFile($full);
            // keep number formats: that is how date cells are recognised (read-data-only would drop them)
            $book = $reader->load($full);
        } catch (\Throwable $e) {
            throw new RuntimeException('เปิดไฟล์ตารางไม่สำเร็จ: ' . $e->getMessage());
        }

        $text = '';
        $rowsTotal = 0;
        $colsMax = 0;
        $truncated = false;
        $sheets = 0;

        foreach ($book->getWorksheetIterator() as $sheet) {
            $sheets++;
            $maxRow = min($sheet->getHighestDataRow(), self::MAX_ROWS + 1);
            $maxCol = min(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), self::MAX_COLS);
            if ($sheet->getHighestDataRow() > self::MAX_ROWS + 1) {
                $truncated = true;
            }
            $text .= "### ชีต: {$sheet->getTitle()} (" . ($sheet->getHighestDataRow() - 1) . " แถวข้อมูล)\n";
            for ($r = 1; $r <= $maxRow; $r++) {
                $cells = [];
                $empty = true;
                for ($c = 1; $c <= $maxCol; $c++) {
                    $cell = $sheet->getCellByColumnAndRow($c, $r);
                    $v = $this->value($cell);
                    if ($v !== '') {
                        $empty = false;
                    }
                    $cells[] = $v;
                }
                if ($empty) {
                    continue;
                }
                $colsMax = max($colsMax, count($cells));
                $text .= ($r === 1 ? 'หัวตาราง: ' : "แถว {$r}: ") . implode(' | ', $cells) . "\n";
                if ($r > 1) {
                    $rowsTotal++;
                }
                if (strlen($text) > self::MAX_CHARS) {
                    $truncated = true;
                    break 2;
                }
            }
            $text .= "\n";
        }

        return ['sheets' => $sheets, 'rows' => $rowsTotal, 'columns' => $colsMax, 'truncated' => $truncated, 'text' => trim($text)];
    }

    private function value(Cell $cell): string
    {
        $v = $cell->getValue();
        if ($v === null || $v === '') {
            return '';
        }
        if (is_numeric($v) && ExcelDate::isDateTime($cell)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
            } catch (\Throwable $e) {
            }
        }
        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $v = $v->getPlainText();
        }

        return trim(str_replace(['|', "\r", "\n"], ['/', ' ', ' '], (string) $v));
    }
}
