<?php

namespace App\Exports\ExportCustom;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Full labour list, optionally filtered by company and working status.
 */
class LabourExportCustom implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    private int $no = 0;

    public function __construct(private $company, private string $status)
    {
    }

    public function collection()
    {
        return DB::table('labour')
            ->leftJoin('company', 'company.company_id', '=', 'labour.company_id')
            ->leftJoin('agency', 'agency.agency_id', '=', 'labour.labour_agency')
            ->leftJoin('nationality', 'nationality.code', '=', 'labour.labour_nationality')
            ->select('labour.*', 'company.company_name', 'company.company_contact_sale', 'agency.agency_name', 'nationality.name_th')
            ->when($this->company !== 'ALL', fn ($q) => $q->where('labour.company_id', (int) $this->company))
            ->when($this->status !== 'ALL', fn ($q) => $q->where('labour.labour_status_job', $this->status))
            ->orderBy('company.company_name')
            ->orderBy('labour.labour_fullname')
            ->get();
    }

    public function headings(): array
    {
        return [
            'ลำดับ',
            'รหัสพนักงาน',
            'คำนำหน้า',
            'ชื่อ-สกุล',
            'เพศ',
            'สัญชาติ',
            'เอเจนซี่',
            'บริษัท',
            'วันเกิด',
            'เลขที่หนังสือเดินทาง',
            'วันที่ออกเล่ม',
            'วันหมดอายุหนังสือเดินทาง',
            'เลขที่วีซ่า',
            'วันที่เดินทางเข้ามา',
            'วันที่เริ่มวีซ่า',
            'วันหมดอายุวีซ่า',
            'เลขที่ใบอนุญาตทำงาน',
            'รหัสพนักงาน (ถ้ามี)',
            'วันที่ใบอนุญาตเริ่มต้น',
            'วันที่ใบอนุญาตสิ้นสุด',
            'วันที่รายงานตัว 90 วัน เริ่มต้น',
            'วันที่รายงานตัว 90 วัน สิ้นสุด',
            'เลขที่ ตม.',
            'วันที่เริ่มเข้าทำงาน',
            'สถานะแรงงาน',
            'วันที่ลาออก',
            'วันที่หลบหนี',
            'สถานะในระบบ',
            'ผู้ประสานงานการตลาด',
            'หมายเหตุ',
        ];
    }

    public function map($row): array
    {
        return [
            ++$this->no,
            $row->labour_number,
            $row->labour_prefix,
            $row->labour_fullname,
            sex_label($row->labour_sex),
            $row->name_th ?? $row->labour_nationality,
            $row->agency_name,
            $row->company_name,
            fmt_date($row->labour_birthday),
            (string) $row->labour_passport_number,
            fmt_date($row->labour_passport_date_start),
            fmt_date($row->labour_passport_date_end),
            $row->labour_visa_number,
            fmt_date($row->labour_visa_date_in),
            fmt_date($row->labour_visa_date_start),
            fmt_date($row->labour_visa_date_end),
            (string) $row->labour_workpremit_number,
            $row->labour_labour_number,
            fmt_date($row->labour_workpremit_date_start),
            fmt_date($row->labour_workpremit_date_end),
            fmt_date($row->labour_day90_date_start),
            fmt_date($row->labour_day90_date_end),
            $row->labour_tm_number,
            fmt_date($row->labour_jobdate_start),
            status_job_label($row->labour_status_job),
            fmt_date($row->labour_resing_date),
            fmt_date($row->labour_escape_date),
            enable_label($row->labour_status),
            $row->company_contact_sale,
            $row->labour_note,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
