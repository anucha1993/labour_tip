<?php

namespace App\Imports\labour;

use App\Models\labour\LabourModel;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ImportLabour implements ToModel, WithHeadingRow
{
    public int $imported = 0;
    public int $skipped = 0;
    /** @var string[] */
    public array $skippedReasons = [];

    /** Passport numbers seen in this file, to catch duplicates inside the sheet itself. */
    private array $seen = [];

    public function __construct(
        private int $company,
        private int $agency,
        private string $nationality,
        private string $createdBy
    ) {
    }

    public function model(array $row)
    {
        $passport = strtoupper(trim((string) ($row['labour_passport_number'] ?? '')));
        if ($passport === '') {
            return null; // blank line in the sheet
        }

        if (isset($this->seen[$passport])) {
            return $this->skip("{$passport} ซ้ำในไฟล์");
        }
        $this->seen[$passport] = true;

        if (LabourModel::where('labour_passport_number', $passport)->exists()) {
            return $this->skip("{$passport} มีในระบบแล้ว");
        }

        $fullname = trim((string) ($row['labour_fullname'] ?? ''));
        if ($fullname === '') {
            return $this->skip("{$passport} ไม่มีชื่อ-สกุล");
        }

        $this->imported++;

        return new LabourModel([
            'labour_prefix' => $this->prefix($row['labour_prefix'] ?? null),
            'labour_number' => $this->text($row['labour_number'] ?? null),
            'labour_fullname' => $fullname,
            'labour_sex' => $this->sex($row['labour_sex'] ?? null),
            'labour_nationality' => $this->nationality,
            'labour_agency' => $this->agency,
            'labour_birthday' => $this->date($row['labour_birthday'] ?? null),
            'company_id' => $this->company,
            'labour_passport_number' => $passport,
            'labour_passport_date_start' => $this->date($row['labour_passport_date_start'] ?? null),
            'labour_passport_date_end' => $this->date($row['labour_passport_date_end'] ?? null),
            'labour_visa_number' => $this->text($row['labour_visa_number'] ?? null),
            'labour_visa_date_in' => $this->date($row['labour_visa_date_in'] ?? null),
            'labour_visa_date_start' => $this->date($row['labour_visa_date_start'] ?? null),
            'labour_visa_date_end' => $this->date($row['labour_visa_date_end'] ?? null),
            'labour_workpremit_number' => $this->text($row['labour_workpremit_number'] ?? null),
            'labour_labour_number' => $this->text($row['labour_labour_number'] ?? null),
            'labour_workpremit_date_start' => $this->date($row['labour_workpremit_date_start'] ?? null),
            'labour_workpremit_date_end' => $this->date($row['labour_workpremit_date_end'] ?? null),
            'labour_day90_date_start' => $this->date($row['labour_day90_date_start'] ?? null),
            'labour_day90_date_end' => $this->date($row['labour_day90_date_end'] ?? null),
            'labour_tm_number' => $this->text($row['labour_tm_number'] ?? null),
            'labour_status' => $this->enum($row['labour_status'] ?? null, ['enable', 'disable'], 'enable'),
            'labour_jobdate_start' => $this->date($row['labour_jobdate_start'] ?? null),
            'labour_status_job' => $this->enum($row['labour_status_job'] ?? null, ['job', 'resign', 'escape'], 'job'),
            'labour_note' => $this->text($row['labour_note'] ?? null),
            'created_by' => $this->createdBy,
        ]);
    }

    // ------------------------------------------------------------------ cell normalisers

    private function skip(string $reason)
    {
        $this->skipped++;
        $this->skippedReasons[] = $reason;

        return null;
    }

    private function text($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Accept Excel serial numbers, ISO strings and d/m/Y strings. Blank cells become NULL
     * instead of 1899-12-30.
     */
    private function date($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_numeric($value)) {
                $n = (float) $value;
                if ($n <= 0) {
                    return null;
                }

                return ExcelDate::excelToDateTimeObject($n)->format('Y-m-d');
            }
            $value = trim((string) $value);
            if (preg_match('~^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$~', $value, $m)) {
                $year = (int) $m[3];
                if ($year > 2400) {
                    $year -= 543; // Buddhist calendar year
                }

                return Carbon::createFromDate($year, (int) $m[2], (int) $m[1])->format('Y-m-d');
            }

            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function prefix($value): string
    {
        $v = strtolower(trim((string) $value));
        $v = rtrim($v, '.');

        return [
            'mr' => 'Mr', 'นาย' => 'Mr',
            'ms' => 'Ms', 'miss' => 'Ms', 'นางสาว' => 'Ms', 'น.ส' => 'Ms',
            'mrs' => 'Mrs', 'นาง' => 'Mrs',
        ][$v] ?? 'Mr';
    }

    private function sex($value): string
    {
        $v = strtolower(trim((string) $value));

        return in_array($v, ['female', 'f', 'หญิง', 'woman', 'ms', 'mrs'], true) ? 'female' : 'male';
    }

    private function enum($value, array $allowed, string $default): string
    {
        $v = strtolower(trim((string) $value));

        return in_array($v, $allowed, true) ? $v : $default;
    }
}
