<?php

namespace App\Exports\labourexpire;

class LabourExpireVisa extends ExpiryExportBase
{
    protected function type(): string
    {
        return 'visa';
    }

    protected function extraHeadings(): array
    {
        return ['เลขที่วีซ่า', 'วันที่เดินทางเข้ามา', 'วันที่เริ่มวีซ่า', 'วันที่หมดอายุวีซ่า'];
    }

    protected function extraValues($row): array
    {
        return [
            $row->labour_visa_number,
            fmt_date($row->labour_visa_date_in),
            fmt_date($row->labour_visa_date_start),
            fmt_date($row->labour_visa_date_end),
        ];
    }
}
