<?php

namespace App\Services\Ai;

use App\Models\ActionLog;
use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use App\Services\ExpiryService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, whitelisted access to labour data for the AI assistant.
 * Every filter is validated here, so the model can never run arbitrary SQL.
 */
class LabourQueryService
{
    public const MAX_ROWS = 40;
    public const MAX_EXPORT_ROWS = 5000;

    public const DATE_FIELDS = [
        'labour_passport_date_end' => 'วันหมดอายุหนังสือเดินทาง',
        'labour_visa_date_end' => 'วันหมดอายุวีซ่า',
        'labour_workpremit_date_end' => 'วันหมดอายุใบอนุญาตทำงาน',
        'labour_day90_date_end' => 'วันครบกำหนดรายงานตัว 90 วัน',
        'labour_visa_date_in' => 'วันที่เดินทางเข้ามา',
        'labour_jobdate_start' => 'วันที่เริ่มเข้าทำงาน',
        'labour_birthday' => 'วันเกิด',
        'labour_resing_date' => 'วันที่ลาออก',
        'labour_escape_date' => 'วันที่หลบหนี',
        'created_at' => 'วันที่บันทึกในระบบ',
    ];

    public const GROUP_FIELDS = [
        'company' => 'company.company_name',
        'agency' => 'agency.agency_name',
        'nationality' => 'nationality.name_th',
        'status_job' => 'labour.labour_status_job',
        'status' => 'labour.labour_status',
        'sex' => 'labour.labour_sex',
        'contact_sale' => 'company.company_contact_sale',
    ];

    /** Export columns: key => [heading, value callback]. */
    public static function columns(): array
    {
        return [
            'no' => ['ลำดับ', fn ($r, $i) => $i + 1],
            'labour_number' => ['รหัสพนักงาน', fn ($r) => $r->labour_number],
            'prefix' => ['คำนำหน้า', fn ($r) => $r->labour_prefix],
            'fullname' => ['ชื่อ-สกุล', fn ($r) => $r->labour_fullname],
            'sex' => ['เพศ', fn ($r) => sex_label($r->labour_sex)],
            'nationality' => ['สัญชาติ', fn ($r) => $r->nationality_name ?? $r->labour_nationality],
            'birthday' => ['วันเกิด', fn ($r) => fmt_date($r->labour_birthday)],
            'age' => ['อายุ', fn ($r) => age_text($r->labour_birthday)],
            'company' => ['บริษัท / นายจ้าง', fn ($r) => $r->company_name],
            'contact_sale' => ['ผู้ประสานงานการตลาด', fn ($r) => $r->company_contact_sale],
            'agency' => ['เอเจนซี่', fn ($r) => $r->agency_name],
            'passport_number' => ['เลขที่หนังสือเดินทาง', fn ($r) => (string) $r->labour_passport_number],
            'passport_date_start' => ['วันที่ออกหนังสือเดินทาง', fn ($r) => fmt_date($r->labour_passport_date_start)],
            'passport_date_end' => ['วันหมดอายุหนังสือเดินทาง', fn ($r) => fmt_date($r->labour_passport_date_end)],
            'passport_days_left' => ['หนังสือเดินทางเหลือ (วัน)', fn ($r) => days_left($r->labour_passport_date_end)],
            'visa_number' => ['เลขที่วีซ่า', fn ($r) => $r->labour_visa_number],
            'visa_date_in' => ['วันที่เดินทางเข้ามา', fn ($r) => fmt_date($r->labour_visa_date_in)],
            'visa_date_start' => ['วันที่เริ่มวีซ่า', fn ($r) => fmt_date($r->labour_visa_date_start)],
            'visa_date_end' => ['วันหมดอายุวีซ่า', fn ($r) => fmt_date($r->labour_visa_date_end)],
            'visa_days_left' => ['วีซ่าเหลือ (วัน)', fn ($r) => days_left($r->labour_visa_date_end)],
            'workpermit_number' => ['เลขที่ใบอนุญาตทำงาน', fn ($r) => (string) $r->labour_workpremit_number],
            'workpermit_date_start' => ['วันที่ใบอนุญาตเริ่มต้น', fn ($r) => fmt_date($r->labour_workpremit_date_start)],
            'workpermit_date_end' => ['วันที่ใบอนุญาตสิ้นสุด', fn ($r) => fmt_date($r->labour_workpremit_date_end)],
            'workpermit_days_left' => ['ใบอนุญาตเหลือ (วัน)', fn ($r) => days_left($r->labour_workpremit_date_end)],
            'day90_date_start' => ['รายงานตัว 90 วัน เริ่มต้น', fn ($r) => fmt_date($r->labour_day90_date_start)],
            'day90_date_end' => ['รายงานตัว 90 วัน สิ้นสุด', fn ($r) => fmt_date($r->labour_day90_date_end)],
            'day90_days_left' => ['รายงานตัว 90 วัน เหลือ (วัน)', fn ($r) => days_left($r->labour_day90_date_end)],
            'tm_number' => ['เลขที่ ตม.', fn ($r) => $r->labour_tm_number],
            'labour_labour_number' => ['รหัสพนักงานที่นายจ้าง', fn ($r) => $r->labour_labour_number],
            'jobdate_start' => ['วันที่เริ่มเข้าทำงาน', fn ($r) => fmt_date($r->labour_jobdate_start)],
            'status_job' => ['สถานะการทำงาน', fn ($r) => status_job_label($r->labour_status_job)],
            'resign_date' => ['วันที่ลาออก', fn ($r) => fmt_date($r->labour_resing_date)],
            'escape_date' => ['วันที่หลบหนี', fn ($r) => fmt_date($r->labour_escape_date)],
            'status' => ['สถานะในระบบ', fn ($r) => enable_label($r->labour_status)],
            'note' => ['หมายเหตุ', fn ($r) => $r->labour_note],
        ];
    }

    public const DEFAULT_COLUMNS = [
        'no', 'labour_number', 'prefix', 'fullname', 'sex', 'nationality', 'company', 'agency', 'passport_number', 'passport_date_end',
        'visa_number', 'visa_date_end', 'workpermit_number', 'workpermit_date_end', 'day90_date_end', 'status_job', 'contact_sale',
    ];

    public function __construct(private ExpiryService $expiry)
    {
    }

    /**
     * Base query with joins. Filters (all optional):
     *  keyword, company, agency, nationality, status_job, status, sex,
     *  date_field + date_from / date_to,
     *  expiring: {type: visa|passport|work|ninety, within_days: int, include_expired: bool},
     *  expired: {type},
     *  order_by (a DATE_FIELDS key or fullname/company), order_dir
     */
    public function build(array $f): Builder
    {
        $q = DB::table('labour')
            ->leftJoin('company', 'company.company_id', '=', 'labour.company_id')
            ->leftJoin('agency', 'agency.agency_id', '=', 'labour.labour_agency')
            ->leftJoin('nationality', 'nationality.code', '=', 'labour.labour_nationality')
            ->select('labour.*', 'company.company_name', 'company.company_contact_sale', 'agency.agency_name', 'nationality.name_th as nationality_name');

        if ($kw = $this->str($f, 'keyword')) {
            $like = '%' . $kw . '%';
            $name = self::stripPrefix($kw);
            $q->where(function ($w) use ($like, $name) {
                self::nameLike($w, 'labour.labour_fullname', $name);
                $w->orWhere('labour.labour_passport_number', 'LIKE', $like)
                    ->orWhere('labour.labour_visa_number', 'LIKE', $like)
                    ->orWhere('labour.labour_workpremit_number', 'LIKE', $like)
                    ->orWhere('labour.labour_number', 'LIKE', $like)
                    ->orWhere('labour.labour_labour_number', 'LIKE', $like)
                    ->orWhere('labour.labour_tm_number', 'LIKE', $like);
            });
        }
        if ($v = $this->str($f, 'company')) {
            $q->where(function ($w) use ($v) {
                $w->where('company.company_tax', $v);
                self::nameLike($w, 'company.company_name', $v);
            });
        }
        if ($v = $this->str($f, 'agency')) {
            $q->where('agency.agency_name', 'LIKE', '%' . $v . '%');
        }
        if ($v = $this->str($f, 'contact_sale')) {
            $q->where('company.company_contact_sale', 'LIKE', '%' . $v . '%');
        }
        if ($v = $this->str($f, 'nationality')) {
            $code = $this->nationalityCode($v);
            $q->where(fn ($w) => $w->where('labour.labour_nationality', $code)->orWhere('nationality.name_th', 'LIKE', '%' . $v . '%'));
        }
        if (in_array($v = $this->str($f, 'status_job'), ['job', 'resign', 'escape'], true)) {
            $q->where('labour.labour_status_job', $v);
        }
        if (in_array($v = $this->str($f, 'status'), ['enable', 'disable'], true)) {
            $q->where('labour.labour_status', $v);
        }
        if (in_array($v = $this->str($f, 'sex'), ['male', 'female'], true)) {
            $q->where('labour.labour_sex', $v === 'male' ? 'male' : 'female');
            if ($v === 'male') {
                $q->orWhere('labour.labour_sex', 'man'); // legacy value
            }
        }

        // Generic date range
        $field = $this->str($f, 'date_field');
        if ($field && array_key_exists($field, self::DATE_FIELDS)) {
            if ($from = $this->date($f, 'date_from')) {
                $q->where('labour.' . $field, '>=', $from);
            }
            if ($to = $this->date($f, 'date_to')) {
                $q->where('labour.' . $field, '<=', $to);
            }
        }

        // Expiring soon / expired helpers
        $expiring = $f['expiring'] ?? null;
        if (is_array($expiring) && isset(ExpiryService::TYPES[$expiring['type'] ?? ''])) {
            $col = 'labour.' . ExpiryService::TYPES[$expiring['type']];
            $days = isset($expiring['within_days']) && is_numeric($expiring['within_days']) ? (int) $expiring['within_days'] : $this->expiry->threshold($expiring['type']);
            $q->whereNotNull($col)->where($col, '<=', Carbon::today()->addDays(max(0, $days))->toDateString());
            if (empty($expiring['include_expired'])) {
                $q->where($col, '>=', Carbon::today()->toDateString());
            }
            if (! array_key_exists('status_job', $f)) {
                $q->where('labour.labour_status_job', 'job');
            }
        }
        $expired = $f['expired'] ?? null;
        if (is_array($expired) && isset(ExpiryService::TYPES[$expired['type'] ?? ''])) {
            $col = 'labour.' . ExpiryService::TYPES[$expired['type']];
            $q->whereNotNull($col)->where($col, '<', Carbon::today()->toDateString());
            if (! array_key_exists('status_job', $f)) {
                $q->where('labour.labour_status_job', 'job');
            }
        }

        // Ordering
        $order = $this->str($f, 'order_by');
        $dir = strtolower($this->str($f, 'order_dir') ?? 'asc') === 'desc' ? 'desc' : 'asc';
        if ($order && array_key_exists($order, self::DATE_FIELDS)) {
            $q->orderBy('labour.' . $order, $dir);
        } elseif ($order === 'company') {
            $q->orderBy('company.company_name', $dir);
        } elseif ($order === 'fullname') {
            $q->orderBy('labour.labour_fullname', $dir);
        } elseif (is_array($expiring) || is_array($expired)) {
            $q->orderBy($col ?? 'labour.labour_id');
        } else {
            $q->orderBy('labour.labour_fullname');
        }

        return $q;
    }

    /** Compact rows for the model + total count. */
    public function search(array $f): array
    {
        $q = $this->build($f);
        $total = (clone $q)->count();
        $limit = min(self::MAX_ROWS, max(1, (int) ($f['limit'] ?? 20)));
        $rows = $q->limit($limit)->get()->map(fn ($r) => $this->compact($r))->all();

        return ['total' => $total, 'returned' => count($rows), 'rows' => $rows];
    }

    public function count(array $f): array
    {
        $group = $this->str($f, 'group_by');
        $q = $this->build($f);

        if ($group && isset(self::GROUP_FIELDS[$group])) {
            $col = self::GROUP_FIELDS[$group];
            $rows = $q->reorder()->select(DB::raw("COALESCE($col, '-') as `group`"), DB::raw('COUNT(*) as total'))
                ->groupBy(DB::raw("COALESCE($col, '-')"))->orderByDesc('total')->limit(100)->get()
                ->map(fn ($r) => ['group' => $this->groupLabel($group, $r->group), 'total' => (int) $r->total])->all();

            return ['group_by' => $group, 'groups' => $rows, 'total' => array_sum(array_column($rows, 'total'))];
        }

        return ['total' => $q->count()];
    }

    public function detail(string $query): array
    {
        $rows = $this->build(['keyword' => $query, 'limit' => 5])->limit(5)->get();
        if ($rows->isEmpty()) {
            return ['found' => 0, 'rows' => []];
        }

        return ['found' => $rows->count(), 'rows' => $rows->map(fn ($r) => $this->full($r))->all()];
    }

    public function exportRows(array $f): Collection
    {
        return $this->build($f)->limit(self::MAX_EXPORT_ROWS)->get();
    }

    public function expirySummary(): array
    {
        $counts = $this->expiry->counts();
        $out = [];
        foreach (ExpiryService::TYPES as $type => $col) {
            $out[] = ['type' => $type, 'label' => ExpiryService::LABELS[$type], 'notify_within_days' => $this->expiry->threshold($type), 'count' => $counts[$type]];
        }

        return ['summary' => $out, 'total' => $counts['total']];
    }

    public function overview(): array
    {
        $st = DB::table('labour')->selectRaw("COUNT(*) total, SUM(labour_status_job='job') job, SUM(labour_status_job='resign') resign, SUM(labour_status_job='escape') escape")->first();

        return [
            'labours' => ['total' => (int) $st->total, 'job' => (int) $st->job, 'resign' => (int) $st->resign, 'escape' => (int) $st->escape],
            'companies' => DB::table('company')->count(),
            'agencies' => DB::table('agency')->count(),
            'nationalities' => DB::table('nationality')->pluck('name_th', 'code')->all(),
        ];
    }

    public function listCompanies(?string $keyword): array
    {
        $rows = DB::table('company')
            ->leftJoin('labour', 'labour.company_id', '=', 'company.company_id')
            ->when($keyword, fn ($q) => $q->where('company.company_name', 'LIKE', '%' . $keyword . '%'))
            ->groupBy('company.company_id', 'company.company_name', 'company.company_contact_sale', 'company.company_status')
            ->select('company.company_id', 'company.company_name', 'company.company_contact_sale', 'company.company_status', DB::raw('COUNT(labour.labour_id) as labours'))
            ->orderBy('company.company_name')->limit(50)->get();

        return ['count' => $rows->count(), 'companies' => $rows->all()];
    }

    public function listAgencies(): array
    {
        $rows = DB::table('agency')
            ->leftJoin('labour', 'labour.labour_agency', '=', 'agency.agency_id')
            ->leftJoin('nationality', 'nationality.code', '=', 'agency.agency_nationality')
            ->groupBy('agency.agency_id', 'agency.agency_name', 'nationality.name_th')
            ->select('agency.agency_id', 'agency.agency_name', 'nationality.name_th as nationality', DB::raw('COUNT(labour.labour_id) as labours'))
            ->orderBy('agency.agency_name')->get();

        return ['agencies' => $rows->all()];
    }

    // ------------------------------------------------------------------ helpers

    private function compact($r): array
    {
        return [
            'id' => $r->labour_id,
            'name' => trim($r->labour_prefix . '. ' . $r->labour_fullname),
            'passport' => $r->labour_passport_number,
            'nationality' => $r->nationality_name ?? $r->labour_nationality,
            'company' => $r->company_name,
            'agency' => $r->agency_name,
            'status_job' => status_job_label($r->labour_status_job),
            'passport_end' => fmt_date($r->labour_passport_date_end),
            'visa_end' => fmt_date($r->labour_visa_date_end),
            'workpermit_end' => fmt_date($r->labour_workpremit_date_end),
            'day90_end' => fmt_date($r->labour_day90_date_end),
        ];
    }

    private function full($r): array
    {
        $out = [];
        foreach (self::columns() as $key => [$label, $fn]) {
            if ($key === 'no') {
                continue;
            }
            $out[$label] = $fn($r, 0);
        }
        $out['id'] = $r->labour_id;
        $out['url'] = route('labour.show', $r->labour_id);
        $out['เอกสารไฟล์'] = $this->documentsOf((int) $r->labour_id);

        return $out;
    }

    /** Uploaded files (labour_documents rows + legacy labour_file_* columns) with links the user can open. */
    /**
     * Find one uploaded document of a labour for the read_labour_document tool.
     * $document may be the document_key from get_labour_detail or (part of) the document name / file name.
     *
     * @return array{0: ?LabourModel, 1: ?LabourDocument, 2: array} labour, document, candidates (when ambiguous / not found)
     */
    public function findDocument(string $query, string $document): array
    {
        $row = $this->build(['keyword' => $query, 'limit' => 1])->first();
        $labour = $row ? LabourModel::find((int) $row->labour_id) : null;
        if (! $labour) {
            return [null, null, []];
        }

        $docs = $labour->documentList();
        $key = trim($document);
        $needle = mb_strtolower($key);
        $match = $docs->first(fn (LabourDocument $d) => ($d->id ? (string) $d->id : 'legacy-' . $d->legacy_column) === $key);
        if (! $match && $needle !== '') {
            $hits = $docs->filter(fn (LabourDocument $d) => str_contains(mb_strtolower((string) $d->name), $needle) || str_contains(mb_strtolower($d->fileName()), $needle));
            $match = $hits->count() === 1 ? $hits->first() : null;
            if (! $match && $hits->count() > 1) {
                $docs = $hits;
            }
        }
        if (! $match && $docs->count() === 1 && $needle === '') {
            $match = $docs->first();
        }

        return [$labour, $match, $this->documentsOf((int) $labour->labour_id)];
    }

    private function documentsOf(int $labourId): array
    {
        $labour = LabourModel::find($labourId);
        if (! $labour) {
            return [];
        }

        return $labour->documentList()->map(fn (LabourDocument $d) => [
            'document_key' => $d->id ? (string) $d->id : 'legacy-' . $d->legacy_column,
            'ชื่อเอกสาร' => $d->name,
            'ไฟล์' => $d->fileName(),
            'ขนาด' => $d->humanSize(),
            'มีไฟล์อยู่จริง' => $d->exists(),
            'open_url' => $d->id ? route('labour.document.open', $d->id) : route('labour.document.openLegacy', [$labourId, $d->legacy_column]),
        ])->values()->all();
    }

    // ------------------------------------------------------------------ insight tools

    /** Who changed what on a labour (action_logs), newest first. $query empty = all labours. */
    public function changeHistory(string $query, ?string $from = null, ?string $to = null, int $limit = 20): array
    {
        $ids = [];
        if (trim($query) !== '') {
            $ids = $this->build(['keyword' => $query])->limit(20)->pluck('labour.labour_id')->all();
            if (! $ids) {
                return ['found' => 0, 'rows' => [], 'message' => 'ไม่พบแรงงานตามคำค้น'];
            }
        }
        $logs = ActionLog::query()->with('user')
            ->where('model_type', LabourModel::class)
            ->when($ids, fn ($q) => $q->whereIn('model_id', $ids))
            ->when($from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('id')->limit(max(1, min($limit, 50)))->get();

        $names = LabourModel::whereIn('labour_id', $logs->pluck('model_id')->unique())->get(['labour_id', 'labour_prefix', 'labour_fullname', 'labour_passport_number'])->keyBy('labour_id');
        $labels = array_map(fn ($f) => $f[0], DataUpdateService::LABOUR_FIELDS) + ['labour_file_passport' => 'ไฟล์พาสปอร์ต', 'labour_file_visa' => 'ไฟล์วีซ่า', 'labour_file_work' => 'ไฟล์ใบอนุญาต', 'labour_photo' => 'รูปแรงงาน', 'company_id' => 'บริษัท (id)'];
        $actions = ['created' => 'เพิ่ม', 'updated' => 'แก้ไข', 'deleted' => 'ลบ'];

        $rows = $logs->map(function (ActionLog $l) use ($names, $labels, $actions) {
            $n = $names->get($l->model_id);
            $changes = [];
            $new = is_array($l->new_values) ? $l->new_values : [];
            $old = is_array($l->old_values) ? $l->old_values : [];
            foreach (array_keys($new ?: $old) as $col) {
                if (in_array($col, ['updated_at', 'created_at', 'updated_by', 'created_by'], true)) {
                    continue;
                }
                $changes[$labels[$col] ?? $col] = ($l->action_type === 'created' ? '' : (string) ($old[$col] ?? '') . ' → ') . (string) ($new[$col] ?? '');
            }

            return [
                'when' => fmt_datetime($l->created_at),
                'by' => optional($l->user)->name ?? ((string) ($new['updated_by'] ?? $new['created_by'] ?? '-')),
                'action' => $actions[$l->action_type] ?? $l->action_type,
                'labour' => $n ? trim($n->labour_prefix . '. ' . $n->labour_fullname) . ' (' . $n->labour_passport_number . ')' : '#' . $l->model_id . ' (ถูกลบแล้ว)',
                'changes' => $changes,
            ];
        })->all();

        return ['found' => count($rows), 'rows' => $rows];
    }

    /** How many documents of one type expire in each of the next N months (plus already expired), optionally grouped. */
    public function expiryTimeline(string $type, int $months = 6, ?string $groupBy = null, array $filters = []): array
    {
        $col = ExpiryService::TYPES[$type] ?? null;
        if (! $col) {
            return ['error' => 'type ต้องเป็น ' . implode('/', array_keys(ExpiryService::TYPES))];
        }
        $months = max(1, min($months, 24));
        $today = Carbon::today();
        $end = $today->copy()->addMonths($months)->endOfMonth()->toDateString();
        $filters = $filters + ['status_job' => 'job'];
        unset($filters['expiring'], $filters['expired'], $filters['order_by'], $filters['limit']);

        $base = $this->build($filters)->reorder()->whereNotNull("labour.$col")->where("labour.$col", '<=', $end);
        $expired = (clone $base)->where("labour.$col", '<', $today->toDateString())->count();
        $upcoming = (clone $base)->where("labour.$col", '>=', $today->toDateString());

        $groupCol = $groupBy && isset(self::GROUP_FIELDS[$groupBy]) ? self::GROUP_FIELDS[$groupBy] : null;
        $select = [DB::raw("SUBSTR(labour.$col, 1, 7) AS ym"), DB::raw('COUNT(*) AS total')];
        if ($groupCol) {
            $select[] = DB::raw("COALESCE($groupCol, '-') AS grp");
        }
        $rows = $upcoming->select($select)->groupBy(DB::raw("SUBSTR(labour.$col, 1, 7)" . ($groupCol ? ", COALESCE($groupCol, '-')" : '')))->orderBy('ym')->get();

        $byMonth = [];
        for ($i = 0; $i <= $months; $i++) {
            $byMonth[$today->copy()->addMonths($i)->format('Y-m')] = ['month' => $today->copy()->addMonths($i)->format('Y-m'), 'total' => 0, 'groups' => []];
        }
        foreach ($rows as $r) {
            if (! isset($byMonth[$r->ym])) {
                continue;
            }
            $byMonth[$r->ym]['total'] += (int) $r->total;
            if ($groupCol) {
                $byMonth[$r->ym]['groups'][] = ['group' => $this->groupLabel($groupBy, $r->grp), 'total' => (int) $r->total];
            }
        }
        foreach ($byMonth as &$m) {
            usort($m['groups'], fn ($a, $b) => $b['total'] <=> $a['total']);
            $m['groups'] = array_slice($m['groups'], 0, 15);
            if (! $groupCol) {
                unset($m['groups']);
            }
        }

        return [
            'type' => $type, 'label' => ExpiryService::LABELS[$type], 'from' => $today->toDateString(), 'to' => $end,
            'expired_already' => $expired, 'months' => array_values($byMonth), 'total_upcoming' => array_sum(array_column($byMonth, 'total')),
            'note' => 'นับเฉพาะแรงงานที่ทำงานอยู่ (status_job=job) เว้นแต่ระบุ filters เป็นอย่างอื่น',
        ];
    }

    /** Data problems that break the workflow: missing fields, impossible dates, expired-but-working, no files. */
    public function dataQualityCheck(array $filters = []): array
    {
        unset($filters['expiring'], $filters['expired'], $filters['order_by'], $filters['limit']);
        $base = $this->build($filters)->reorder();
        $today = Carbon::today()->toDateString();
        $checks = [
            'no_birthday' => ['ไม่มีวันเกิด (เข้า Worker App ไม่ได้)', fn ($q) => $q->whereNull('labour.labour_birthday')],
            'no_passport_number' => ['ไม่มีเลขพาสปอร์ต', fn ($q) => $q->where(fn ($w) => $w->whereNull('labour.labour_passport_number')->orWhere('labour.labour_passport_number', ''))],
            'no_passport_end' => ['ทำงานอยู่แต่ไม่มีวันหมดอายุพาสปอร์ต', fn ($q) => $q->where('labour.labour_status_job', 'job')->whereNull('labour.labour_passport_date_end')],
            'no_visa_end' => ['ทำงานอยู่แต่ไม่มีวันหมดอายุวีซ่า', fn ($q) => $q->where('labour.labour_status_job', 'job')->whereNull('labour.labour_visa_date_end')],
            'no_work_end' => ['ทำงานอยู่แต่ไม่มีวันหมดอายุใบอนุญาตทำงาน', fn ($q) => $q->where('labour.labour_status_job', 'job')->whereNull('labour.labour_workpremit_date_end')],
            'no_company' => ['ทำงานอยู่แต่ไม่มีนายจ้าง', fn ($q) => $q->where('labour.labour_status_job', 'job')->whereNull('labour.company_id')],
            'visa_after_passport' => ['วีซ่าหมดอายุหลังพาสปอร์ตหมด (ผิดปกติ)', fn ($q) => $q->whereNotNull('labour.labour_visa_date_end')->whereNotNull('labour.labour_passport_date_end')->whereColumn('labour.labour_visa_date_end', '>', 'labour.labour_passport_date_end')],
            'work_after_passport' => ['ใบอนุญาตทำงานหมดอายุหลังพาสปอร์ตหมด (ผิดปกติ)', fn ($q) => $q->whereNotNull('labour.labour_workpremit_date_end')->whereNotNull('labour.labour_passport_date_end')->whereColumn('labour.labour_workpremit_date_end', '>', 'labour.labour_passport_date_end')],
            'expired_but_working' => ['ทำงานอยู่แต่มีเอกสารหมดอายุแล้ว', fn ($q) => $q->where('labour.labour_status_job', 'job')->where(fn ($w) => $w->where('labour.labour_passport_date_end', '<', $today)->orWhere('labour.labour_visa_date_end', '<', $today)->orWhere('labour.labour_workpremit_date_end', '<', $today))],
            'resign_without_date' => ['ลาออกแต่ไม่มีวันที่ลาออก', fn ($q) => $q->where('labour.labour_status_job', 'resign')->whereNull('labour.labour_resing_date')],
            'disabled_but_working' => ['ปิดใช้งานในระบบแต่สถานะยังทำงานอยู่', fn ($q) => $q->where('labour.labour_status', 'disable')->where('labour.labour_status_job', 'job')],
        ];
        if (LabourDocument::tableReady()) {
            $checks['no_documents'] = ['ไม่มีไฟล์เอกสารเลย', fn ($q) => $q->where('labour.labour_status_job', 'job')
                ->whereNull('labour.labour_file_passport')->whereNull('labour.labour_file_visa')->whereNull('labour.labour_file_work')
                ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('labour_documents')->whereColumn('labour_documents.labour_id', 'labour.labour_id'))];
        }

        $out = [];
        $problems = 0;
        foreach ($checks as $key => [$label, $apply]) {
            $q = $apply(clone $base);
            $n = (clone $q)->count();
            if ($n === 0) {
                continue;
            }
            $problems += $n;
            $out[] = [
                'check' => $key, 'label' => $label, 'count' => $n,
                'examples' => $q->limit(8)->get()->map(fn ($r) => trim($r->labour_prefix . '. ' . $r->labour_fullname) . ' (' . $r->labour_passport_number . ')')->all(),
            ];
        }
        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['checked' => (clone $base)->count(), 'problems' => $problems, 'issues' => $out, 'filters' => $filters];
    }

    /** Words that only say what kind of legal entity a name is; ignored when matching company/agency names. */
    public const LEGAL_WORDS = ['บริษัท', 'บจก.', 'บจก', 'จำกัด', 'จํากัด', '(มหาชน)', 'มหาชน', 'หจก.', 'หจก', 'ห้างหุ้นส่วนจำกัด', 'บมจ.', 'บมจ', 'co.,ltd.', 'co., ltd.', 'co.,ltd', 'co ltd', 'company', 'limited', 'ltd.', 'ltd', 'co.', 'นจ.', 'นาย', 'นาง', 'นางสาว'];

    /**
     * "ประดิษฐ์ธนานุรักษ์ บจก." / "บริษัท ประดิษฐ์ธนานุรักษ์ จำกัด" / "ประดิษฐ์ ธนานุรักษ์" all match the same row:
     * the whole text is tried first, then every meaningful word must appear in the name.
     */
    /** "Ms. CHHUON SAVETH" / "นางสาว CHHUON SAVETH" -> "CHHUON SAVETH" (the prefix is a separate column). */
    public static function stripPrefix(string $name): string
    {
        return trim(preg_replace('/^(mr|mrs|ms|miss|นาย|นางสาว|นาง|น\.ส\.)\.?\s+/iu', '', trim($name)));
    }

    public static function nameLike($query, string $column, string $value): void
    {
        $value = trim($value);
        $words = array_values(array_filter(preg_split('/[\s,]+/u', mb_strtolower($value)), function ($w) {
            return $w !== '' && ! in_array($w, array_map('mb_strtolower', self::LEGAL_WORDS), true);
        }));
        $query->orWhere($column, 'LIKE', '%' . $value . '%');
        if ($words && (count($words) > 1 || implode(' ', $words) !== mb_strtolower($value))) {
            $query->orWhere(function ($w) use ($column, $words) {
                foreach ($words as $word) {
                    $w->where($column, 'LIKE', '%' . $word . '%');
                }
            });
        }
    }

    private function str(array $f, string $key): ?string
    {
        $v = $f[$key] ?? null;

        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    }

    private function date(array $f, string $key): ?string
    {
        $v = $this->str($f, $key);
        if (! $v) {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function nationalityCode(string $v): string
    {
        $v = mb_strtolower($v);
        $map = ['พม่า' => 'myanmar', 'เมียนมา' => 'myanmar', 'myanmar' => 'myanmar', 'burma' => 'myanmar',
            'กัมพูชา' => 'cambodia', 'เขมร' => 'cambodia', 'cambodia' => 'cambodia',
            'ลาว' => 'lao', 'lao' => 'lao', 'laos' => 'lao',
            'เวียดนาม' => 'vietnam', 'vietnam' => 'vietnam'];

        return $map[$v] ?? $v;
    }

    private function groupLabel(string $group, $value): string
    {
        return match ($group) {
            'status_job' => status_job_label($value),
            'status' => enable_label($value),
            'sex' => sex_label($value),
            default => (string) $value,
        };
    }
}
