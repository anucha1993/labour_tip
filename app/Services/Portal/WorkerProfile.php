<?php

namespace App\Services\Portal;

use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use App\Services\ExpiryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Builds the JSON the worker portal shows to a labour: identity, employer, document
 * dates with days remaining, alerts and the list of downloadable files.
 */
class WorkerProfile
{
    public const NATIONALITY_EN = [
        'myanmar' => 'Myanmar',
        'cambodia' => 'Cambodia',
        'lao' => 'Laos',
        'vietnam' => 'Vietnam',
    ];

    public const STATUS_JOB_EN = [
        'job' => 'Employed',
        'resign' => 'Resigned',
        'escape' => 'Absconded',
    ];

    public const SECTION_LABELS = [
        'passport' => ['th' => 'หนังสือเดินทาง', 'en' => 'Passport'],
        'visa' => ['th' => 'วีซ่า', 'en' => 'Visa'],
        'work_permit' => ['th' => 'ใบอนุญาตทำงาน', 'en' => 'Work Permit'],
        'ninety_day' => ['th' => 'รายงานตัว 90 วัน', 'en' => '90-Day Report'],
    ];

    /** Minutes a document download link stays valid. */
    public const LINK_MINUTES = 15;

    public function __construct(private ExpiryService $expiry)
    {
    }

    public function make(LabourModel $labour): array
    {
        $labour->loadMissing(['company', 'agency']);
        $thresholds = $this->expiry->thresholds();

        $statusJob = (string) $labour->labour_status_job;

        $sections = [
            'passport' => $this->dates($labour->labour_passport_date_start, $labour->labour_passport_date_end, $thresholds['passport'] ?? 90),
            'visa' => $this->dates($labour->labour_visa_date_start, $labour->labour_visa_date_end, $thresholds['visa'] ?? 90),
            'work_permit' => $this->dates($labour->labour_workpremit_date_start, $labour->labour_workpremit_date_end, $thresholds['work'] ?? 30),
            'ninety_day' => $this->dates($labour->labour_day90_date_start, $labour->labour_day90_date_end, $thresholds['ninety'] ?? 15),
        ];

        return [
            'id' => (int) $labour->labour_id,
            'passport_number' => (string) $labour->labour_passport_number,
            'prefix' => (string) $labour->labour_prefix,
            'name' => trim((string) $labour->labour_fullname),
            'full_name' => trim(trim((string) $labour->labour_prefix) . ' ' . trim((string) $labour->labour_fullname)),
            'sex' => (string) $labour->labour_sex,
            'sex_label' => sex_label($labour->labour_sex),
            'birthday' => $this->iso($labour->labour_birthday),
            'birthday_text' => fmt_date($labour->labour_birthday),
            'age_text' => age_text($labour->labour_birthday),
            'labour_number' => (string) $labour->labour_number,
            'nationality' => $this->nationality($labour->labour_nationality),
            'status' => [
                'enabled' => $labour->labour_status === 'enable',
                'job' => $statusJob,
                'active' => $statusJob === 'job' && $labour->labour_status === 'enable',
                'label_th' => status_job_label($statusJob),
                'label_en' => self::STATUS_JOB_EN[$statusJob] ?? ($statusJob ?: '-'),
                'job_date_start' => $this->iso($labour->labour_jobdate_start),
                'job_date_start_text' => fmt_date($labour->labour_jobdate_start),
                'resign_date' => $this->iso($labour->labour_resing_date),
                'escape_date' => $this->iso($labour->labour_escape_date),
            ],
            'employer' => $this->employer($labour),
            'agency' => $labour->agency ? ['id' => (int) $labour->agency->agency_id, 'name' => (string) $labour->agency->agency_name] : null,
            'passport' => ['number' => (string) $labour->labour_passport_number] + $sections['passport'],
            'visa' => [
                'number' => (string) $labour->labour_visa_number,
                'date_in' => $this->iso($labour->labour_visa_date_in),
                'date_in_text' => fmt_date($labour->labour_visa_date_in),
            ] + $sections['visa'],
            'work_permit' => [
                'number' => (string) $labour->labour_workpremit_number,
                'labour_number' => (string) $labour->labour_labour_number,
            ] + $sections['work_permit'],
            'ninety_day' => ['tm_number' => (string) $labour->labour_tm_number] + $sections['ninety_day'],
            'alerts' => $this->alerts($sections),
            'documents' => $this->documents($labour),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** Short-lived link for one document. Returns null when the key does not belong to this labour. */
    public function documentLink(LabourModel $labour, string $key): ?array
    {
        $doc = self::findDocument($labour, $key);
        if (! $doc) {
            return null;
        }
        $expires = now()->addMinutes(self::LINK_MINUTES);

        return [
            'key' => $key,
            'name' => (string) $doc->name,
            'file_name' => $doc->fileName(),
            'url' => URL::temporarySignedRoute('portal.file', $expires, ['labourModel' => $labour->labour_id, 'key' => $key]),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    public static function documentKey(LabourDocument $doc): string
    {
        return $doc->id ? (string) $doc->id : 'legacy-' . $doc->legacy_column;
    }

    public static function findDocument(LabourModel $labour, string $key): ?LabourDocument
    {
        foreach ($labour->documentList() as $doc) {
            if (self::documentKey($doc) === $key) {
                return $doc;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ helpers

    private function dates($start, $end, int $warnDays): array
    {
        return [
            'date_start' => $this->iso($start),
            'date_start_text' => fmt_date($start),
            'date_end' => $this->iso($end),
            'date_end_text' => fmt_date($end),
            'days_left' => days_left($end),
            'level' => days_left_level($end, $warnDays),
            'warn_days' => $warnDays,
        ];
    }

    private function alerts(array $sections): array
    {
        $out = [];
        foreach ($sections as $type => $s) {
            if ($s['days_left'] === null || $s['days_left'] > $s['warn_days']) {
                continue;
            }
            $out[] = [
                'type' => $type,
                'label_th' => self::SECTION_LABELS[$type]['th'],
                'label_en' => self::SECTION_LABELS[$type]['en'],
                'date_end' => $s['date_end'],
                'date_end_text' => $s['date_end_text'],
                'days_left' => $s['days_left'],
                'level' => $s['level'],
            ];
        }
        usort($out, fn ($a, $b) => $a['days_left'] <=> $b['days_left']);

        return $out;
    }

    private function documents(LabourModel $labour): array
    {
        return $labour->documentList()->map(function (LabourDocument $doc) {
            return [
                'key' => self::documentKey($doc),
                'name' => (string) $doc->name,
                'file_name' => $doc->fileName(),
                'ext' => $doc->extension(),
                'is_image' => $doc->isImage(),
                'size' => (int) $doc->size,
                'size_text' => $doc->humanSize(),
                'updated_at' => optional($doc->updated_at)->toIso8601String(),
            ];
        })->values()->all();
    }

    private function employer(LabourModel $labour): ?array
    {
        $c = $labour->company;
        if (! $c) {
            return null;
        }

        $province = $c->company_province ? DB::table('provinces')->where('id', $c->company_province)->first() : null;
        $amphur = $c->company_amphur ? DB::table('amphures')->where('id', $c->company_amphur)->first() : null;
        $district = $c->company_district ? DB::table('districts')->where('id', $c->company_district)->first() : null;

        $isBkk = $province && str_contains((string) $province->name_th, 'กรุงเทพ');
        $th = array_filter([
            trim((string) $c->company_address),
            $district ? ($isBkk ? 'แขวง' : 'ต.') . $district->name_th : null,
            $amphur ? ($isBkk ? 'เขต' : 'อ.') . $amphur->name_th : null,
            $province ? ($isBkk ? '' : 'จ.') . $province->name_th : null,
            trim((string) $c->company_zipcode) ?: null,
        ]);
        $en = array_filter([
            trim((string) $c->company_address),
            $district->name_en ?? null,
            $amphur->name_en ?? null,
            $province->name_en ?? null,
            trim((string) $c->company_zipcode) ?: null,
        ]);

        return [
            'id' => (int) $c->company_id,
            'name' => (string) $c->company_name,
            'type' => (string) $c->company_type,
            'phone' => (string) $c->company_phone,
            'contact' => (string) $c->company_contact,
            'address' => [
                'line' => (string) $c->company_address,
                'district' => $district->name_th ?? null,
                'amphur' => $amphur->name_th ?? null,
                'province' => $province->name_th ?? null,
                'zipcode' => (string) $c->company_zipcode,
                'text_th' => implode(' ', $th),
                'text_en' => implode(', ', $en),
            ],
        ];
    }

    private function nationality($code): array
    {
        $code = (string) $code;
        $row = null;
        try {
            $row = $code !== '' ? DB::table('nationality')->where('code', $code)->first() : null;
        } catch (\Throwable $e) {
            // nationality table missing: fall back to the code
        }

        return [
            'code' => $code,
            'name_th' => $row->name_th ?? ($code ?: '-'),
            'name_en' => self::NATIONALITY_EN[$code] ?? ucfirst($code),
        ];
    }

    private function iso($value): ?string
    {
        if (is_blank_date($value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
