<?php

namespace App\Exports;

use App\Services\Ai\LabourQueryService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel report built by the AI assistant: any whitelisted columns, any validated filter.
 */
class AiLabourExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle
{
    private array $columns;
    private int $i = 0;

    public function __construct(private Collection $rows, array $columns, private string $title = 'รายงานแรงงาน')
    {
        $all = LabourQueryService::columns();
        $this->columns = array_values(array_filter($columns, fn ($c) => isset($all[$c])));
        if (! in_array('no', $this->columns, true)) {
            array_unshift($this->columns, 'no');
        }
    }

    public function collection()
    {
        return $this->rows;
    }

    public function headings(): array
    {
        $all = LabourQueryService::columns();

        return array_map(fn ($c) => $all[$c][0], $this->columns);
    }

    public function map($row): array
    {
        $all = LabourQueryService::columns();
        $i = $this->i++;

        return array_map(fn ($c) => $all[$c][1]($row, $i), $this->columns);
    }

    public function styles(Worksheet $sheet)
    {
        return [1 => ['font' => ['bold' => true]]];
    }

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $this->title), 0, 31) ?: 'Report';
    }

    public function columnKeys(): array
    {
        return $this->columns;
    }
}
