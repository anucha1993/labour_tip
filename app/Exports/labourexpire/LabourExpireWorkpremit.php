<?php

namespace App\Exports\labourexpire;

class LabourExpireWorkpremit extends ExpiryExportBase
{
    protected function type(): string
    {
        return 'work';
    }

    protected function extraHeadings(): array
    {
        return ['เลขที่ใบอนุญาตทำงาน', 'วันที่ใบอนุญาตเริ่มต้น', 'วันที่ใบอนุญาตสิ้นสุด'];
    }

    protected function extraValues($row): array
    {
        return [
            (string) $row->labour_workpremit_number,
            fmt_date($row->labour_workpremit_date_start),
            fmt_date($row->labour_workpremit_date_end),
        ];
    }
}
