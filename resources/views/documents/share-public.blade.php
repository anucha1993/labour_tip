@extends('layouts.public')

@section('title', $share->title())
@section('subtitle', $share->kind === 'company' ? 'เอกสารแรงงานของบริษัท' : 'เอกสารของแรงงาน')

@section('content')
    @php
        $fileAttrs = function ($doc, $labour) use ($share) {
            $persisted = (bool) $doc->exists;
            $url = $persisted ? route('share.file', [$share->token, $doc->id]) : route('share.legacy', [$share->token, $labour->labour_id, $doc->legacy_column]);
            return [
                'data-url' => $url,
                'data-dl' => $share->can_download ? $url . '?download=1' : '',
                'data-kind' => $doc->isImage() ? 'image' : 'pdf',
                'data-name' => $doc->name,
                'data-file' => $doc->original_name ?: $doc->fileName(),
                'data-size' => $doc->humanSize(),
                'data-date' => $doc->created_at ? fmt_datetime($doc->created_at) : '-',
                'data-labour' => trim($labour->labour_fullname . ' · ' . $labour->labour_passport_number, ' ·'),
            ];
        };
        $attrString = fn (array $a) => collect($a)->map(fn ($v, $k) => $k . '="' . e($v) . '"')->implode(' ');
        $rootUrl = route('share.show', $share->token);
        $isCompany = $share->kind === 'company';
    @endphp

    {{-- ---------------------------------------------------------- breadcrumb + toolbar (like Drive) --}}
    <div class="dx-bar">
        <nav class="dx-crumbs" aria-label="ตำแหน่งปัจจุบัน">
            @if ($isCompany)
                @if ($current)
                    <a href="{{ $rootUrl }}"><i class="mdi mdi-folder"></i> {{ $share->title() }}</a>
                    <i class="mdi mdi-chevron-right"></i>
                    <span class="cur">{{ $current->labour_fullname }}</span>
                @else
                    <span class="cur"><i class="mdi mdi-folder"></i> {{ $share->title() }}</span>
                @endif
            @else
                <span class="cur"><i class="mdi mdi-folder"></i> {{ $share->title() }}</span>
            @endif
        </nav>
        <div class="ms-auto d-flex align-items-center gap-2">
            @if ($isCompany && ! $current && $labours->count() > 5)
                <input type="search" class="form-control form-control-sm" style="width:240px" id="shFilter" placeholder="ค้นหาชื่อหรือพาสปอร์ต" autocomplete="off">
            @endif
            <div class="btn-group btn-group-sm" role="group" aria-label="รูปแบบการแสดงผล">
                <button type="button" class="btn btn-soft" data-dx-view="grid" title="แบบตาราง"><i class="mdi mdi-view-grid"></i></button>
                <button type="button" class="btn btn-soft" data-dx-view="list" title="แบบรายการ"><i class="mdi mdi-view-list"></i></button>
            </div>
        </div>
    </div>
    <div class="text-3 small mb-3">
        {{ $labours->count() }} คน · {{ $total }} ไฟล์ · {{ $share->expiryLabel() }} · {{ $share->can_download ? 'ดาวน์โหลดได้' : 'ดูอย่างเดียว' }}@if ($share->label) · {{ $share->label }}@endif @if (! empty($viewerEmail)) · <i class="mdi mdi-account"></i> {{ $viewerEmail }}@endif
    </div>

    <div class="dx-layout" id="dxLayout">
    <div class="dx-main">

    {{-- ========================================================== folders: one per worker --}}
    @if ($isCompany && ! $current)
        @if ($labours->isEmpty())
            <x-empty-state icon="mdi-folder-outline" title="ยังไม่มีแรงงานในบริษัทนี้" />
        @else
            <div class="dx-grid folders" id="shFolders">
                @foreach ($labours as $l)
                    @php $n = $countOf($l); @endphp
                    <a href="{{ $rootUrl }}?labour={{ $l->labour_id }}" class="dx-folder person {{ $n === 0 ? 'muted' : '' }}" data-name="{{ mb_strtolower($l->labour_fullname . ' ' . $l->labour_passport_number) }}">
                        <span class="ic"><i class="mdi mdi-folder"></i></span>
                        <span class="body">
                            <span class="t d-block" title="{{ $l->labour_fullname }}">{{ $l->labour_fullname }}</span>
                            <span class="m d-block">{{ $l->labour_passport_number ?: '-' }} · {{ $n }} ไฟล์</span>
                        </span>
                    </a>
                @endforeach
            </div>
            <div class="dx-list d-none" id="shFoldersList">
                <div class="card"><div class="table-wrap"><table class="table table-modern">
                    <thead><tr><th>ชื่อ</th><th>พาสปอร์ต</th><th class="text-end">ไฟล์</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($labours as $l)
                        <tr data-name="{{ mb_strtolower($l->labour_fullname . ' ' . $l->labour_passport_number) }}">
                            <td class="fw-name"><a href="{{ $rootUrl }}?labour={{ $l->labour_id }}" class="text-reset"><i class="mdi mdi-folder text-warning"></i> {{ $l->labour_fullname }}</a></td>
                            <td class="text-mono">{{ $l->labour_passport_number ?: '-' }}</td>
                            <td class="text-end">{{ $countOf($l) }}</td>
                            <td class="td-actions"><a href="{{ $rootUrl }}?labour={{ $l->labour_id }}" class="btn btn-soft btn-sm">เปิด</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div></div>
            </div>
        @endif

    {{-- ========================================================== files of one worker --}}
    @else
        @if ($documents->isEmpty())
            <x-empty-state icon="mdi-folder-outline" title="ยังไม่มีไฟล์ในโฟลเดอร์นี้" />
        @else
            <div class="dx-grid d-none" id="shFiles">
                @foreach ($documents as $doc)
                    @php $a = $fileAttrs($doc, $current); @endphp
                    <div class="dx-file" {!! $attrString($a) !!} role="button" tabindex="0" title="{{ $a['data-file'] }}">
                        <div class="thumb">
                            @if ($doc->isImage())<img src="{{ $a['data-url'] }}" alt="" loading="lazy">@else<i class="mdi {{ $doc->icon() }}"></i>@endif
                            <span class="ext">{{ $doc->extension() ?: 'file' }}</span>
                        </div>
                        <div class="body">
                            <div class="t">{{ $doc->name }}</div>
                            <div class="m">{{ $doc->humanSize() }} · {{ $a['data-date'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="dx-list" id="shFilesList">
                <div class="card"><div class="table-wrap"><table class="table table-modern">
                    <thead><tr><th style="width:40px"></th><th>ชื่อเอกสาร</th><th class="d-none d-md-table-cell">ไฟล์</th><th class="d-none d-sm-table-cell">ขนาด</th><th class="d-none d-lg-table-cell">อัปโหลดเมื่อ</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($documents as $doc)
                        @php $a = $fileAttrs($doc, $current); @endphp
                        <tr class="dx-file-row" {!! $attrString($a) !!}>
                            <td><i class="mdi {{ $doc->icon() }} fs-5 {{ $doc->isImage() ? 'text-info' : 'text-danger' }}"></i></td>
                            <td class="fw-name">{{ $doc->name }}</td>
                            <td class="d-none d-md-table-cell text-2 text-truncate" style="max-width:260px">{{ $a['data-file'] }}</td>
                            <td class="d-none d-sm-table-cell text-2">{{ $doc->humanSize() }}</td>
                            <td class="d-none d-lg-table-cell text-2">{{ $a['data-date'] }}</td>
                            <td class="td-actions">
                                <a href="{{ $a['data-url'] }}" target="_blank" rel="noopener" class="btn btn-soft btn-sm" title="เปิดเต็มจอ" data-no-loader onclick="event.stopPropagation()"><i class="mdi mdi-open-in-new"></i></a>
                                @if ($share->can_download)<a href="{{ $a['data-dl'] }}" class="btn btn-soft btn-sm" title="ดาวน์โหลด" data-no-loader onclick="event.stopPropagation()"><i class="mdi mdi-download"></i></a>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div></div>
            </div>
        @endif
    @endif
    </div>

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
            </dl>
        </div>
        <div class="pa">
            <a href="#" class="btn btn-primary btn-sm" id="dxPvOpen" target="_blank" rel="noopener" data-no-loader><i class="mdi mdi-open-in-new"></i> เปิดเต็มจอ</a>
            @if ($share->can_download)
                <a href="#" class="btn btn-soft btn-sm" id="dxPvDownload" data-no-loader><i class="mdi mdi-download"></i> ดาวน์โหลด</a>
            @endif
        </div>
    </aside>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

    // ---- grid / list toggle: folders default to grid, files default to list (like Drive); remembered per browser
    var key = $('#shFiles') ? 'sh_view_files' : 'sh_view_folders';
    var view = $('#shFiles') ? 'list' : 'grid';
    try { view = localStorage.getItem(key) || view; } catch (e) {}
    function applyView(v) {
        view = v;
        $$('.dx-grid').forEach(function (el) { el.classList.toggle('d-none', v === 'list'); });
        $$('.dx-list').forEach(function (el) { el.classList.toggle('d-none', v !== 'list'); });
        $$('[data-dx-view]').forEach(function (b) { b.classList.toggle('btn-primary', b.dataset.dxView === v); b.classList.toggle('btn-soft', b.dataset.dxView !== v); });
        try { localStorage.setItem(key, v); } catch (e) {}
    }
    $$('[data-dx-view]').forEach(function (b) { b.addEventListener('click', function () { applyView(b.dataset.dxView); }); });
    applyView(view);

    // ---- filter worker folders
    var f = $('#shFilter');
    if (f) f.addEventListener('input', function () {
        var q = f.value.trim().toLowerCase();
        $$('#shFolders [data-name], #shFoldersList [data-name]').forEach(function (el) { el.classList.toggle('d-none', q !== '' && el.dataset.name.indexOf(q) === -1); });
    });

    // ---- preview panel
    var layout = $('#dxLayout'), panel = $('#dxPreview'), body = $('#dxPvBody'), current = null;
    function close() { panel.classList.add('d-none'); layout.classList.remove('has-preview'); body.innerHTML = ''; $$('.dx-file.active, .dx-file-row.active').forEach(function (x) { x.classList.remove('active'); }); current = null; }
    function show(el) {
        var d = el.dataset;
        $$('.dx-file.active, .dx-file-row.active').forEach(function (x) { x.classList.remove('active'); });
        el.classList.add('active'); current = d;
        $('#dxPvTitle').textContent = d.name;
        $('#dxPvIcon').className = 'mdi fs-5 ' + (d.kind === 'image' ? 'mdi-file-image text-info' : 'mdi-file-pdf text-danger');
        $('#dxPvFile').textContent = d.file; $('#dxPvLabour').textContent = d.labour; $('#dxPvSize').textContent = d.size; $('#dxPvDate').textContent = d.date;
        $('#dxPvOpen').href = d.url;
        var dl = $('#dxPvDownload'); if (dl) dl.href = d.dl;
        body.innerHTML = '';
        if (d.kind === 'image') { var img = document.createElement('img'); img.src = d.url; img.alt = d.name; body.appendChild(img); }
        else { var fr = document.createElement('iframe'); fr.src = d.url + '#toolbar=0'; fr.title = d.name; body.appendChild(fr); }
        panel.classList.remove('d-none'); layout.classList.add('has-preview');
        if (window.innerWidth < 768) panel.scrollIntoView();
    }
    $$('.dx-file, .dx-file-row').forEach(function (el) { el.addEventListener('click', function (e) { if (e.target.closest('a, button')) return; show(el); }); });
    var c = $('#dxPvClose'); if (c) c.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && current) close(); });
})();
</script>
@endpush
