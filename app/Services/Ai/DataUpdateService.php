<?php

namespace App\Services\Ai;

use App\Models\agency\AgencyModel;
use App\Models\company\CompanyModel;
use App\Models\labour\LabourModel;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Lets the assistant edit data in two steps (MasterAdmin only, enforced by LabourAssistant):
 *   propose()  validates the requested changes, stores them under a token for 10 minutes and returns
 *              an old -> new preview for the user to confirm;
 *   apply()    writes the stored proposal (never values the model sends at that point).
 * Writes go through Eloquent, so the labour audit log (action_logs) records them like any other edit.
 */
class DataUpdateService
{
    public const TTL_MINUTES = 10;

    /** column => [label, rules]. Only these columns can ever be changed through the assistant. */
    public const LABOUR_FIELDS = [
        'labour_prefix' => ['คำนำหน้า', 'in:Mr,Ms,Mrs'],
        'labour_fullname' => ['ชื่อ-สกุล', 'string|max:255'],
        'labour_sex' => ['เพศ', 'in:male,female'],
        'labour_nationality' => ['สัญชาติ', 'string|max:50'],
        'labour_birthday' => ['วันเกิด', 'date'],
        'labour_number' => ['รหัสพนักงาน', 'nullable|string|max:255'],
        'labour_agency' => ['เอเจนซี่', 'nullable|integer'],
        'company_id' => ['บริษัท/นายจ้าง', 'nullable|integer'],
        'labour_jobdate_start' => ['วันที่เริ่มเข้าทำงาน', 'nullable|date'],
        'labour_labour_number' => ['รหัสพนักงานที่นายจ้าง', 'nullable|string|max:50'],
        'labour_passport_number' => ['เลขที่หนังสือเดินทาง', 'string|max:50|regex:/^[A-Za-z0-9\-]+$/'],
        'labour_passport_date_start' => ['วันที่ออกหนังสือเดินทาง', 'nullable|date'],
        'labour_passport_date_end' => ['วันหมดอายุหนังสือเดินทาง', 'nullable|date'],
        'labour_visa_number' => ['เลขที่วีซ่า', 'nullable|string|max:255'],
        'labour_visa_date_in' => ['วันที่เดินทางเข้า', 'nullable|date'],
        'labour_visa_date_start' => ['วันที่เริ่มวีซ่า', 'nullable|date'],
        'labour_visa_date_end' => ['วันหมดอายุวีซ่า', 'nullable|date'],
        'labour_workpremit_number' => ['เลขที่ใบอนุญาตทำงาน', 'nullable|string|max:50'],
        'labour_workpremit_date_start' => ['วันที่เริ่มใบอนุญาตทำงาน', 'nullable|date'],
        'labour_workpremit_date_end' => ['วันหมดอายุใบอนุญาตทำงาน', 'nullable|date'],
        'labour_day90_date_start' => ['รายงานตัว 90 วัน เริ่ม', 'nullable|date'],
        'labour_day90_date_end' => ['รายงานตัว 90 วัน ครบกำหนด', 'nullable|date'],
        'labour_tm_number' => ['เลขที่ ตม.', 'nullable|string|max:255'],
        'labour_status_job' => ['สถานะการทำงาน', 'in:job,resign,escape'],
        'labour_resing_date' => ['วันที่ลาออก', 'nullable|date'],
        'labour_escape_date' => ['วันที่หลบหนี', 'nullable|date'],
        'labour_status' => ['สถานะในระบบ', 'in:enable,disable'],
        'labour_note' => ['หมายเหตุ', 'nullable|string|max:5000'],
    ];

    public const COMPANY_FIELDS = [
        'company_name' => ['ชื่อบริษัท', 'string|max:255'],
        'company_name_en' => ['ชื่อบริษัท (อังกฤษ)', 'nullable|string|max:255'],
        'company_tax' => ['เลขทะเบียนบริษัท', 'string|max:255'],
        'company_address' => ['ที่อยู่', 'string|max:255'],
        'company_address_en' => ['ที่อยู่ (อังกฤษ)', 'nullable|string|max:255'],
        'company_zipcode' => ['รหัสไปรษณีย์', 'string|max:10'],
        'company_contact' => ['ผู้ติดต่อ', 'string|max:100'],
        'company_phone' => ['เบอร์โทร', 'string|max:100'],
        'company_email' => ['อีเมล', 'nullable|email|max:100'],
        'company_fax' => ['แฟกซ์', 'nullable|string|max:100'],
        'company_type' => ['ประเภทกิจการ', 'string|max:100'],
        'company_type_en' => ['ประเภทกิจการ (อังกฤษ)', 'nullable|string|max:100'],
        'company_contact_sale' => ['ผู้ประสานงานการตลาด', 'string|max:100'],
        'company_note' => ['หมายเหตุ', 'nullable|string|max:5000'],
        'company_status' => ['สถานะ', 'in:enable,disable'],
    ];

    public const AGENCY_FIELDS = [
        'agency_name' => ['ชื่อเอเจนซี่', 'string|max:255'],
        'agency_tax' => ['เลขทะเบียน', 'string|max:20'],
        'agency_nationality' => ['สัญชาติ', 'string|max:50'],
        'agency_address' => ['ที่อยู่', 'string|max:255'],
        'agency_email' => ['อีเมล', 'nullable|email|max:150'],
        'agency_contact' => ['ผู้ติดต่อ', 'nullable|string|max:150'],
        'agency_phone' => ['เบอร์โทร', 'nullable|string|max:150'],
        'agency_note' => ['หมายเหตุ', 'nullable|string|max:150'],
    ];

    /** Friendlier names the model may use instead of the column names. */
    private const ALIASES = [
        'labour' => [
            'prefix' => 'labour_prefix', 'fullname' => 'labour_fullname', 'name' => 'labour_fullname', 'sex' => 'labour_sex',
            'nationality' => 'labour_nationality', 'birthday' => 'labour_birthday', 'agency' => 'labour_agency', 'company' => 'company_id',
            'employer' => 'company_id', 'passport_number' => 'labour_passport_number', 'passport_date_start' => 'labour_passport_date_start',
            'passport_date_end' => 'labour_passport_date_end', 'visa_number' => 'labour_visa_number', 'visa_date_in' => 'labour_visa_date_in',
            'visa_date_start' => 'labour_visa_date_start', 'visa_date_end' => 'labour_visa_date_end', 'workpermit_number' => 'labour_workpremit_number',
            'work_permit_number' => 'labour_workpremit_number', 'workpermit_date_start' => 'labour_workpremit_date_start',
            'work_permit_date_start' => 'labour_workpremit_date_start', 'workpermit_date_end' => 'labour_workpremit_date_end',
            'work_permit_date_end' => 'labour_workpremit_date_end', 'day90_date_start' => 'labour_day90_date_start', 'day90_date_end' => 'labour_day90_date_end',
            'tm_number' => 'labour_tm_number', 'jobdate_start' => 'labour_jobdate_start', 'status_job' => 'labour_status_job',
            'resign_date' => 'labour_resing_date', 'labour_resign_date' => 'labour_resing_date', 'escape_date' => 'labour_escape_date',
            'status' => 'labour_status', 'note' => 'labour_note', 'labour_number' => 'labour_number',
        ],
        'company' => ['name' => 'company_name', 'name_en' => 'company_name_en', 'tax' => 'company_tax', 'address' => 'company_address', 'phone' => 'company_phone', 'email' => 'company_email', 'type' => 'company_type', 'status' => 'company_status', 'note' => 'company_note', 'contact' => 'company_contact'],
        'agency' => ['name' => 'agency_name', 'tax' => 'agency_tax', 'nationality' => 'agency_nationality', 'address' => 'agency_address', 'phone' => 'agency_phone', 'email' => 'agency_email', 'contact' => 'agency_contact', 'note' => 'agency_note'],
    ];

    public const MAX_ITEMS = 500;

    public function __construct(private LabourQueryService $query)
    {
    }

    /** Step 1 (single record): validate + preview. */
    public function propose(User $user, string $entity, string $query, array $changes): array
    {
        $out = $this->proposeMany($user, $entity, [['query' => $query, 'changes' => $changes]]);
        if (isset($out['error'])) {
            return $out;
        }
        if (empty($out['items'])) {
            return $out['failed'][0] ?? ['error' => 'ไม่มีรายการที่แก้ได้'];
        }

        return $out + ['record' => $out['items'][0]['record'], 'preview' => $out['items'][0]['preview']];
    }

    /**
     * Step 1 (one or many records, e.g. rows of an Excel sheet): every valid row goes into ONE proposal
     * that the user confirms once; rows that fail are reported in "failed" and skipped.
     */
    public function proposeMany(User $user, string $entity, array $items): array
    {
        $entity = strtolower(trim($entity));
        if (! in_array($entity, ['labour', 'company', 'agency'], true)) {
            return ['error' => 'entity ต้องเป็น labour, company หรือ agency'];
        }
        $items = array_values(array_filter($items, 'is_array'));
        if (! $items) {
            return ['error' => 'ไม่ได้ระบุรายการที่ต้องการแก้ไข'];
        }
        if (count($items) > self::MAX_ITEMS) {
            return ['error' => 'แก้ได้ครั้งละไม่เกิน ' . self::MAX_ITEMS . ' รายการ'];
        }

        $ok = [];
        $failed = [];
        $seen = [];
        foreach ($items as $i => $item) {
            $query = (string) ($item['query'] ?? '');
            $changes = is_array($item['changes'] ?? null) ? $item['changes'] : [];
            $r = $this->buildItem($entity, $query, $changes);
            if (isset($r['error'])) {
                $failed[] = ['row' => $i + 1, 'query' => $query] + $r;
                continue;
            }
            if (isset($seen[$r['id']])) {
                $failed[] = ['row' => $i + 1, 'query' => $query, 'error' => 'รายการซ้ำกับแถวก่อนหน้า (' . $r['label'] . ')'];
                continue;
            }
            $seen[$r['id']] = true;
            $ok[] = ['record' => $r['label'], 'id' => $r['id'], 'data' => $r['data'], 'preview' => $r['preview']];
        }

        $token = null;
        if ($ok) {
            $token = Str::lower(Str::random(8));
            Cache::put($this->key($user, $token), ['entity' => $entity, 'items' => $ok, 'user_id' => $user->id], now()->addMinutes(self::TTL_MINUTES));
            Cache::put($this->key($user, 'latest'), $token, now()->addMinutes(self::TTL_MINUTES));
        }

        return [
            'ok' => (bool) $ok,
            'token' => $token,
            'entity' => $entity,
            'total' => count($items),
            'valid' => count($ok),
            'items' => array_map(fn ($o) => ['record' => $o['record'], 'preview' => $o['preview']], $ok),
            'failed' => $failed,
            'expires_in_minutes' => self::TTL_MINUTES,
            'next_step' => $ok
                ? 'แสดงตารางค่าเดิม→ค่าใหม่ของทุกรายการ (และรายการที่แก้ไม่ได้พร้อมเหตุผล) ให้ผู้ใช้ดู แล้วถามให้พิมพ์ "ยืนยัน" ห้ามเรียก apply_update จนกว่าผู้ใช้จะยืนยันในข้อความถัดไป'
                : 'ไม่มีรายการที่แก้ได้ แจ้งเหตุผลของแต่ละแถวให้ผู้ใช้',
        ];
    }

    /**
     * Step 1 (by condition): the same changes for every labour matching the search filters
     * (same filters as search_labours), e.g. all workers of one company -> resigned + disabled.
     */
    public function proposeByFilter(User $user, array $filters, array $changes): array
    {
        $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== []);
        if (! $filters) {
            return ['error' => 'ต้องระบุเงื่อนไข (filters) อย่างน้อย 1 อย่าง ไม่อนุญาตให้แก้แรงงานทั้งระบบในครั้งเดียว'];
        }
        $rows = $this->query->build($filters)->limit(self::MAX_ITEMS + 1)->get(['labour.labour_id', 'labour.labour_fullname', 'labour.labour_passport_number']);
        if ($rows->isEmpty()) {
            return ['error' => 'ไม่พบแรงงานตามเงื่อนไขที่ระบุ', 'filters' => $filters];
        }
        if ($rows->count() > self::MAX_ITEMS) {
            return ['error' => 'พบเกิน ' . self::MAX_ITEMS . ' คน กรุณาแบ่งเงื่อนไขให้แคบลง'];
        }
        $items = $rows->map(fn ($r) => ['query' => (string) $r->labour_id, 'changes' => $changes])->all();
        $out = $this->proposeMany($user, 'labour', $items);
        $out['filters'] = $filters;
        $out['matched'] = $rows->count();

        return $out;
    }

    /** Validate one record's changes. Returns ['error'...] or ['id','label','data','preview']. */
    private function buildItem(string $entity, string $query, array $changes): array
    {
        if (! $changes) {
            return ['error' => 'ไม่ได้ระบุค่าที่ต้องการแก้ไข (changes)'];
        }

        [$model, $candidates] = $this->find($entity, $query);
        if (! $model) {
            return $candidates
                ? ['error' => 'พบหลายรายการ กรุณาระบุให้ชัด (ใช้เลขพาสปอร์ต/ชื่อเต็ม หรือ id)', 'candidates' => $candidates]
                : ['error' => 'ไม่พบ ' . $this->entityLabel($entity) . ' ตามคำค้น "' . $query . '"'];
        }

        $fields = $this->fields($entity);
        $data = [];
        $unknown = [];
        $resolved = [];
        foreach ($changes as $key => $value) {
            $column = $this->column($entity, (string) $key);
            if (! $column) {
                $unknown[] = (string) $key;
                continue;
            }
            $value = is_string($value) ? trim($value) : $value;
            if (in_array($value, ['', null, '-', 'null', 'ว่าง', 'ลบ'], true)) {
                $value = null;
            } elseif (str_ends_with($column, '_date') || str_contains($column, '_date_') || $column === 'labour_birthday') {
                $value = $this->normalizeDate((string) $value);
                if ($value === false) {
                    return ['error' => 'รูปแบบวันที่ไม่ถูกต้องสำหรับ ' . $fields[$column][0] . ' (ใช้ วัน/เดือน/ปี ค.ศ. หรือ ปี-เดือน-วัน)'];
                }
            } elseif ($column === 'company_id') {
                $value = $this->resolveId($value, CompanyModel::class, 'company_id', 'company_name', $resolved, 'บริษัท');
            } elseif ($column === 'labour_agency') {
                $value = $this->resolveId($value, AgencyModel::class, 'agency_id', 'agency_name', $resolved, 'เอเจนซี่');
            } elseif (in_array($column, ['labour_nationality', 'agency_nationality'], true)) {
                $value = $this->resolveNationality((string) $value);
            } elseif ($column === 'labour_passport_number') {
                $value = strtoupper(preg_replace('/\s+/', '', (string) $value));
            } elseif (in_array($column, ['labour_sex'], true)) {
                $value = match (mb_strtolower((string) $value)) { 'ชาย', 'm', 'man', 'male' => 'male', 'หญิง', 'f', 'woman', 'female' => 'female', default => $value };
            } elseif ($column === 'labour_status_job') {
                $value = match (mb_strtolower((string) $value)) { 'ทำงาน', 'job', 'working' => 'job', 'ลาออก', 'resign', 'resigned' => 'resign', 'หลบหนี', 'escape', 'absconded' => 'escape', default => $value };
            } elseif (in_array($column, ['labour_status', 'company_status'], true)) {
                $value = match (mb_strtolower((string) $value)) { 'เปิด', 'เปิดใช้งาน', 'enable', 'enabled', 'active' => 'enable', 'ปิด', 'ปิดใช้งาน', 'disable', 'disabled', 'inactive' => 'disable', default => $value };
            }
            if (is_array($value) && isset($value['error'])) {
                return $value;
            }
            $data[$column] = $value;
        }
        if ($unknown) {
            return ['error' => 'ไม่รู้จักฟิลด์: ' . implode(', ', $unknown), 'allowed_fields' => array_map(fn ($f) => $f[0], $fields)];
        }

        // Validate with the same kind of rules the forms use.
        $rules = [];
        foreach ($data as $column => $value) {
            $rule = $fields[$column][1];
            if ($value === null && ! str_contains($rule, 'nullable')) {
                return ['error' => $fields[$column][0] . ' ต้องมีค่า ลบไม่ได้'];
            }
            $rules[$column] = $value === null ? ['nullable'] : array_merge(['nullable'], explode('|', $rule));
        }
        if ($entity === 'labour' && isset($data['labour_passport_number'])) {
            $rules['labour_passport_number'][] = Rule::unique('labour', 'labour_passport_number')->ignore($model->labour_id, 'labour_id');
        }
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return ['error' => 'ค่าไม่ถูกต้อง: ' . implode(' ', $validator->errors()->all())];
        }

        // Status dates follow the chosen work status (same rule as the labour form).
        if ($entity === 'labour' && isset($data['labour_status_job'])) {
            if ($data['labour_status_job'] !== 'resign') {
                $data['labour_resing_date'] = null;
            }
            if ($data['labour_status_job'] !== 'escape') {
                $data['labour_escape_date'] = null;
            }
        }

        $preview = [];
        foreach ($data as $column => $new) {
            $old = $model->{$column};
            if ((string) $old === (string) $new) {
                unset($data[$column]);
                continue;
            }
            $preview[] = ['field' => $column, 'label' => $fields[$column][0] ?? $column, 'old' => $this->display($column, $old, $resolved), 'new' => $this->display($column, $new, $resolved)];
        }
        if (! $data) {
            return ['error' => 'ค่าที่ขอแก้เท่ากับค่าปัจจุบันอยู่แล้ว ไม่มีอะไรต้องเปลี่ยน'];
        }

        return ['id' => $this->id($entity, $model), 'label' => $this->label($entity, $model), 'data' => $data, 'preview' => $preview];
    }

    /** Step 2: write the stored proposal. */
    public function apply(User $user, ?string $token): array
    {
        $token = trim((string) $token);
        if ($token === '' || $token === 'latest') {
            $token = (string) Cache::get($this->key($user, 'latest'));
        }
        $payload = $token !== '' ? Cache::get($this->key($user, $token)) : null;
        if (! is_array($payload)) {
            return ['error' => 'ไม่พบรายการแก้ไขที่รอยืนยัน หรือหมดเวลาแล้ว (' . self::TTL_MINUTES . ' นาที) กรุณาเสนอการแก้ไขใหม่ด้วย propose_update'];
        }

        $done = [];
        $missing = [];
        DB::transaction(function () use ($payload, $user, &$done, &$missing) {
            foreach ($payload['items'] as $item) {
                $model = $this->findById($payload['entity'], (int) $item['id']);
                if (! $model) {
                    $missing[] = $item['record'];
                    continue;
                }
                $data = $item['data'];
                if (in_array('updated_by', $model->getFillable(), true)) {
                    $data['updated_by'] = $user->name . ' (AI)';
                }
                $model->update($data);
                $done[] = ['record' => $item['record'], 'changes' => $item['preview']];
            }
        });
        Cache::forget($this->key($user, $token));
        Cache::forget($this->key($user, 'latest'));
        Log::info('AI data update applied', ['user' => $user->id, 'entity' => $payload['entity'], 'records' => count($done)]);

        return [
            'updated' => (bool) $done,
            'entity' => $payload['entity'],
            'count' => count($done),
            'items' => $done,
            'record' => $done[0]['record'] ?? null,
            'changes' => $done[0]['changes'] ?? [],
            'not_found' => $missing,
            'updated_by' => $user->name . ' (AI)',
        ];
    }

    public function cancel(User $user, ?string $token): array
    {
        $token = trim((string) $token) ?: (string) Cache::get($this->key($user, 'latest'));
        Cache::forget($this->key($user, $token));
        Cache::forget($this->key($user, 'latest'));

        return ['cancelled' => true];
    }

    // ------------------------------------------------------------------ helpers

    private function key(User $user, string $token): string
    {
        return 'ai-update:' . $user->id . ':' . $token;
    }

    private function fields(string $entity): array
    {
        return match ($entity) { 'labour' => self::LABOUR_FIELDS, 'company' => self::COMPANY_FIELDS, default => self::AGENCY_FIELDS };
    }

    private function column(string $entity, string $key): ?string
    {
        $key = trim($key);
        $fields = $this->fields($entity);
        if (isset($fields[$key])) {
            return $key;
        }
        $alias = self::ALIASES[$entity][strtolower($key)] ?? null;

        return $alias && isset($fields[$alias]) ? $alias : null;
    }

    private function entityLabel(string $entity): string
    {
        return match ($entity) { 'labour' => 'แรงงาน', 'company' => 'บริษัท', default => 'เอเจนซี่' };
    }

    /** @return array{0: ?Model, 1: array} model when exactly one match, otherwise candidates */
    private function find(string $entity, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [null, []];
        }
        if ($entity === 'labour') {
            if (ctype_digit($query) && ($m = LabourModel::find((int) $query))) {
                return [$m, []];
            }
            $exact = LabourModel::whereRaw('UPPER(labour_passport_number) = ?', [strtoupper(preg_replace('/\s+/', '', $query))])->orderByDesc('labour_id')->get();
            if ($exact->count() === 1) {
                return [$exact->first(), []];
            }
            $name = LabourQueryService::stripPrefix($query);
            $exact = LabourModel::whereRaw('UPPER(labour_fullname) = ?', [mb_strtoupper($name)])->get();
            if ($exact->count() === 1) {
                return [$exact->first(), []];
            }
            if ($exact->count() > 1) {   // namesakes: prefer the single one still enabled / working
                $live = $exact->filter(fn ($r) => $r->labour_status === 'enable');
                if ($live->count() === 1) {
                    return [$live->first(), []];
                }
            }
            $rows = LabourModel::where(function ($w) use ($name, $query) {
                LabourQueryService::nameLike($w, 'labour_fullname', $name);
                $w->orWhere('labour_passport_number', 'LIKE', '%' . $query . '%');
            })->limit(6)->get();
            if ($rows->count() === 1) {
                return [$rows->first(), []];
            }

            return [null, $rows->map(fn ($r) => ['id' => $r->labour_id, 'name' => $r->labour_prefix . '. ' . $r->labour_fullname, 'passport' => $r->labour_passport_number])->all()];
        }
        [$class, $idCol, $nameCol] = $entity === 'company' ? [CompanyModel::class, 'company_id', 'company_name'] : [AgencyModel::class, 'agency_id', 'agency_name'];
        if (ctype_digit($query) && ($m = $class::find((int) $query))) {
            return [$m, []];
        }
        $exact = $class::where($nameCol, $query)->get();
        if ($exact->count() === 1) {
            return [$exact->first(), []];
        }
        $rows = $class::where(fn ($w) => LabourQueryService::nameLike($w, $nameCol, $query))->limit(6)->get();
        if ($rows->count() === 1) {
            return [$rows->first(), []];
        }

        return [null, $rows->map(fn ($r) => ['id' => $r->{$idCol}, 'name' => $r->{$nameCol}])->all()];
    }

    private function findById(string $entity, int $id): ?Model
    {
        return match ($entity) { 'labour' => LabourModel::find($id), 'company' => CompanyModel::find($id), default => AgencyModel::find($id) };
    }

    private function id(string $entity, Model $m): int
    {
        return (int) $m->getKey();
    }

    private function label(string $entity, Model $m): string
    {
        return match ($entity) {
            'labour' => trim($m->labour_prefix . '. ' . $m->labour_fullname) . ' (' . $m->labour_passport_number . ', #' . $m->labour_id . ')',
            'company' => $m->company_name . ' (#' . $m->company_id . ')',
            default => $m->agency_name . ' (#' . $m->agency_id . ')',
        };
    }

    /** Accepts 25/12/2026, 25-12-2026, 2026-12-25, 25/12/2569 (BE). Returns Y-m-d, or false when unreadable. */
    private function normalizeDate(string $value): string|false
    {
        $value = trim($value);
        if (preg_match('~^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$~', $value, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $value, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            try {
                return Carbon::parse($value)->toDateString();
            } catch (\Throwable $e) {
                return false;
            }
        }
        if ($y > 2400) {
            $y -= 543;
        }
        if (! checkdate($mo, $d, $y)) {
            return false;
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /** "123" or a (partial) name -> id; a unique match is required. */
    private function resolveId($value, string $class, string $idCol, string $nameCol, array &$resolved, string $what)
    {
        if (is_int($value) || ctype_digit((string) $value)) {
            $m = $class::find((int) $value);
            if (! $m) {
                return ['error' => 'ไม่พบ' . $what . ' id ' . $value];
            }
            $resolved[$idCol][(int) $value] = $m->{$nameCol};

            return (int) $value;
        }
        $rows = $class::where(fn ($w) => LabourQueryService::nameLike($w, $nameCol, (string) $value))->limit(6)->get();
        if ($rows->count() !== 1) {
            return ['error' => ($rows->isEmpty() ? 'ไม่พบ' : 'พบหลาย') . $what . ' "' . $value . '"', 'candidates' => $rows->map(fn ($r) => ['id' => $r->{$idCol}, 'name' => $r->{$nameCol}])->all()];
        }
        $resolved[$idCol][(int) $rows->first()->{$idCol}] = $rows->first()->{$nameCol};

        return (int) $rows->first()->{$idCol};
    }

    private function resolveNationality(string $value)
    {
        $v = mb_strtolower(trim($value));
        $row = DB::table('nationality')->whereRaw('LOWER(code) = ?', [$v])->orWhere('name_th', $value)->first()
            ?: DB::table('nationality')->where('name_th', 'LIKE', '%' . $value . '%')->first();
        if (! $row) {
            $map = ['พม่า' => 'myanmar', 'เมียนมา' => 'myanmar', 'burma' => 'myanmar', 'กัมพูชา' => 'cambodia', 'เขมร' => 'cambodia', 'ลาว' => 'laos', 'lao' => 'laos', 'เวียดนาม' => 'vietnam'];
            $code = $map[$v] ?? null;
            $row = $code ? DB::table('nationality')->where('code', $code)->first() : null;
        }

        return $row ? $row->code : ['error' => 'ไม่รู้จักสัญชาติ "' . $value . '"'];
    }

    private function display(string $column, $value, array $resolved): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($column === 'company_id') {
            return ($resolved['company_id'][(int) $value] ?? optional(CompanyModel::find((int) $value))->company_name ?? '?') . ' (#' . $value . ')';
        }
        if ($column === 'labour_agency') {
            return ($resolved['agency_id'][(int) $value] ?? optional(AgencyModel::find((int) $value))->agency_name ?? '?') . ' (#' . $value . ')';
        }

        return (string) $value;
    }
}
