<?php

namespace App\Services;

use App\Models\settings\settingsModel;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "document expiring soon" logic.
 * Used by the dashboard, the sidebar badge and the Excel exports so they never disagree.
 */
class ExpiryService
{
    /** Map of notification type => labour date column. */
    public const TYPES = [
        'visa' => 'labour_visa_date_end',
        'passport' => 'labour_passport_date_end',
        'work' => 'labour_workpremit_date_end',
        'ninety' => 'labour_day90_date_end',
    ];

    public const LABELS = [
        'visa' => 'วีซ่า',
        'passport' => 'หนังสือเดินทาง',
        'work' => 'ใบอนุญาตทำงาน',
        'ninety' => 'รายงานตัว 90 วัน',
    ];

    /** Fallbacks when the settings table is missing a row. */
    private const DEFAULT_DAYS = [
        'visa' => 90,
        'passport' => 90,
        'work' => 30,
        'ninety' => 15,
    ];

    private ?array $thresholds = null;

    /**
     * Days-before-expiry for each type, read once per request.
     */
    public function thresholds(): array
    {
        if ($this->thresholds !== null) {
            return $this->thresholds;
        }

        $rows = settingsModel::query()->pluck('set_expire', 'set_name')->all();

        $this->thresholds = [];
        foreach (self::TYPES as $type => $column) {
            $value = $rows[$type] ?? null;
            $this->thresholds[$type] = is_numeric($value) && (int) $value >= 0 ? (int) $value : self::DEFAULT_DAYS[$type];
        }

        return $this->thresholds;
    }

    public function threshold(string $type): int
    {
        return $this->thresholds()[$type] ?? 0;
    }

    public function cutoff(string $type): string
    {
        return Carbon::today()->addDays($this->threshold($type))->toDateString();
    }

    /**
     * Base query for one notification type: working labours whose document expires on/before the cutoff.
     */
    public function query(string $type): Builder
    {
        $column = self::TYPES[$type];

        return DB::table('labour')
            ->leftJoin('company', 'company.company_id', '=', 'labour.company_id')
            ->select('labour.*', 'company.company_name', 'company.company_contact_sale', 'company.company_phone')
            ->where('labour.labour_status_job', 'job')
            ->whereNotNull('labour.' . $column)
            ->where('labour.' . $column, '<=', $this->cutoff($type))
            ->orderBy('labour.' . $column);
    }

    /**
     * Count per type using one query.
     */
    public function counts(): array
    {
        $selects = [];
        $bindings = [];
        foreach (self::TYPES as $type => $column) {
            $selects[] = "SUM(CASE WHEN `$column` IS NOT NULL AND `$column` <= ? THEN 1 ELSE 0 END) AS `$type`";
            $bindings[] = $this->cutoff($type);
        }

        $row = DB::table('labour')
            ->where('labour_status_job', 'job')
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();

        $counts = [];
        foreach (self::TYPES as $type => $column) {
            $counts[$type] = (int) ($row->{$type} ?? 0);
        }
        $counts['total'] = array_sum($counts);

        return $counts;
    }
}
