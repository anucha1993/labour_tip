<?php

namespace App\Exports\labourexpire;

use App\Services\ExpiryService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Shared "documents expiring soon" export. Subclasses only declare the type and the extra columns.
 */
abstract class ExpiryExportBase implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected int $no = 0;

    /** One of ExpiryService::TYPES keys. */
    abstract protected function type(): string;

    /** Extra headings specific to the document type. */
    abstract protected function extraHeadings(): array;

    /** Extra values specific to the document type, same order as extraHeadings(). */
    abstract protected function extraValues($row): array;

    public function collection()
    {
        return app(ExpiryService::class)->query($this->type())->get();
    }

    public function headings(): array
    {
        return array_merge([
            'ลำดับ',
            'รหัสพนักงาน',
            'คำนำหน้า',
            'ชื่อ-สกุล',
            'นายจ้าง',
            'เลขที่หนังสือเดินทาง',
            'วันหมดอายุหนังสือเดินทาง',
        ], $this->extraHeadings(), [
            'จำนวนวันคงเหลือ',
            'สถานะการทำงาน',
            'ผู้ประสานงานการตลาด',
            'หมายเหตุ',
        ]);
    }

    public function map($row): array
    {
        $column = ExpiryService::TYPES[$this->type()];

        return array_merge([
            ++$this->no,
            $row->labour_number,
            $row->labour_prefix . '.',
            $row->labour_fullname,
            $row->company_name,
            (string) $row->labour_passport_number,
            fmt_date($row->labour_passport_date_end),
        ], $this->extraValues($row), [
            days_left($row->{$column}),
            status_job_label($row->labour_status_job),
            $row->company_contact_sale,
            $row->labour_note,
        ]);
    }

    public function styles(Worksheet $sheet)
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
