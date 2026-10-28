<?php

namespace App\Http\Controllers\labour;

use App\Http\Controllers\Controller;
use App\Models\agency\AgencyModel;
use App\Models\company\CompanyModel;
use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use App\Services\LabourPhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LabourController extends Controller
{
    /** Columns a user may filter / mass-update by. Never trust a raw column name from the request. */
    public const DATE_COLUMNS = [
        'labour_day90_date_end' => 'วันหมดอายุรายงานตัว 90 วัน',
        'labour_visa_date_end' => 'วันหมดอายุวีซ่า',
        'labour_workpremit_date_end' => 'วันหมดอายุใบอนุญาตทำงาน',
        'labour_passport_date_end' => 'วันหมดอายุหนังสือเดินทาง',
    ];

    private const PER_PAGE_OPTIONS = [10, 25, 50, 100, 200];

    private const FILE_RULE = 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240';
    private const MAX_DOCS_PER_SAVE = 30;

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $filters = [
            'company_id' => (int) $request->input('company_id'),
            'labour_status_job' => $request->input('labour_status_job'),
            'labour_status' => $request->input('labour_status'),
            'keyword' => trim((string) $request->input('keyword')),
            'column_name_type' => $request->input('column_name_type'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
        ];

        if (! array_key_exists($filters['column_name_type'], self::DATE_COLUMNS)) {
            $filters['column_name_type'] = null;
        }
        if (! in_array($filters['labour_status_job'], ['job', 'resign', 'escape'], true)) {
            $filters['labour_status_job'] = null;
        }
        if (! in_array($filters['labour_status'], ['enable', 'disable'], true)) {
            $filters['labour_status'] = null;
        }

        $perPage = (int) $request->input('per_page', 50);
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 50;
        }

        $dateColumn = $filters['column_name_type'];
        $hasDateRange = $dateColumn && $filters['start_date'] && $filters['end_date'];

        $labours = LabourModel::query()
            ->with(['company:company_id,company_name', 'agency:agency_id,agency_name'])
            ->when($filters['company_id'], fn ($q, $v) => $q->where('company_id', $v))
            ->when($filters['labour_status_job'], fn ($q, $v) => $q->where('labour_status_job', $v))
            ->when($filters['labour_status'], fn ($q, $v) => $q->where('labour_status', $v))
            ->when($hasDateRange, fn ($q) => $q->whereBetween($dateColumn, [$filters['start_date'], $filters['end_date']]))
            ->when($filters['keyword'], function ($q, $keyword) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('labour_fullname', 'LIKE', $like)
                        ->orWhere('labour_passport_number', 'LIKE', $like)
                        ->orWhere('labour_visa_number', 'LIKE', $like)
                        ->orWhere('labour_workpremit_number', 'LIKE', $like)
                        ->orWhere('labour_number', 'LIKE', $like)
                        ->orWhere('labour_labour_number', 'LIKE', $like)
                        ->orWhereHas('company', fn ($c) => $c->where('company_name', 'LIKE', $like))
                        ->orWhereHas('agency', fn ($a) => $a->where('agency_name', 'LIKE', $like));
                });
            })
            ->when($dateColumn, fn ($q) => $q->orderBy($dateColumn), fn ($q) => $q->orderByDesc('labour_id'))
            ->paginate($perPage)
            ->withQueryString();

        $companys = CompanyModel::orderBy('company_name')->get(['company_id', 'company_name']);

        return view('labour.index', [
            'labours' => $labours,
            'companys' => $companys,
            'filters' => $filters,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'dateColumns' => self::DATE_COLUMNS,
        ]);
    }

    public function create()
    {
        return view('labour.form-add', $this->formOptions() + ['labour' => new LabourModel(['labour_status' => 'enable', 'labour_status_job' => 'job'])]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->name;
        $data = $this->normalizeStatusDates($data);

        try {
            $labour = DB::transaction(function () use ($data, $request) {
                $labour = LabourModel::create($data);
                $this->storeUploads($request, $labour);
                app(LabourPhotoService::class)->sync($request, $labour);

                return $labour;
            });
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('labour.index')->with('success', 'เพิ่มข้อมูลแรงงาน ' . $labour->labour_fullname . ' เรียบร้อยแล้ว');
    }

    /** Inline profile photo (streams local files, redirects to a signed URL on R2). */
    public function photo(LabourModel $labourModel, LabourPhotoService $photos)
    {
        abort_unless($photos->exists($labourModel), 404);

        return $photos->response($labourModel);
    }

    public function show(LabourModel $labourModel)
    {
        $labourModel->load(LabourDocument::tableReady() ? ['company', 'agency', 'documents'] : ['company', 'agency']);
        $nationality = DB::table('nationality')->where('code', $labourModel->labour_nationality)->first();

        return view('labour.form-show', [
            'labour' => $labourModel,
            'nationalityName' => $nationality->name_th ?? ($labourModel->labour_nationality ?: '-'),
        ]);
    }

    public function edit(LabourModel $labourModel)
    {
        return view('labour.form-edit', $this->formOptions() + ['labour' => $labourModel]);
    }

    public function update(Request $request, LabourModel $labourModel)
    {
        $data = $this->validated($request, $labourModel);
        $data['updated_by'] = $request->user()->name;
        $data = $this->normalizeStatusDates($data);

        try {
            DB::transaction(function () use ($data, $request, $labourModel) {
                $oldPassport = $labourModel->labour_passport_number;
                $labourModel->update($data);

                // Uploaded documents live in a folder named after the passport number.
                // Keep them reachable when that number changes.
                if ($oldPassport && $oldPassport !== $labourModel->labour_passport_number) {
                    $this->moveDocumentFolder($labourModel, $oldPassport);
                }

                $this->storeUploads($request, $labourModel);
                app(LabourPhotoService::class)->sync($request, $labourModel);
            });
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('labour.index')->with('success', 'บันทึกข้อมูลแรงงาน ' . $labourModel->labour_fullname . ' เรียบร้อยแล้ว');
    }

    public function destroy(LabourModel $labourModel)
    {
        $folder = $labourModel->labour_passport_number;

        if ($labourModel->documentList()->isNotEmpty()) {
            return back()->with('error', 'ไม่สามารถลบข้อมูลได้ เนื่องจากยังมีไฟล์เอกสารของ ' . $labourModel->labour_fullname . ' อยู่ กรุณาลบเอกสารทั้งหมดก่อน');
        }

        if ($folder && Storage::disk('public')->exists($folder)) {
            if (count(Storage::disk('public')->files($folder)) > 0) {
                return back()->with('error', 'ไม่สามารถลบข้อมูลได้ เนื่องจากยังมีไฟล์เอกสารของ ' . $labourModel->labour_fullname . ' ค้างอยู่ กรุณาลบไฟล์ทั้งหมดก่อน');
            }
            Storage::disk('public')->deleteDirectory($folder);
        }

        app(LabourPhotoService::class)->remove($labourModel, false);
        $name = $labourModel->labour_fullname;
        $labourModel->delete();

        return redirect()->route('labour.index')->with('success', 'ลบข้อมูลแรงงาน ' . $name . ' เรียบร้อยแล้ว');
    }


    // ------------------------------------------------------------------ helpers

    private function formOptions(): array
    {
        return [
            'nationality' => DB::table('nationality')->orderBy('id')->get(),
            'agency' => AgencyModel::orderBy('agency_name')->get(['agency_id', 'agency_name']),
            'company' => CompanyModel::orderBy('company_name')->get(['company_id', 'company_name']),
        ];
    }

    private function validated(Request $request, ?LabourModel $existing = null): array
    {
        $passportRule = Rule::unique('labour', 'labour_passport_number');
        if ($existing) {
            $passportRule = $passportRule->ignore($existing->labour_id, 'labour_id');
        }

        $validated = $request->validate([
            'labour_prefix' => ['required', Rule::in(['Mr', 'Ms', 'Mrs'])],
            'labour_fullname' => ['required', 'string', 'max:255'],
            'labour_sex' => ['required', Rule::in(['male', 'female'])],
            'labour_nationality' => ['required', 'string', 'max:50', Rule::exists('nationality', 'code')],
            'labour_agency' => ['nullable', 'integer', Rule::exists('agency', 'agency_id')],
            'labour_birthday' => ['nullable', 'date'],
            'company_id' => ['nullable', 'integer', Rule::exists('company', 'company_id')],
            'labour_passport_number' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-]+$/', $passportRule],
            'labour_passport_date_start' => ['nullable', 'date'],
            'labour_passport_date_end' => ['nullable', 'date'],
            'labour_visa_number' => ['nullable', 'string', 'max:255'],
            'labour_visa_date_in' => ['nullable', 'date'],
            'labour_visa_date_start' => ['nullable', 'date'],
            'labour_visa_date_end' => ['nullable', 'date'],
            'labour_workpremit_number' => ['nullable', 'string', 'max:50'],
            'labour_labour_number' => ['nullable', 'string', 'max:50'],
            'labour_workpremit_date_start' => ['nullable', 'date'],
            'labour_workpremit_date_end' => ['nullable', 'date'],
            'labour_day90_date_start' => ['nullable', 'date'],
            'labour_day90_date_end' => ['nullable', 'date'],
            'labour_tm_number' => ['nullable', 'string', 'max:255'],
            'labour_number' => ['nullable', 'string', 'max:255'],
            'labour_jobdate_start' => ['nullable', 'date'],
            'labour_status_job' => ['required', Rule::in(['job', 'resign', 'escape'])],
            'labour_resing_date' => ['nullable', 'date'],
            'labour_escape_date' => ['nullable', 'date'],
            'labour_status' => ['required', Rule::in(['enable', 'disable'])],
            'labour_note' => ['nullable', 'string', 'max:5000'],
            'labour_photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_photo' => ['nullable', 'boolean'],
            'documents' => ['nullable', 'array', 'max:' . self::MAX_DOCS_PER_SAVE],
            'documents.*.name' => ['nullable', 'string', 'max:100', 'required_with:documents.*.file'],
            'documents.*.file' => self::FILE_RULE,
        ], [
            'labour_passport_number.required' => 'กรุณากรอกเลขที่หนังสือเดินทาง',
            'labour_passport_number.unique' => 'เลขที่หนังสือเดินทางนี้มีในระบบแล้ว',
            'labour_passport_number.regex' => 'เลขที่หนังสือเดินทางใช้ได้เฉพาะตัวอักษรภาษาอังกฤษ ตัวเลข และขีด (-)',
            'labour_fullname.required' => 'กรุณากรอกชื่อ-สกุล',
            'labour_nationality.required' => 'กรุณาเลือกสัญชาติ',
            'documents.*.name.required_with' => 'กรุณาระบุชื่อเอกสารของไฟล์ที่เลือก',
            'documents.*.file.mimes' => 'ไฟล์เอกสารต้องเป็น PDF, JPG หรือ PNG เท่านั้น',
            'documents.*.file.max' => 'ไฟล์เอกสารต้องมีขนาดไม่เกิน 10 MB',
            'labour_photo.image' => 'รูปแรงงานต้องเป็นไฟล์รูปภาพ',
            'labour_photo.mimes' => 'รูปแรงงานรองรับเฉพาะ JPG, PNG หรือ WEBP',
            'labour_photo.max' => 'รูปแรงงานต้องมีขนาดไม่เกิน 5 MB',
            'documents.max' => 'อัปโหลดได้ครั้งละไม่เกิน ' . self::MAX_DOCS_PER_SAVE . ' ไฟล์',
            '*.date' => 'รูปแบบวันที่ไม่ถูกต้อง',
        ]);

        unset($validated['documents'], $validated['labour_photo'], $validated['remove_photo']);
        $validated['labour_passport_number'] = strtoupper(trim($validated['labour_passport_number']));

        return $validated;
    }

    /** Clear the resign/escape date that does not belong to the chosen status. */
    private function normalizeStatusDates(array $data): array
    {
        if ($data['labour_status_job'] !== 'resign') {
            $data['labour_resing_date'] = null;
        }
        if ($data['labour_status_job'] !== 'escape') {
            $data['labour_escape_date'] = null;
        }

        return $data;
    }

    /**
     * Save the dynamic "documents[]" rows. Each row is { name, file }; rows without a file are ignored.
     * Files go to the disk configured in filesystems.labour_documents_disk (local or Cloudflare R2).
     */
    private function storeUploads(Request $request, LabourModel $labour): void
    {
        $rows = $request->file('documents', []);
        if (! is_array($rows) || ! $rows) {
            return;
        }
        if (! LabourDocument::tableReady()) {
            throw new \RuntimeException('ยังไม่ได้สร้างตาราง labour_documents กรุณารัน php artisan migrate ก่อนอัปโหลดเอกสาร');
        }

        $names = (array) $request->input('documents', []);
        $disk = LabourDocument::targetDisk();
        $nextOrder = (int) ($labour->documents()->max('sort_order') ?? 0) + 1;

        foreach ($rows as $index => $row) {
            $file = $row['file'] ?? null;
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $name = trim((string) ($names[$index]['name'] ?? '')) ?: 'Document';
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $slug = Str::slug($name, '-') ?: 'document';
            $path = $labour->labour_passport_number . '/' . now()->format('Ymd_His') . '_' . $slug . '_' . Str::lower(Str::random(4)) . '.' . $ext;

            $file->storeAs(dirname($path), basename($path), $disk);

            $labour->documents()->create([
                'name' => $name,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
                'sort_order' => $nextOrder++,
                'uploaded_by' => $request->user()->name,
            ]);
        }
    }

    private function moveDocumentFolder(LabourModel $labour, string $from): void
    {
        $to = $labour->labour_passport_number;
        $disk = Storage::disk('public');
        if (! $disk->exists($from)) {
            return;
        }

        $rename = fn (?string $name) => $name ? preg_replace('/^' . preg_quote($from, '/') . '_/', $to . '_', $name) : null;

        foreach ($disk->files($from) as $path) {
            // Files are named <passport>_<type>.<ext>; rename the prefix as well.
            $disk->move($path, $to . '/' . $rename(basename($path)));
        }
        $disk->deleteDirectory($from);

        $labour->update([
            'labour_file_passport' => $rename($labour->labour_file_passport),
            'labour_file_visa' => $rename($labour->labour_file_visa),
            'labour_file_work' => $rename($labour->labour_file_work),
        ]);

        // Document rows stored on the local disk moved with the folder; keep their paths in sync.
        foreach ((LabourDocument::tableReady() ? $labour->documents()->where('disk', 'public')->get() : collect()) as $doc) {
            if (str_starts_with($doc->path, $from . '/')) {
                $doc->update(['path' => $to . '/' . $rename(basename($doc->path))]);
            }
        }
    }

}
