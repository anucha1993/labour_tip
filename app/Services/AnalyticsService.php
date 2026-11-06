<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numbers behind the "Dashboard" page (/dashboard): renewal workload, overdue documents, per-company
 * ranking, data completeness, workforce trend, demographics, agency escape rate, tenure and system use.
 *
 * Everything is plain SQL over the labour table, computed once per (company, months) and cached for
 * CACHE_MINUTES so the page stays fast however many workers there are.
 */
class AnalyticsService
{
    public const CACHE_MINUTES = 10;
    public const MONTH_OPTIONS = [6, 12, 24];

    public const TYPES = ExpiryService::TYPES;        // visa / passport / work / ninety => column
    public const LABELS = ExpiryService::LABELS;

    public function __construct(private ExpiryService $expiry)
    {
    }

    /** Everything the page needs. $company = 0 means all companies. */
    public function build(int $company, int $months, bool $withSystem): array
    {
        $months = in_array($months, self::MONTH_OPTIONS, true) ? $months : 12;
        $key = 'analytics:' . $company . ':' . $months . ':' . ($withSystem ? 1 : 0) . ':' . Carbon::today()->toDateString();

        return Cache::remember($key, self::CACHE_MINUTES * 60, function () use ($company, $months, $withSystem) {
            $data = [
                'generated_at' => now()->toDateTimeString(),
                'summary' => $this->summary($company),
                'timeline' => $this->timeline($company, $months),
                'overdue' => $this->overdue($company),
                'companies' => $company ? [] : $this->companies(),
                'completeness' => $this->completeness($company),
                'trend' => $this->trend($company, $months),
                'demographics' => $this->demographics($company),
                'agencies' => $this->agencies($company),
                'tenure' => $company ? [] : $this->tenure(),
            ];
            if ($withSystem) {
                $data['system'] = $this->system();
            }

            return $data;
        });
    }

    public static function forget(): void
    {
        // keys carry the date, so stale ones simply expire; nothing to do but keep the API symmetrical
    }

    // ------------------------------------------------------------------ pieces

    private function base(int $company): Builder
    {
        return DB::table('labour')->when($company, fn ($q) => $q->where('company_id', $company));
    }

    /** Head-line numbers. */
    public function summary(int $company): array
    {
        $row = $this->base($company)->selectRaw("
            COUNT(*) AS total,
            SUM(labour_status_job = 'job') AS job,
            SUM(labour_status_job = 'resign') AS resign,
            SUM(labour_status_job = 'escape') AS esc,
            SUM(labour_status_job = 'job' AND labour_jobdate_start >= ?) AS joined_30,
            SUM(labour_status_job = 'resign' AND labour_resing_date >= ?) AS resigned_30,
            SUM(labour_status_job = 'escape' AND labour_escape_date >= ?) AS escaped_30
        ", array_fill(0, 3, Carbon::today()->subDays(30)->toDateString()))->first();

        return [
            'total' => (int) $row->total,
            'job' => (int) $row->job,
            'resign' => (int) $row->resign,
            'escape' => (int) $row->esc,
            'joined_30' => (int) $row->joined_30,
            'resigned_30' => (int) $row->resigned_30,
            'escaped_30' => (int) $row->escaped_30,
            'companies' => $company ? 1 : (int) DB::table('labour')->where('labour_status_job', 'job')->distinct()->count('company_id'),
        ];
    }

    /** Renewals due per month for the next N months, per document type (working labours only). */
    public function timeline(int $company, int $months): array
    {
        $start = Carbon::today()->startOfMonth();
        $end = $start->copy()->addMonths($months)->subDay();
        $labels = [];
        $keys = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $start->copy()->addMonths($i);
            $keys[] = $m->format('Y-m');
            $labels[] = self::thaiMonth($m);
        }
        $series = [];
        foreach (self::TYPES as $type => $column) {
            $rows = $this->base($company)
                ->where('labour_status_job', 'job')
                ->whereNotNull($column)
                ->whereBetween($column, [$start->toDateString(), $end->toDateString()])
                ->selectRaw($this->monthExpr($column) . ' AS m, COUNT(*) AS c')
                ->groupBy('m')
                ->pluck('c', 'm');
            $series[$type] = array_map(fn ($k) => (int) ($rows[$k] ?? 0), $keys);
        }
        $totals = array_map(fn ($i) => array_sum(array_column($series, $i)), array_keys($keys));

        return ['keys' => $keys, 'labels' => $labels, 'series' => $series, 'totals' => $totals, 'peak' => $totals ? max($totals) : 0];
    }

    /** Documents already expired while the worker is still marked as working. */
    public function overdue(int $company): array
    {
        $today = Carbon::today()->toDateString();
        $selects = [];
        foreach (self::TYPES as $type => $column) {
            $selects[] = "SUM(`$column` IS NOT NULL AND `$column` < '$today') AS `$type`";
            $selects[] = "SUM(`$column` IS NOT NULL AND `$column` < '$today' AND `$column` >= '" . Carbon::today()->subDays(30)->toDateString() . "') AS `{$type}_30`";
        }
        $row = $this->base($company)->where('labour_status_job', 'job')->selectRaw(implode(', ', $selects))->first();
        $out = [];
        foreach (self::TYPES as $type => $column) {
            $out[$type] = ['count' => (int) $row->{$type}, 'last_30' => (int) $row->{$type . '_30'}, 'column' => $column];
        }
        $out['total'] = array_sum(array_column($out, 'count'));

        return $out;
    }

    /** Per-company ranking: working, expiring within thresholds, overdue, escaped, files per worker. */
    public function companies(): array
    {
        $today = Carbon::today()->toDateString();
        $soon = [];
        $over = [];
        foreach (self::TYPES as $type => $column) {
            $cut = $this->expiry->cutoff($type);
            $soon[] = "(labour_status_job = 'job' AND `$column` IS NOT NULL AND `$column` BETWEEN '$today' AND '$cut')";
            $over[] = "(labour_status_job = 'job' AND `$column` IS NOT NULL AND `$column` < '$today')";
        }
        $docsReady = Schema::hasTable('labour_documents');
        $rows = DB::table('labour')
            ->leftJoin('company', 'company.company_id', '=', 'labour.company_id')
            ->selectRaw("labour.company_id, COALESCE(company.company_name, 'ไม่ระบุบริษัท') AS name,
                SUM(labour_status_job = 'job') AS job,
                SUM(labour_status_job = 'escape') AS esc,
                SUM(labour_status_job = 'resign') AS resign,
                COUNT(*) AS total,
                SUM(" . implode(' OR ', $soon) . ") AS soon,
                SUM(" . implode(' OR ', $over) . ") AS overdue,
                SUM(labour_status_job = 'job' AND (labour_photo IS NULL OR labour_photo = '')) AS no_photo")
            ->groupBy('labour.company_id', 'company.company_name')
            ->having('job', '>', 0)
            ->orderByDesc('overdue')->orderByDesc('soon')->orderByDesc('job')
            ->limit(30)
            ->get();
        $files = $docsReady
            ? DB::table('labour_documents')->join('labour', 'labour.labour_id', '=', 'labour_documents.labour_id')
                ->selectRaw('labour.company_id, COUNT(*) AS c')->groupBy('labour.company_id')->pluck('c', 'company_id')
            : collect();

        return $rows->map(fn ($r) => [
            'id' => (int) $r->company_id,
            'name' => $r->name,
            'job' => (int) $r->job,
            'total' => (int) $r->total,
            'soon' => (int) $r->soon,
            'overdue' => (int) $r->overdue,
            'escape' => (int) $r->esc,
            'escape_rate' => $r->total ? round($r->esc * 100 / $r->total, 1) : 0,
            'files_per_worker' => $r->job ? round((int) ($files[$r->company_id] ?? 0) / $r->job, 1) : 0,
            'no_photo' => (int) $r->no_photo,
        ])->values()->all();
    }

    /** Share of working labours that have each piece of data / file. */
    public function completeness(int $company): array
    {
        $q = $this->base($company)->where('labour_status_job', 'job');
        $total = (clone $q)->count();
        if ($total === 0) {
            return ['total' => 0, 'items' => []];
        }
        $docsReady = Schema::hasTable('labour_documents');
        $checks = [
            'photo' => ['รูปโปรไฟล์', "labour_photo IS NOT NULL AND labour_photo <> ''"],
            'passport_no' => ['เลขพาสปอร์ต', "labour_passport_number IS NOT NULL AND labour_passport_number <> ''"],
            'passport_end' => ['วันหมดอายุพาสปอร์ต', 'labour_passport_date_end IS NOT NULL'],
            'visa_end' => ['วันหมดอายุวีซ่า', 'labour_visa_date_end IS NOT NULL'],
            'work_end' => ['วันหมดอายุใบอนุญาตทำงาน', 'labour_workpremit_date_end IS NOT NULL'],
            'ninety_end' => ['วันรายงานตัว 90 วัน', 'labour_day90_date_end IS NOT NULL'],
            'birthday' => ['วันเกิด', 'labour_birthday IS NOT NULL'],
            'jobstart' => ['วันเริ่มงาน', 'labour_jobdate_start IS NOT NULL'],
            'company' => ['บริษัท', "company_id IS NOT NULL AND company_id <> 0"],
        ];
        $selects = [];
        foreach ($checks as $k => [$label, $expr]) {
            $selects[] = "SUM($expr) AS `$k`";
        }
        $row = (clone $q)->selectRaw(implode(', ', $selects))->first();
        $items = [];
        foreach ($checks as $k => [$label, $expr]) {
            $items[$k] = ['label' => $label, 'have' => (int) $row->{$k}, 'pct' => round((int) $row->{$k} * 100 / $total, 1)];
        }
        if ($docsReady) {
            $withFile = (clone $q)->whereExists(function ($s) {
                $s->selectRaw('1')->from('labour_documents')->whereColumn('labour_documents.labour_id', 'labour.labour_id');
            })->count();
            $items['file'] = ['label' => 'มีไฟล์เอกสารอย่างน้อย 1 ไฟล์', 'have' => $withFile, 'pct' => round($withFile * 100 / $total, 1)];
        }
        uasort($items, fn ($a, $b) => $a['pct'] <=> $b['pct']);

        return ['total' => $total, 'items' => $items];
    }

    /** Joined / resigned / escaped per month for the past N months. */
    public function trend(int $company, int $months): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);
        $keys = [];
        $labels = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $start->copy()->addMonths($i);
            $keys[] = $m->format('Y-m');
            $labels[] = self::thaiMonth($m);
        }
        $series = [];
        foreach (['joined' => 'labour_jobdate_start', 'resigned' => 'labour_resing_date', 'escaped' => 'labour_escape_date'] as $k => $column) {
            $q = $this->base($company)->whereNotNull($column)->where($column, '>=', $start->toDateString());
            if ($k === 'resigned') {
                $q->where('labour_status_job', 'resign');
            } elseif ($k === 'escaped') {
                $q->where('labour_status_job', 'escape');
            }
            $rows = $q->selectRaw($this->monthExpr($column) . ' AS m, COUNT(*) AS c')->groupBy('m')->pluck('c', 'm');
            $series[$k] = array_map(fn ($key) => (int) ($rows[$key] ?? 0), $keys);
        }
        // Working head-count at the end of each month (approximation from start / leave dates).
        $working = [];
        foreach ($keys as $i => $key) {
            $endOfMonth = Carbon::createFromFormat('Y-m', $key)->endOfMonth()->toDateString();
            $working[] = (int) $this->base($company)
                ->whereNotNull('labour_jobdate_start')->where('labour_jobdate_start', '<=', $endOfMonth)
                ->where(function ($w) use ($endOfMonth) {
                    $w->where('labour_status_job', 'job')
                        ->orWhere(fn ($x) => $x->where('labour_status_job', 'resign')->where(fn ($y) => $y->whereNull('labour_resing_date')->orWhere('labour_resing_date', '>', $endOfMonth)))
                        ->orWhere(fn ($x) => $x->where('labour_status_job', 'escape')->where(fn ($y) => $y->whereNull('labour_escape_date')->orWhere('labour_escape_date', '>', $endOfMonth)));
                })->count();
        }
        $series['working'] = $working;

        return ['keys' => $keys, 'labels' => $labels, 'series' => $series];
    }

    /** Nationality, sex and age bands of working labours. */
    public function demographics(int $company): array
    {
        $q = fn () => $this->base($company)->where('labour_status_job', 'job');
        $natNames = Schema::hasTable('nationality') ? DB::table('nationality')->pluck('name_th', 'code') : collect();
        $nat = $q()->selectRaw("COALESCE(NULLIF(labour_nationality, ''), '-') AS k, COUNT(*) AS c")->groupBy('k')->orderByDesc('c')->get()
            ->map(fn ($r) => ['label' => $natNames[$r->k] ?? ucfirst($r->k), 'code' => $r->k, 'count' => (int) $r->c])->values()->all();
        $sexRows = $q()->selectRaw('labour_sex AS k, COUNT(*) AS c')->groupBy('k')->pluck('c', 'k');
        $sex = [
            ['label' => 'ชาย', 'count' => (int) ($sexRows['male'] ?? 0) + (int) ($sexRows['man'] ?? 0)],
            ['label' => 'หญิง', 'count' => (int) ($sexRows['female'] ?? 0)],
        ];
        $today = Carbon::today();
        $bands = ['18-24' => [18, 24], '25-34' => [25, 34], '35-44' => [35, 44], '45-54' => [45, 54], '55+' => [55, 120]];
        $selects = [];
        foreach ($bands as $label => [$lo, $hi]) {
            $from = $today->copy()->subYears($hi + 1)->addDay()->toDateString();
            $to = $today->copy()->subYears($lo)->toDateString();
            $selects[] = "SUM(labour_birthday IS NOT NULL AND labour_birthday BETWEEN '$from' AND '$to') AS `" . str_replace(['-', '+'], ['_', 'p'], $label) . '`';
        }
        $selects[] = 'SUM(labour_birthday IS NULL) AS unknown';
        $row = $q()->selectRaw(implode(', ', $selects))->first();
        $age = [];
        foreach ($bands as $label => $x) {
            $age[] = ['label' => $label, 'count' => (int) $row->{str_replace(['-', '+'], ['_', 'p'], $label)}];
        }
        $age[] = ['label' => 'ไม่ระบุ', 'count' => (int) $row->unknown];
        $avgAge = $q()->whereNotNull('labour_birthday')->selectRaw('AVG(' . $this->ageExpr() . ') AS a')->value('a');

        return ['nationality' => $nat, 'sex' => $sex, 'age' => $age, 'avg_age' => $avgAge ? round((float) $avgAge, 1) : null];
    }

    /** Escape rate per agency (all time): escaped / everyone the agency ever brought. */
    public function agencies(int $company): array
    {
        $names = Schema::hasTable('agency') ? DB::table('agency')->pluck('agency_name', 'agency_id') : collect();
        $rows = $this->base($company)
            ->selectRaw("COALESCE(labour_agency, 0) AS id, COUNT(*) AS total, SUM(labour_status_job = 'escape') AS esc, SUM(labour_status_job = 'job') AS job")
            ->groupBy('id')->orderByDesc('total')->limit(15)->get();

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'name' => $names[$r->id] ?? 'ไม่ระบุเอเจนซี่',
            'total' => (int) $r->total,
            'job' => (int) $r->job,
            'escape' => (int) $r->esc,
            'rate' => $r->total ? round($r->esc * 100 / $r->total, 1) : 0,
        ])->sortByDesc('rate')->values()->all();
    }

    /** Average months of service per company (working: until today; left: until the leave date). */
    public function tenure(): array
    {
        $today = Carbon::today()->toDateString();
        $months = "TIMESTAMPDIFF(MONTH, labour_jobdate_start, CASE
            WHEN labour_status_job = 'resign' AND labour_resing_date IS NOT NULL THEN labour_resing_date
            WHEN labour_status_job = 'escape' AND labour_escape_date IS NOT NULL THEN labour_escape_date
            ELSE '$today' END)";
        if (DB::connection()->getDriverName() === 'sqlite') {
            $months = "((julianday(CASE WHEN labour_status_job = 'resign' AND labour_resing_date IS NOT NULL THEN labour_resing_date WHEN labour_status_job = 'escape' AND labour_escape_date IS NOT NULL THEN labour_escape_date ELSE '$today' END) - julianday(labour_jobdate_start)) / 30.44)";
        }
        // Start dates before 2000 (or in the future) are typos and would skew the average by decades.
        $sane = fn ($q) => $q->whereNotNull('labour_jobdate_start')->whereBetween('labour_jobdate_start', ['2000-01-01', $today]);
        $rows = $sane(DB::table('labour'))
            ->leftJoin('company', 'company.company_id', '=', 'labour.company_id')
            ->selectRaw("labour.company_id, COALESCE(company.company_name, 'ไม่ระบุบริษัท') AS name, COUNT(*) AS n, AVG($months) AS avg_months")
            ->groupBy('labour.company_id', 'company.company_name')
            ->having('n', '>=', 5)
            ->orderByDesc('avg_months')
            ->limit(15)
            ->get();
        $overall = $sane(DB::table('labour'))->selectRaw("AVG($months) AS a")->value('a');

        return [
            'overall' => $overall !== null ? round((float) $overall, 1) : null,
            'rows' => $rows->map(fn ($r) => ['id' => (int) $r->company_id, 'name' => $r->name, 'n' => (int) $r->n, 'months' => round((float) $r->avg_months, 1)])->values()->all(),
        ];
    }

    /** Edits and AI use per day for the last 30 days (for the system card). */
    public function system(): array
    {
        $days = [];
        $labels = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i);
            $days[] = $d->toDateString();
            $labels[] = $d->format('j') . ' ' . self::THAI_MONTHS[(int) $d->format('n') - 1];
        }
        $edits = Schema::hasTable('action_logs')
            ? DB::table('action_logs')->where('created_at', '>=', $days[0])->selectRaw($this->dayExpr('created_at') . ' AS d, COUNT(*) AS c')->groupBy('d')->pluck('c', 'd')
            : collect();
        $ai = Schema::hasTable('ai_chat_logs')
            ? DB::table('ai_chat_logs')->where('created_at', '>=', $days[0])->selectRaw($this->dayExpr('created_at') . ' AS d, COUNT(*) AS c, SUM(COALESCE(prompt_tokens, 0) + COALESCE(completion_tokens, 0)) AS t')->groupBy('d')->get()->keyBy('d')
            : collect();
        $topEditors = Schema::hasTable('action_logs') && Schema::hasTable('users')
            ? DB::table('action_logs')->leftJoin('users', 'users.id', '=', 'action_logs.user_id')
                ->where('action_logs.created_at', '>=', $days[0])
                ->selectRaw("COALESCE(users.name, 'ระบบ') AS name, COUNT(*) AS c")->groupBy('users.name')->orderByDesc('c')->limit(5)->get()
                ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->c])->all()
            : [];

        return [
            'labels' => $labels,
            'edits' => array_map(fn ($d) => (int) ($edits[$d] ?? 0), $days),
            'ai' => array_map(fn ($d) => (int) ($ai[$d]->c ?? 0), $days),
            'ai_tokens' => (int) $ai->sum('t'),
            'ai_total' => (int) $ai->sum('c'),
            'edits_total' => (int) $edits->sum(),
            'top_editors' => $topEditors,
        ];
    }

    // ------------------------------------------------------------------ helpers

    public const THAI_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    public static function thaiMonth(Carbon $m): string
    {
        return self::THAI_MONTHS[(int) $m->format('n') - 1] . ' ' . ($m->year + 543 - 2500);
    }

    private function monthExpr(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', $column)" : "DATE_FORMAT($column, '%Y-%m')";
    }

    private function dayExpr(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m-%d', $column)" : "DATE($column)";
    }

    private function ageExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "(julianday('now') - julianday(labour_birthday)) / 365.25"
            : 'TIMESTAMPDIFF(YEAR, labour_birthday, CURDATE())';
    }
}
