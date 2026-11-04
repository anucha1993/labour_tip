@extends('layouts.main_layout')

@section('title', 'คลังเอกสาร')
@section('crumb', 'คลังเอกสาร')

@php
    $subtitle = [
        'root' => 'เอกสารทุกไฟล์ของแรงงาน จัดเป็นโฟลเดอร์ตามบริษัทและรายชื่อ เปิดดูได้ทันทีเหมือน Google Drive',
        'company' => 'เลือกแรงงานเพื่อดูไฟล์เอกสารทั้งหมดของคนนั้น',
        'worker' => 'คลิกไฟล์เพื่อดูตัวอย่าง ดาวน์โหลด หรือจัดการไฟล์',
        'search' => 'ค้นหาจากชื่อแรงงาน เลขพาสปอร์ต ชื่อบริษัท ชื่อเอกสาร หรือชื่อไฟล์',
    ][$mode];

    // Turn a document row into the data-* attributes the preview panel reads.
    $fileAttrs = function ($doc, $labourRow = null) use ($canEdit, $canDelete) {
        $labourRow = $labourRow ?: $doc->labour;
        $persisted = (bool) $doc->exists;
        $openUrl = $persisted ? $doc->url() : route('labour.document.openLegacy', [$doc->labour_id, $doc->legacy_column]);
        return [
            'data-url' => $openUrl,
            'data-dl' => $persisted ? $openUrl . '?download=1' : $openUrl,
            'data-kind' => $doc->isImage() ? 'image' : 'pdf',
            'data-name' => $doc->name,
            'data-file' => $doc->original_name ?: $doc->fileName(),
            'data-size' => $doc->humanSize(),
            'data-date' => $doc->created_at ? fmt_datetime($doc->created_at) : '-',
            'data-by' => $doc->uploaded_by ?: '-',
            'data-disk' => $doc->disk === 'r2' ? 'Cloudflare R2' : 'เซิร์ฟเวอร์',
            'data-labour' => $labourRow ? trim($labourRow->labour_fullname . ' · ' . $labourRow->labour_passport_number, ' ·') : '',
            'data-labour-url' => $labourRow ? route('documents.index', ['labour' => $labourRow->labour_id]) : '',
            'data-rename' => $persisted && $canEdit ? route('documents.rename', $doc->id) : '',
            'data-delete' => $persisted && $canDelete ? route('labour.document.delete', $doc->id) : '',
        ];
    };
    $attrString = fn (array $a) => collect($a)->map(fn ($v, $k) => $k . '="' . e($v) . '"')->implode(' ');
    // JSON for the share dialog trigger (data-share). $shares = active DocumentShare rows of that folder.
    $shareData = fn (string $kind, $id, string $title, $shares) => json_encode([
        'kind' => $kind, 'id' => (int) $id, 'title' => $title,
        'shares' => collect($shares)->map(fn ($x) => $x->toDialogArray())->values()->all(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $emailLine = fn ($shares) => collect($shares)->flatMap(fn ($x) => $x->emails())->unique()->values();
@endphp

@section('content')
    <x-page-header title="คลังเอกสาร" :subtitle="$subtitle">
        @if ($mode === 'worker')
            @if ($zipAvailable)
                <a href="{{ route('documents.zip', $labour->labour_id) }}" class="btn btn-soft" data-no-loader><i class="mdi mdi-zip-box"></i> ดาวน์โหลดทั้งหมด (zip)</a>
            @endif
            <a href="{{ route('labour.show', $labour->labour_id) }}" class="btn btn-soft"><i class="mdi mdi-account"></i> ข้อมูลแรงงาน</a>
            @if ($canEdit)
                <button type="button" class="btn btn-primary" id="dxUploadBtn"><i class="mdi mdi-cloud-upload"></i> อัปโหลดไฟล์</button>
            @endif
        @endif
        @if ($canShare && $mode === 'company' && $company)
            <button type="button" class="btn btn-soft" data-share="{{ $shareData('company', $company->company_id, $company->company_name, $shares) }}"><i class="mdi mdi-link-variant"></i> แชร์โฟลเดอร์@if ($shares->isNotEmpty()) <span class="badge-soft badge-soft-primary">{{ $shares->count() }}</span>@endif</button>
        @elseif ($canShare && $mode === 'worker')
            <button type="button" class="btn btn-soft" data-share="{{ $shareData('labour', $labour->labour_id, $labour->labour_fullname, $shares) }}"><i class="mdi mdi-link-variant"></i> แชร์โฟลเดอร์@if ($shares->isNotEmpty()) <span class="badge-soft badge-soft-primary">{{ $shares->count() }}</span>@endif</button>
        @elseif ($canShare && $mode === 'root')
            <a href="{{ route('documents.shares') }}" class="btn btn-soft"><i class="mdi mdi-link-variant"></i> ลิงก์ที่แชร์</a>
        @endif
        <div class="btn-group" role="group" aria-label="รูปแบบการแสดงผล">
            <button type="button" class="btn btn-soft" data-dx-view="grid" title="แบบตาราง"><i class="mdi mdi-view-grid"></i></button>
            <button type="button" class="btn btn-soft" data-dx-view="list" title="แบบรายการ"><i class="mdi mdi-view-list"></i></button>
        </div>
    </x-page-header>

    {{-- ---------------------------------------------------------- breadcrumbs + search --}}
    <div class="dx-bar">
        <nav class="dx-crumbs" aria-label="ตำแหน่งปัจจุบัน">
            <a href="{{ route('documents.index') }}"><i class="mdi mdi-folder"></i> คลังเอกสาร</a>
            @foreach ($crumbs as $c)
                <i class="mdi mdi-chevron-right"></i>
                @if (! empty($c['url']) && ! $loop->last)
                    <a href="{{ $c['url'] }}">{{ $c['label'] }}</a>
                @else
                    <span class="cur" title="{{ $c['label'] }}">{{ $c['label'] }}</span>
                @endif
            @endforeach
        </nav>
        <form method="GET" action="{{ route('documents.index') }}" class="dx-search ms-auto d-flex gap-2">
            <div class="input-group">
                <span class="input-group-text bg-transparent border-end-0"><i class="mdi mdi-magnify"></i></span>
                <input type="search" name="q" class="form-control border-start-0" placeholder="ค้นหาไฟล์ทั้งระบบ: ชื่อ, พาสปอร์ต, บริษัท, ชื่อไฟล์" value="{{ $q }}">
            </div>
            <select name="type" class="form-select" style="max-width:170px" onchange="this.form.submit()">
                <option value="">ทุกประเภท</option>
                @foreach ($types as $t)
                    <option value="{{ $t->name }}" {{ $type === $t->name ? 'selected' : '' }}>{{ $t->name }} ({{ number_format($t->c) }})</option>
                @endforeach
            </select>
        </form>
    </div>

    @unless ($tableReady)
        <div class="alert alert-warning"><i class="mdi mdi-alert"></i> ยังไม่ได้สร้างตาราง labour_documents กรุณารัน <code>php artisan migrate</code> ก่อน คลังเอกสารจะแสดงเฉพาะไฟล์เดิมจากฟอร์มแรงงาน</div>
    @endunless

    <div class="dx-layout" id="dxLayout">
    <div class="dx-main">

    {{-- ========================================================== ROOT: companies --}}
    @if ($mode === 'root')
        @if ($stats)
            <div class="card mb-3"><div class="card-body py-3 dx-stats">
                <span><i class="mdi mdi-file-document"></i> ไฟล์ทั้งหมด <b>{{ number_format($stats->files) }}</b></span>
                <span><i class="mdi mdi-domain"></i> บริษัท <b>{{ number_format($folders->count()) }}</b></span>
                <span><i class="mdi mdi-cloud-outline"></i> พื้นที่ใช้ <b>{{ $stats->bytes >= 1024 ** 3 ? number_format($stats->bytes / 1024 ** 3, 2) . ' GB' : number_format($stats->bytes / 1024 ** 2, 0) . ' MB' }}</b></span>
                <span>บน Cloudflare R2 <b>{{ number_format((int) $stats->r2) }}</b> · บนเซิร์ฟเวอร์ <b>{{ number_format($stats->files - (int) $stats->r2) }}</b></span>
                <span class="ms-auto">
                    เรียงตาม
                    <a href="{{ route('documents.index', ['sort' => 'name']) }}" class="{{ $sort !== 'files' ? 'fw-semibold' : '' }}">ชื่อ</a> ·
                    <a href="{{ route('documents.index', ['sort' => 'files']) }}" class="{{ $sort === 'files' ? 'fw-semibold' : '' }}">จำนวนไฟล์</a>
                </span>
            </div></div>
        @endif

        @if ($types->isNotEmpty())
            <div class="dx-chips mb-3">
                @foreach ($types as $t)
                    <a href="{{ route('documents.index', ['type' => $t->name]) }}"><i class="mdi mdi-filter-variant"></i> {{ $t->name }} <span class="text-3">{{ number_format($t->c) }}</span></a>
                @endforeach
            </div>
        @endif

        <div class="d-flex align-items-center gap-2 mb-2">
            <h6 class="mb-0"><i class="mdi mdi-domain"></i> บริษัท / นายจ้าง</h6>
            <input type="search" class="form-control form-control-sm ms-auto" style="max-width:260px" placeholder="กรองชื่อบริษัทในหน้านี้" id="dxFolderFilter" autocomplete="off">
        </div>
        @if ($folders->isEmpty())
            <x-empty-state icon="mdi-folder" title="ยังไม่มีบริษัทในระบบ" />
        @else
            <div class="dx-grid folders mb-4" id="dxFolders">
                @foreach ($folders as $f)
                    @php $fs = $f['id'] === 'none' ? collect() : ($sharesByCompany[$f['id']] ?? collect()); $fe = $emailLine($fs); @endphp
                    <div class="dx-folder {{ $f['files'] === 0 ? 'muted' : '' }} {{ $fs->isNotEmpty() ? 'shared' : '' }}" data-name="{{ mb_strtolower($f['name']) }}">
                        <a href="{{ route('documents.index', ['company' => $f['id']]) }}" class="stretched-link" aria-label="เปิด {{ $f['name'] }}"></a>
                        <span class="ic"><i class="mdi {{ $fs->isNotEmpty() ? 'mdi-folder-account' : 'mdi-folder' }}"></i></span>
                        <span class="body">
                            <span class="t d-block" title="{{ $f['name'] }}">{{ $f['name'] }}</span>
                            <span class="m d-block">{{ number_format($f['workers']) }} คน · {{ number_format($f['files']) }} ไฟล์{{ $f['status'] === 'disable' ? ' · ปิดใช้งาน' : '' }}</span>
                            @if ($fs->isNotEmpty())<span class="sh d-block" title="{{ $fe->implode(', ') }}"><i class="mdi {{ $fs->contains(fn ($x) => $x->isRestricted()) ? 'mdi-lock' : 'mdi-link-variant' }}"></i> แชร์ให้ {{ $fe->isNotEmpty() ? $fe->implode(', ') : $fs->count() . ' ลิงก์' }}</span>@endif
                        </span>
                        @if ($canShare && $f['id'] !== 'none')
                            <div class="menu dropdown">
                                <button type="button" class="icon-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="เมนู {{ $f['name'] }}"><i class="mdi mdi-dots-vertical"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button type="button" class="dropdown-item" data-share="{{ $shareData('company', $f['id'], $f['name'], $fs) }}"><i class="mdi mdi-link-variant"></i> แชร์โฟลเดอร์@if ($fs->isNotEmpty()) <span class="badge-soft badge-soft-primary">{{ $fs->count() }}</span>@endif</button></li>
                                    <li><a class="dropdown-item" href="{{ route('documents.index', ['company' => $f['id']]) }}"><i class="mdi mdi-folder"></i> เปิดโฟลเดอร์</a></li>
                                </ul>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="dx-list d-none mb-4" id="dxFoldersList">
                <div class="card"><div class="table-wrap"><table class="table table-modern">
                    <thead><tr><th>บริษัท</th><th class="text-end">แรงงาน</th><th class="text-end">ไฟล์</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($folders as $f)
                        <tr data-name="{{ mb_strtolower($f['name']) }}">
                            <td class="fw-name"><i class="mdi mdi-folder text-warning"></i> <a href="{{ route('documents.index', ['company' => $f['id']]) }}" class="text-reset">{{ $f['name'] }}</a></td>
                            <td class="text-end">{{ number_format($f['workers']) }}</td>
                            <td class="text-end">{{ number_format($f['files']) }}</td>
                            <td class="td-actions"><a href="{{ route('documents.index', ['company' => $f['id']]) }}" class="btn btn-soft btn-sm">เปิด</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div></div>
            </div>
        @endif

        @if ($recent->isNotEmpty())
            <h6 class="mb-2"><i class="mdi mdi-history"></i> อัปโหลดล่าสุด</h6>
            <div class="dx-grid" id="dxRecent">
                @foreach ($recent as $doc)
                    @include('documents._file-card', ['doc' => $doc, 'attrs' => $fileAttrs($doc), 'showLabour' => true])
                @endforeach
            </div>
        @endif

    {{-- ========================================================== COMPANY: workers --}}
    @elseif ($mode === 'company')
        <form method="GET" action="{{ route('documents.index') }}" class="card mb-3">
            <input type="hidden" name="company" value="{{ $companyKey }}">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <div class="card-body py-3 d-flex flex-wrap gap-2 align-items-center">
                <div class="input-group" style="max-width:360px">
                    <span class="input-group-text bg-transparent border-end-0"><i class="mdi mdi-magnify"></i></span>
                    <input type="search" name="w" class="form-control border-start-0" placeholder="ชื่อหรือเลขพาสปอร์ตในบริษัทนี้" value="{{ $w }}">
                </div>
                <select name="status" class="form-select" style="max-width:160px" onchange="this.form.submit()">
                    <option value="">ทุกสถานะ</option>
                    <option value="job" {{ $status === 'job' ? 'selected' : '' }}>ทำงาน</option>
                    <option value="resign" {{ $status === 'resign' ? 'selected' : '' }}>ลาออก</option>
                    <option value="escape" {{ $status === 'escape' ? 'selected' : '' }}>หลบหนี</option>
                </select>
                <button class="btn btn-soft" type="submit">ค้นหา</button>
                <span class="ms-auto text-2 small">
                    {{ number_format($workers->total()) }} คน · เรียงตาม
                    <a href="{{ request()->fullUrlWithQuery(['sort' => 'name', 'page' => null]) }}" class="{{ $sort === 'name' ? 'fw-semibold' : '' }}">ชื่อ</a> ·
                    <a href="{{ request()->fullUrlWithQuery(['sort' => 'files', 'page' => null]) }}" class="{{ $sort === 'files' ? 'fw-semibold' : '' }}">จำนวนไฟล์</a> ·
                    <a href="{{ request()->fullUrlWithQuery(['sort' => 'newest', 'page' => null]) }}" class="{{ $sort === 'newest' ? 'fw-semibold' : '' }}">เพิ่มล่าสุด</a>
                </span>
            </div>
        </form>

        @if ($workers->isEmpty())
            <x-empty-state icon="mdi-account-multiple" title="ไม่พบแรงงานตามเงื่อนไข" />
        @else
            <div class="dx-grid folders" id="dxWorkers">
                @foreach ($workers as $wk)
                    @php $n = $tableReady ? (int) $wk->documents_count : $wk->documentList()->count(); @endphp
                    @php $ws = $sharesByLabour[$wk->labour_id] ?? collect(); $we = $emailLine($ws); @endphp
                    <div class="dx-folder person {{ $n === 0 ? 'muted' : '' }} {{ $ws->isNotEmpty() ? 'shared' : '' }}">
                        <a href="{{ route('documents.index', ['labour' => $wk->labour_id]) }}" class="stretched-link" aria-label="เปิด {{ $wk->labour_fullname }}"></a>
                        <span class="ic">@if ($wk->hasPhoto())<img src="{{ $wk->photoUrl() }}" alt="" loading="lazy">@else<i class="mdi mdi-account"></i>@endif</span>
                        <span class="body">
                            <span class="t d-block" title="{{ $wk->labour_fullname }}">{{ $wk->labour_fullname }}</span>
                            <span class="m d-block">{{ $wk->labour_passport_number ?: '-' }} · {{ $n }} ไฟล์ · {{ status_job_label($wk->labour_status_job) }}</span>
                            @if ($ws->isNotEmpty())<span class="sh d-block" title="{{ $we->implode(', ') }}"><i class="mdi {{ $ws->contains(fn ($x) => $x->isRestricted()) ? 'mdi-lock' : 'mdi-link-variant' }}"></i> แชร์ให้ {{ $we->isNotEmpty() ? $we->implode(', ') : $ws->count() . ' ลิงก์' }}</span>@endif
                        </span>
                        @if ($canShare)
                            <div class="menu dropdown">
                                <button type="button" class="icon-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="เมนู {{ $wk->labour_fullname }}"><i class="mdi mdi-dots-vertical"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button type="button" class="dropdown-item" data-share="{{ $shareData('labour', $wk->labour_id, $wk->labour_fullname, $ws) }}"><i class="mdi mdi-link-variant"></i> แชร์โฟลเดอร์@if ($ws->isNotEmpty()) <span class="badge-soft badge-soft-primary">{{ $ws->count() }}</span>@endif</button></li>
                                    <li><a class="dropdown-item" href="{{ route('documents.index', ['labour' => $wk->labour_id]) }}"><i class="mdi mdi-folder"></i> เปิดโฟลเดอร์</a></li>
                                </ul>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="dx-list d-none" id="dxWorkersList">
                <div class="card"><div class="table-wrap"><table class="table table-modern">
                    <thead><tr><th>ชื่อ</th><th>พาสปอร์ต</th><th>สถานะ</th><th class="text-end">ไฟล์</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($workers as $wk)
                        @php $n = $tableReady ? (int) $wk->documents_count : $wk->documentList()->count(); @endphp
                        <tr>
                            <td class="fw-name"><a href="{{ route('documents.index', ['labour' => $wk->labour_id]) }}" class="text-reset"><i class="mdi mdi-folder text-warning"></i> {{ $wk->labour_fullname }}</a></td>
                            <td class="text-mono">{{ $wk->labour_passport_number ?: '-' }}</td>
                            <td><x-status-badge :value="$wk->labour_status_job" /></td>
                            <td class="text-end">{{ $n }}</td>
                            <td class="td-actions"><a href="{{ route('documents.index', ['labour' => $wk->labour_id]) }}" class="btn btn-soft btn-sm">เปิด</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div></div>
            </div>
            <div class="mt-3">{{ $workers->links() }}</div>
        @endif

    {{-- ========================================================== WORKER: files --}}
    @elseif ($mode === 'worker')
        <div class="card mb-3"><div class="card-body py-3 dx-worker-head">
            @if ($labour->hasPhoto())
                <img src="{{ $labour->photoUrl() }}" alt="" class="photo">
            @else
                <div class="ph-empty"><i class="mdi mdi-account"></i></div>
            @endif
            <div class="min-w-0">
                <div class="fw-semibold fs-6">{{ $labour->labour_fullname }}</div>
                <div class="text-2 small">
                    <span class="text-mono">{{ $labour->labour_passport_number ?: '-' }}</span>
                    · {{ optional($labour->company)->company_name ?: 'ไม่ระบุบริษัท' }}
                    · <x-status-badge :value="$labour->labour_status_job" />
                </div>
            </div>
            <div class="ms-auto text-2 small">{{ $documents->count() }} ไฟล์</div>
        </div></div>

        @if ($canEdit)
            <form method="POST" action="{{ route('documents.upload') }}" enctype="multipart/form-data" id="dxUploadForm" class="card mb-3 {{ $errors->any() ? '' : 'd-none' }}">
                @csrf
                <input type="hidden" name="labour_id" value="{{ $labour->labour_id }}">
                <div class="card-body">
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-md-4">
                            <label class="form-label" for="dxDocName">ชื่อเอกสาร</label>
                            <input list="dxDocNames" name="name" id="dxDocName" class="form-control" value="{{ old('name', 'Passport') }}" maxlength="100" required>
                            <datalist id="dxDocNames">
                                @foreach (\App\Models\labour\LabourDocument::SUGGESTED_NAMES as $s)<option value="{{ $s }}">@endforeach
                            </datalist>
                        </div>
                        <div class="col-md-8 text-2 small">ไฟล์ PDF, JPG, PNG ขนาดไม่เกิน 10 MB ต่อไฟล์ เลือกได้หลายไฟล์ ทุกไฟล์ที่เลือกจะใช้ชื่อเอกสารเดียวกัน</div>
                    </div>
                    <label class="dx-drop d-block mb-0" id="dxDrop">
                        <i class="mdi mdi-cloud-upload"></i>
                        <span id="dxDropText">ลากไฟล์มาวางที่นี่ หรือคลิกเพื่อเลือกไฟล์</span>
                        <input type="file" name="files[]" id="dxFiles" multiple accept=".pdf,.jpg,.jpeg,.png" class="d-none">
                    </label>
                    @error('files')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    @error('files.*')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    @error('name')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary" id="dxUploadSubmit" disabled><i class="mdi mdi-upload"></i> อัปโหลด</button>
                        <button type="button" class="btn btn-soft" id="dxUploadCancel">ปิด</button>
                    </div>
                </div>
            </form>
        @endif

        @if ($documents->isEmpty())
            <x-empty-state icon="mdi-folder-outline" title="ยังไม่มีไฟล์เอกสารของแรงงานคนนี้" :text="$canEdit ? 'กดปุ่ม อัปโหลดไฟล์ หรือลากไฟล์มาวางเพื่อเพิ่มเอกสาร' : null" />
        @else
            <div class="dx-grid" id="dxFiles">
                @foreach ($documents as $doc)
                    @include('documents._file-card', ['doc' => $doc, 'attrs' => $fileAttrs($doc, $labour), 'showLabour' => false])
                @endforeach
            </div>
            <div class="dx-list d-none" id="dxFilesList">
                <div class="card"><div class="table-wrap"><table class="table table-modern">
                    <thead><tr><th style="width:40px"></th><th>ชื่อเอกสาร</th><th>ไฟล์</th><th class="d-none d-md-table-cell">ขนาด</th><th class="d-none d-lg-table-cell">อัปโหลดเมื่อ</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($documents as $doc)
                        @php $a = $fileAttrs($doc, $labour); @endphp
                        <tr class="dx-file-row" {!! $attrString($a) !!}>
                            <td><i class="mdi {{ $doc->icon() }} fs-5 {{ $doc->isImage() ? 'text-info' : 'text-danger' }}"></i></td>
                            <td class="fw-name">{{ $doc->name }}</td>
                            <td class="text-truncate" style="max-width:260px">{{ $a['data-file'] }}</td>
                            <td class="d-none d-md-table-cell text-2">{{ $doc->humanSize() }}</td>
                            <td class="d-none d-lg-table-cell text-2">{{ $a['data-date'] }}</td>
                            <td class="td-actions"><a href="{{ $a['data-url'] }}" target="_blank" rel="noopener" class="btn btn-soft btn-sm" data-no-loader onclick="event.stopPropagation()"><i class="mdi mdi-open-in-new"></i></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div></div>
            </div>
        @endif

    {{-- ========================================================== SEARCH --}}
    @else
        @if (! $results || $results->isEmpty())
            <x-empty-state icon="mdi-magnify" title="ไม่พบไฟล์ที่ตรงกับคำค้น" text="ลองค้นด้วยเลขพาสปอร์ตหรือบางส่วนของชื่อ">
                <a href="{{ route('documents.index') }}" class="btn btn-soft"><i class="mdi mdi-arrow-left"></i> กลับหน้าหลัก</a>
            </x-empty-state>
        @else
            <div class="text-2 small mb-2">พบ {{ number_format($results->total()) }} ไฟล์</div>
            <div class="dx-grid" id="dxResults">
                @foreach ($results as $doc)
                    @include('documents._file-card', ['doc' => $doc, 'attrs' => $fileAttrs($doc), 'showLabour' => true])
                @endforeach
            </div>
            <div class="dx-list d-none" id="dxResultsList">
                <div class="card"><div class="table-wrap"><table class="table table-modern">
                    <thead><tr><th style="width:40px"></th><th>ชื่อเอกสาร</th><th>แรงงาน</th><th class="d-none d-md-table-cell">บริษัท</th><th class="d-none d-lg-table-cell">อัปโหลดเมื่อ</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($results as $doc)
                        @php $a = $fileAttrs($doc); @endphp
                        <tr class="dx-file-row" {!! $attrString($a) !!}>
                            <td><i class="mdi {{ $doc->icon() }} fs-5 {{ $doc->isImage() ? 'text-info' : 'text-danger' }}"></i></td>
                            <td class="fw-name">{{ $doc->name }}<div class="sub text-truncate" style="max-width:220px">{{ $a['data-file'] }}</div></td>
                            <td>{{ optional($doc->labour)->labour_fullname ?: '-' }}<div class="sub text-mono">{{ optional($doc->labour)->labour_passport_number }}</div></td>
                            <td class="d-none d-md-table-cell text-2">{{ optional(optional($doc->labour)->company)->company_name ?: '-' }}</td>
                            <td class="d-none d-lg-table-cell text-2">{{ $a['data-date'] }}</td>
                            <td class="td-actions"><a href="{{ $a['data-url'] }}" target="_blank" rel="noopener" class="btn btn-soft btn-sm" data-no-loader onclick="event.stopPropagation()"><i class="mdi mdi-open-in-new"></i></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div></div>
            </div>
            <div class="mt-3">{{ $results->links() }}</div>
        @endif
    @endif

    </div>{{-- /dx-main --}}

    {{-- ---------------------------------------------------------- preview panel --}}
    <aside class="card dx-preview d-none" id="dxPreview" aria-live="polite">
        <div class="ph">
            <i class="mdi mdi-file-document text-danger fs-5" id="dxPvIcon"></i>
            <span class="t" id="dxPvTitle"></span>
            <button type="button" class="icon-btn" id="dxPvClose" aria-label="ปิดตัวอย่าง"><i class="mdi mdi-close"></i></button>
        </div>
        <div class="pv" id="dxPvBody"></div>
        <div class="pm">
            <dl class="kv mb-0">
                <dt>ไฟล์</dt><dd id="dxPvFile" class="text-break"></dd>
                <dt>แรงงาน</dt><dd id="dxPvLabour"></dd>
                <dt>ขนาด</dt><dd id="dxPvSize"></dd>
                <dt>อัปโหลด</dt><dd id="dxPvDate"></dd>
                <dt>โดย</dt><dd id="dxPvBy"></dd>
                <dt>เก็บที่</dt><dd id="dxPvDisk"></dd>
            </dl>
        </div>
        <div class="pa">
            <a href="#" class="btn btn-primary btn-sm" id="dxPvOpen" target="_blank" rel="noopener" data-no-loader><i class="mdi mdi-open-in-new"></i> เปิดเต็มจอ</a>
            <a href="#" class="btn btn-soft btn-sm" id="dxPvDownload" data-no-loader><i class="mdi mdi-download"></i> ดาวน์โหลด</a>
            <a href="#" class="btn btn-soft btn-sm d-none" id="dxPvFolder"><i class="mdi mdi-folder"></i> โฟลเดอร์แรงงาน</a>
            @if ($canEdit)
                <button type="button" class="btn btn-soft btn-sm d-none" id="dxPvRename"><i class="mdi mdi-pencil"></i> เปลี่ยนชื่อ</button>
            @endif
            @if ($canDelete)
                <form method="POST" action="#" id="dxPvDeleteForm" class="d-none" data-confirm="ลบไฟล์นี้ออกจากระบบและที่เก็บไฟล์ถาวร ใช่หรือไม่?" data-confirm-title="ยืนยันการลบไฟล์" data-confirm-ok="ลบ">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-soft-danger btn-sm"><i class="mdi mdi-delete"></i> ลบ</button>
                </form>
            @endif
        </div>
    </aside>
    </div>{{-- /dx-layout --}}

    @if ($canShare)
        @include('documents._share-modal')
    @endif

    @if ($canEdit)
        <div class="modal fade" id="dxRenameModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-sm modal-dialog-centered">
                <form method="POST" action="#" class="modal-content" id="dxRenameForm">
                    @csrf @method('PATCH')
                    <div class="modal-header"><h6 class="modal-title">เปลี่ยนชื่อเอกสาร</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
                    <div class="modal-body">
                        <input type="text" name="name" class="form-control" id="dxRenameInput" list="dxRenameNames" maxlength="100" required>
                        <datalist id="dxRenameNames">
                            @foreach (\App\Models\labour\LabourDocument::SUGGESTED_NAMES as $s)<option value="{{ $s }}">@endforeach
                        </datalist>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-soft" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-primary">บันทึก</button></div>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
(function () {
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

    // ---- grid / list toggle (remembered per browser)
    var view = 'grid';
    try { view = localStorage.getItem('dx_view') || 'grid'; } catch (e) {}
    function applyView(v) {
        view = v;
        $$('.dx-grid').forEach(function (el) { el.classList.toggle('d-none', v === 'list'); });
        $$('.dx-list').forEach(function (el) { el.classList.toggle('d-none', v !== 'list'); });
        $$('[data-dx-view]').forEach(function (b) { b.classList.toggle('btn-primary', b.dataset.dxView === v); b.classList.toggle('btn-soft', b.dataset.dxView !== v); });
        try { localStorage.setItem('dx_view', v); } catch (e) {}
    }
    $$('[data-dx-view]').forEach(function (b) { b.addEventListener('click', function () { applyView(b.dataset.dxView); }); });
    applyView(view);

    // ---- in-page company filter
    var ff = $('#dxFolderFilter');
    if (ff) ff.addEventListener('input', function () {
        var q = ff.value.trim().toLowerCase();
        $$('#dxFolders [data-name], #dxFoldersList [data-name]').forEach(function (el) { el.classList.toggle('d-none', q !== '' && el.dataset.name.indexOf(q) === -1); });
    });

    // ---- preview panel
    var layout = $('#dxLayout'), panel = $('#dxPreview'), body = $('#dxPvBody');
    var current = null;
    function closePreview() {
        panel.classList.add('d-none'); layout.classList.remove('has-preview'); body.innerHTML = '';
        $$('.dx-file.active, .dx-file-row.active').forEach(function (el) { el.classList.remove('active'); });
        current = null;
    }
    function showPreview(el) {
        var d = el.dataset;
        $$('.dx-file.active, .dx-file-row.active').forEach(function (x) { x.classList.remove('active'); });
        el.classList.add('active');
        current = d;
        $('#dxPvTitle').textContent = d.name;
        $('#dxPvIcon').className = 'mdi fs-5 ' + (d.kind === 'image' ? 'mdi-file-image text-info' : 'mdi-file-pdf text-danger');
        $('#dxPvFile').textContent = d.file;
        $('#dxPvLabour').textContent = d.labour || '-';
        $('#dxPvSize').textContent = d.size;
        $('#dxPvDate').textContent = d.date;
        $('#dxPvBy').textContent = d.by;
        $('#dxPvDisk').textContent = d.disk;
        $('#dxPvOpen').href = d.url;
        $('#dxPvDownload').href = d.dl;
        var folder = $('#dxPvFolder');
        folder.classList.toggle('d-none', !d.labourUrl || location.search.indexOf('labour=') !== -1);
        if (d.labourUrl) folder.href = d.labourUrl;
        var rn = $('#dxPvRename'); if (rn) rn.classList.toggle('d-none', !d.rename);
        var df = $('#dxPvDeleteForm'); if (df) { df.classList.toggle('d-none', !d.delete); df.action = d.delete || '#'; }
        body.innerHTML = '';
        if (d.kind === 'image') {
            var img = document.createElement('img'); img.src = d.url; img.alt = d.name; body.appendChild(img);
        } else {
            var fr = document.createElement('iframe'); fr.src = d.url + (d.url.indexOf('?') === -1 ? '?' : '&') + 'inline=1#toolbar=0'; fr.title = d.name; body.appendChild(fr);
        }
        panel.classList.remove('d-none'); layout.classList.add('has-preview');
        if (window.innerWidth < 768) panel.scrollIntoView();
    }
    $$('.dx-file, .dx-file-row').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (e.target.closest('a, button, form')) return;
            showPreview(el);
        });
    });
    $('#dxPvClose').addEventListener('click', closePreview);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && current) closePreview(); });

    // ---- rename
    var rnBtn = $('#dxPvRename'), rnModal = $('#dxRenameModal');
    if (rnBtn && rnModal && window.bootstrap) {
        rnBtn.addEventListener('click', function () {
            if (!current) return;
            $('#dxRenameForm').action = current.rename;
            $('#dxRenameInput').value = current.name;
            bootstrap.Modal.getOrCreateInstance(rnModal).show();
            setTimeout(function () { $('#dxRenameInput').select(); }, 200);
        });
    }

    // ---- share links: copy buttons + show the "link created" dialog once
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-copy]'); if (!b) return;
        var done = function () { var h = b.innerHTML; b.innerHTML = '<i class="mdi mdi-check"></i> คัดลอกแล้ว'; setTimeout(function () { b.innerHTML = h; }, 1500); };
        if (navigator.clipboard) navigator.clipboard.writeText(b.dataset.copy).then(done); else { var i = b.closest('.input-group').querySelector('input'); i.select(); document.execCommand('copy'); done(); }
    });
    // Lift a tile above its neighbours while its ... menu is open (the hover transform would otherwise trap the menu underneath).
    document.addEventListener('show.bs.dropdown', function (e) { var f = e.target.closest('.dx-folder'); if (f) f.classList.add('menu-open'); });
    document.addEventListener('hidden.bs.dropdown', function (e) { var f = e.target.closest('.dx-folder'); if (f) f.classList.remove('menu-open'); });
    var shareModal = $('#dxShareModal');
    function esc(t) { return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-share]'); if (!t || !shareModal || !window.bootstrap) return;
        e.preventDefault();
        var d; try { d = JSON.parse(t.dataset.share); } catch (err) { return; }
        $('#shTitle').textContent = d.title; $('#shKind').value = d.kind; $('#shTarget').value = d.id;
        var wrap = $('#shExistingWrap'), list = $('#shExisting');
        if (d.shares && d.shares.length) {
            $('#shExistingCount').textContent = d.shares.length;
            list.innerHTML = d.shares.map(function (x, i) {
                var chips = (x.list || []).map(function (em) {
                    return '<span class="sh-chip' + (x.restricted ? ' locked' : '') + '">' + esc(em) + ' <button type="button" class="x" data-remove-email="' + esc(em) + '" data-share-index="' + i + '" title="ลบสิทธิ์ ' + esc(em) + '">&times;</button></span>';
                }).join('');
                return '<div class="d-flex align-items-center gap-2 mb-1"><div class="input-group input-group-sm"><input type="text" class="form-control text-mono" value="' + esc(x.url) + '" readonly onclick="this.select()"><button type="button" class="btn btn-soft" data-copy="' + esc(x.url) + '" title="คัดลอก"><i class="mdi mdi-content-copy"></i></button></div>' +
                    '<button type="button" class="btn btn-soft-danger btn-sm" data-revoke="' + esc(x.revoke) + '" title="ยกเลิกลิงก์ทั้งหมด"><i class="mdi mdi-link-variant-off"></i></button></div>' +
                    '<div class="text-3 small">' + (x.restricted ? '<i class="mdi mdi-lock"></i> ' : '') + esc(x.summary) + '</div>' +
                    '<div class="sh-chips mb-2">' + chips + '<span class="sh-add"><input type="email" class="form-control form-control-sm" placeholder="เพิ่มอีเมล" data-add-input="' + i + '"><button type="button" class="btn btn-soft btn-sm" data-add-email="' + i + '"><i class="mdi mdi-plus"></i></button></span></div>';
            }).join('');
            list.dataset.shares = JSON.stringify(d.shares);
            wrap.classList.remove('d-none');
        } else { wrap.classList.add('d-none'); list.innerHTML = ''; }
        $('#shForm').reset();
        bootstrap.Modal.getOrCreateInstance(shareModal).show();
    });
    // Remove one recipient (the link stays; in restricted mode that person loses access) / add one.
    document.addEventListener('click', function (e) {
        var rm = e.target.closest('[data-remove-email]');
        var add = e.target.closest('[data-add-email]');
        if (!rm && !add) return;
        var shares = JSON.parse($('#shExisting').dataset.shares || '[]');
        var x = shares[(rm || add).dataset.shareIndex || add.dataset.addEmail];
        if (!x) return;
        var f, emails;
        if (rm) {
            emails = (x.list || []).filter(function (em) { return em !== rm.dataset.removeEmail; });
            if (x.restricted && emails.length === 0) { alert('ลิงก์โหมด "เฉพาะอีเมลที่ระบุ" ต้องเหลืออีเมลอย่างน้อย 1 รายการ ถ้าต้องการปิดทั้งลิงก์ให้กดยกเลิกลิงก์'); return; }
            f = $('#shUpdateForm'); $('#shUpdateEmails').value = emails.join(', ');
        } else {
            var inp = $('[data-add-input="' + add.dataset.addEmail + '"]'); var v = (inp.value || '').trim();
            if (!v || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { inp.focus(); inp.classList.add('is-invalid'); return; }
            emails = (x.list || []).concat([v.toLowerCase()]);
            f = $('#shAddForm'); $('#shAddEmails').value = emails.join(', ');
        }
        f.action = x.update;
        if (f.requestSubmit) f.requestSubmit(); else f.submit();
    });
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-revoke]'); if (!b) return;
        var f = $('#shRevokeForm'); f.action = b.dataset.revoke;
        if (f.requestSubmit) f.requestSubmit(); else f.submit();
    });
    var doneModal = $('#dxShareDone');
    if (doneModal && window.bootstrap) { bootstrap.Modal.getOrCreateInstance(doneModal).show(); setTimeout(function () { var i = $('#dxShareDoneUrl'); if (i) i.select(); }, 300); }

    // ---- upload (drag & drop)
    var form = $('#dxUploadForm'), input = $('#dxFiles'), drop = $('#dxDrop'), submit = $('#dxUploadSubmit');
    function openUpload() { if (form) { form.classList.remove('d-none'); form.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }
    var ub = $('#dxUploadBtn'); if (ub) ub.addEventListener('click', openUpload);
    var uc = $('#dxUploadCancel'); if (uc) uc.addEventListener('click', function () { form.classList.add('d-none'); });
    function describe() {
        var n = input.files.length;
        $('#dxDropText').textContent = n ? ('เลือกแล้ว ' + n + ' ไฟล์: ' + Array.prototype.map.call(input.files, function (f) { return f.name; }).join(', ')) : 'ลากไฟล์มาวางที่นี่ หรือคลิกเพื่อเลือกไฟล์';
        submit.disabled = n === 0;
    }
    if (form) {
        input.addEventListener('change', describe);
        ['dragenter', 'dragover'].forEach(function (ev) { document.addEventListener(ev, function (e) { if (e.dataTransfer && e.dataTransfer.types.indexOf('Files') !== -1) { e.preventDefault(); openUpload(); drop.classList.add('over'); } }); });
        ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('over'); }); });
        document.addEventListener('drop', function (e) {
            if (!e.dataTransfer || !e.dataTransfer.files.length) return;
            e.preventDefault();
            input.files = e.dataTransfer.files;
            describe();
        });
    }
})();
</script>
@endpush
