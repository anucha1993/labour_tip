<?php

namespace App\Exports\labourexpire;

class LabourExpireDay90 extends ExpiryExportBase
{
    protected function type(): string
    {
        return 'ninety';
    }

    protected function extraHeadings(): array
    {
        return ['วันที่รายงานตัว 90 วัน เริ่มต้น', 'วันที่รายงานตัว 90 วัน สิ้นสุด'];
    }

    protected function extraValues($row): array
    {
        return [
            fmt_date($row->labour_day90_date_start),
            fmt_date($row->labour_day90_date_end),
        ];
    }
}
