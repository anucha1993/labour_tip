<?php

namespace App\Http\Controllers\documents;

use App\Http\Controllers\Controller;
use App\Models\company\CompanyModel;
use App\Models\DocumentShare;
use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use App\Services\DocumentZipService;
use App\Services\LabourDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * "คลังเอกสาร": browse every uploaded file like a drive.
 *
 *   /documents                  companies as folders (+ recent uploads, storage summary)
 *   /documents?company=ID       workers of that company as folders ("none" = no company)
 *   /documents?labour=ID        files of one worker, with preview / upload / rename / zip
 *   /documents?q=...&type=...   search across all files
 */
class DocumentExplorerController extends Controller
{
    private const PER_PAGE = 60;

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->input('q'));
        $type = trim((string) $request->input('type'));
        $company = $request->input('company');
        $labourId = (int) $request->input('labour');
        $sort = in_array($request->input('sort'), ['name', 'newest', 'files'], true) ? $request->input('sort') : 'name';

        $base = [
            'q' => $q,
            'type' => $type,
            'sort' => $sort,
            'types' => $this->types(),
            'canEdit' => user_is('Admin', 'MasterAdmin'),
            'canDelete' => user_is('MasterAdmin'),
            'tableReady' => LabourDocument::tableReady(),
            'zipMaxFiles' => DocumentZipService::maxFiles(),
            'zipMaxBytes' => DocumentZipService::maxBytes(),
            'canShare' => user_is('Admin', 'MasterAdmin') && DocumentShare::tableReady(),
        ];

        if ($labourId) {
            return $this->worker($labourId, $base);
        }
        if ($q !== '' || $type !== '') {
            return $this->search($base);
        }
        if ($company !== null && $company !== '') {
            return $this->company($company, $request, $base);
        }

        return $this->root($base);
    }

    // ------------------------------------------------------------------ views

    private function root(array $base)
    {
        $workersPer = LabourModel::query()
            ->select('company_id', DB::raw('COUNT(*) AS c'))
            ->groupBy('company_id')
            ->pluck('c', 'company_id');

        $filesPer = $base['tableReady']
            ? LabourDocument::query()
                ->join('labour', 'labour.labour_id', '=', 'labour_documents.labour_id')
                ->select('labour.company_id', DB::raw('COUNT(*) AS c'))
                ->groupBy('labour.company_id')
                ->pluck('c', 'company_id')
            : collect();

        $folders = CompanyModel::query()
            ->orderBy('company_name')
            ->get(['company_id', 'company_name', 'company_status'])
            ->map(fn ($c) => [
                'id' => $c->company_id,
                'name' => $c->company_name,
                'status' => $c->company_status,
                'workers' => (int) ($workersPer[$c->company_id] ?? 0),
                'files' => (int) ($filesPer[$c->company_id] ?? 0),
            ]);

        // Workers without a company get their own folder (null, 0 and '' all mean "none").
        $noCompany = fn ($q) => $q->where(fn ($w) => $w->whereNull('labour.company_id')->orWhere('labour.company_id', 0)->orWhere('labour.company_id', ''));
        $noCompanyWorkers = $noCompany(LabourModel::query())->count();
        if ($noCompanyWorkers > 0) {
            $folders->push([
                'id' => 'none',
                'name' => 'ไม่ระบุบริษัท',
                'status' => 'enable',
                'workers' => $noCompanyWorkers,
                'files' => $base['tableReady']
                    ? $noCompany(LabourDocument::query()->join('labour', 'labour.labour_id', '=', 'labour_documents.labour_id'))->count()
                    : 0,
            ]);
        }

        if ($base['sort'] === 'files') {
            $folders = $folders->sortByDesc('files')->values();
        }

        $stats = $base['tableReady']
            ? LabourDocument::query()->selectRaw('COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes, SUM(disk = ?) AS r2', ['r2'])->first()
            : null;

        $recent = $base['tableReady']
            ? LabourDocument::query()->with('labour.company')->orderByDesc('id')->limit(12)->get()
            : collect();

        $sharesByCompany = $base['canShare']
            ? DocumentShare::active()->where('kind', 'company')->orderByDesc('id')->get()->groupBy('target_id')
            : collect();

        return view('documents.index', $base + [
            'mode' => 'root',
            'sharesByCompany' => $sharesByCompany,
            'folders' => $folders,
            'stats' => $stats,
            'recent' => $recent,
            'crumbs' => [],
        ]);
    }

    private function company($company, Request $request, array $base)
    {
        $model = null;
        $query = LabourModel::query();

        if ($company === 'none') {
            $query->where(fn ($w) => $w->whereNull('company_id')->orWhere('company_id', 0)->orWhere('company_id', ''));
            $title = 'ไม่ระบุบริษัท';
        } else {
            $model = CompanyModel::find((int) $company);
            abort_unless($model, 404);
            $query->where('company_id', $model->company_id);
            $title = $model->company_name;
        }

        $w = trim((string) $request->input('w'));
        if ($w !== '') {
            $like = '%' . $w . '%';
            $query->where(fn ($x) => $x->where('labour_fullname', 'LIKE', $like)->orWhere('labour_passport_number', 'LIKE', $like));
        }
        if ($status = $request->input('status')) {
            if (in_array($status, ['job', 'resign', 'escape'], true)) {
                $query->where('labour_status_job', $status);
            }
        }

        if ($base['tableReady']) {
            $query->withCount('documents')->withSum('documents', 'size');
        }

        $workers = $query
            ->when($base['sort'] === 'files' && $base['tableReady'], fn ($q) => $q->orderByDesc('documents_count'))
            ->when($base['sort'] === 'newest', fn ($q) => $q->orderByDesc('labour_id'))
            ->orderBy('labour_fullname')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $sharesByLabour = $base['canShare']
            ? DocumentShare::active()->where('kind', 'labour')->whereIn('target_id', $workers->pluck('labour_id'))->orderByDesc('id')->get()->groupBy('target_id')
            : collect();

        return view('documents.index', $base + [
            'mode' => 'company',
            'sharesByLabour' => $sharesByLabour,
            'company' => $model,
            'companyKey' => $company,
            'title' => $title,
            'workers' => $workers,
            'w' => $w,
            'status' => $status,
            'crumbs' => [['label' => $title]],
            'shares' => $model && $base['canShare'] ? DocumentShare::active()->for('company', $model->company_id)->orderByDesc('id')->get() : collect(),
        ]);
    }

    private function worker(int $labourId, array $base)
    {
        $labour = LabourModel::with('company')->findOrFail($labourId);
        $documents = $labour->documentList();

        $companyCrumb = $labour->company
            ? ['label' => $labour->company->company_name, 'url' => route('documents.index', ['company' => $labour->company_id])]
            : ['label' => 'ไม่ระบุบริษัท', 'url' => route('documents.index', ['company' => 'none'])];

        return view('documents.index', $base + [
            'mode' => 'worker',
            'labour' => $labour,
            'documents' => $documents,
            'crumbs' => [$companyCrumb, ['label' => $labour->labour_fullname]],
            'zipAvailable' => class_exists(\ZipArchive::class) && $documents->isNotEmpty(),
            'shares' => $base['canShare'] ? DocumentShare::active()->for('labour', $labour->labour_id)->orderByDesc('id')->get() : collect(),
        ]);
    }

    private function search(array $base)
    {
        $results = $base['tableReady']
            ? LabourDocument::query()
                ->with('labour.company')
                ->when($base['type'] !== '', fn ($q) => $q->where('name', $base['type']))
                ->when($base['q'] !== '', function ($q) use ($base) {
                    $like = '%' . $base['q'] . '%';
                    $q->where(function ($w) use ($like) {
                        $w->where('name', 'LIKE', $like)
                            ->orWhere('original_name', 'LIKE', $like)
                            ->orWhere('path', 'LIKE', $like)
                            ->orWhereHas('labour', function ($l) use ($like) {
                                $l->where('labour_fullname', 'LIKE', $like)
                                    ->orWhere('labour_passport_number', 'LIKE', $like)
                                    ->orWhereHas('company', fn ($c) => $c->where('company_name', 'LIKE', $like));
                            });
                    });
                })
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString()
            : null;

        return view('documents.index', $base + [
            'mode' => 'search',
            'results' => $results,
            'crumbs' => [['label' => 'ผลการค้นหา']],
        ]);
    }

    // ------------------------------------------------------------------ actions

    /** Admin+: upload one or more files to a worker straight from the explorer. */
    public function upload(Request $request, LabourDocumentService $service)
    {
        $data = $request->validate([
            'labour_id' => ['required', 'integer', 'exists:labour,labour_id'],
            'name' => ['required', 'string', 'max:100'],
            'files' => ['required', 'array', 'min:1', 'max:' . LabourDocumentService::MAX_PER_REQUEST],
            'files.*' => ['file', 'mimes:' . LabourDocumentService::ALLOWED_MIMES, 'max:' . LabourDocumentService::MAX_KB],
        ], [
            'files.required' => 'กรุณาเลือกไฟล์',
            'files.max' => 'อัปโหลดได้ครั้งละไม่เกิน ' . LabourDocumentService::MAX_PER_REQUEST . ' ไฟล์',
            'files.*.mimes' => 'ไฟล์เอกสารต้องเป็น PDF, JPG หรือ PNG เท่านั้น',
            'files.*.max' => 'ไฟล์เอกสารต้องมีขนาดไม่เกิน 10 MB',
            'name.required' => 'กรุณาระบุชื่อเอกสาร',
        ]);

        $labour = LabourModel::findOrFail($data['labour_id']);
        $count = 0;
        foreach ($request->file('files', []) as $file) {
            if ($file && $file->isValid()) {
                $service->store($labour, $file, $data['name'], $request->user()->name);
                $count++;
            }
        }

        return redirect()
            ->route('documents.index', ['labour' => $labour->labour_id])
            ->with('success', 'อัปโหลด ' . $count . ' ไฟล์ให้ ' . $labour->labour_fullname . ' เรียบร้อยแล้ว');
    }

    /** Admin+: change the document label (the file itself is untouched). */
    public function rename(Request $request, LabourDocument $document)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']], ['name.required' => 'กรุณาระบุชื่อเอกสาร']);
        $document->update(['name' => trim($data['name'])]);

        return redirect()
            ->route('documents.index', ['labour' => $document->labour_id])
            ->with('success', 'เปลี่ยนชื่อเอกสารเป็น "' . $document->name . '" แล้ว');
    }

    /** Download every file of one worker as a single (streamed) zip. */
    public function zip(LabourModel $labourModel, DocumentZipService $zipper)
    {
        try {
            $entries = $zipper->entries(collect([$labourModel]), collect());
        } catch (\InvalidArgumentException $e) {
            abort(404, 'แรงงานคนนี้ยังไม่มีไฟล์เอกสาร');
        }

        return $zipper->stream($entries, DocumentZipService::nameFor(collect([$labourModel]), collect()));
    }

    /**
     * Download a selection (worker folders and/or single files, from the checkboxes) as one streamed zip.
     * Too big a selection comes back to the page with a warning instead of starting the download.
     */
    public function zipSelected(Request $request, DocumentZipService $zipper)
    {
        $data = $request->validate([
            'labours' => ['nullable', 'array', 'max:' . DocumentZipService::maxFiles()],
            'labours.*' => ['integer'],
            'documents' => ['nullable', 'array', 'max:' . DocumentZipService::maxFiles()],
            'documents.*' => ['integer'],
        ]);
        $labours = ! empty($data['labours']) ? LabourModel::with('company')->whereIn('labour_id', $data['labours'])->get() : collect();
        $documents = ! empty($data['documents']) && LabourDocument::tableReady()
            ? LabourDocument::with('labour.company')->whereIn('id', $data['documents'])->get()
            : collect();

        try {
            $entries = $zipper->entries($labours, $documents);
        } catch (\InvalidArgumentException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return $zipper->stream($entries, DocumentZipService::nameFor($labours, $documents));
    }

    // ------------------------------------------------------------------ helpers

    /** Document labels in use, most common first (for the type filter). */
    private function types()
    {
        if (! LabourDocument::tableReady()) {
            return collect();
        }

        return LabourDocument::query()
            ->select('name', DB::raw('COUNT(*) AS c'))
            ->groupBy('name')
            ->orderByDesc('c')
            ->limit(15)
            ->get();
    }
}
