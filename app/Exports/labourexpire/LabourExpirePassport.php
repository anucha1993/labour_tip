<?php

namespace App\Exports\labourexpire;

class LabourExpirePassport extends ExpiryExportBase
{
    protected function type(): string
    {
        return 'passport';
    }

    protected function extraHeadings(): array
    {
        return ['วันที่ออกหนังสือเดินทาง'];
    }

    protected function extraValues($row): array
    {
        return [fmt_date($row->labour_passport_date_start)];
    }
}
