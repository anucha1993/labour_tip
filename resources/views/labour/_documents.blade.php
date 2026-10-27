{{-- Document rows: existing documents (table) + dynamic rows for new uploads. Expects: $labour --}}
@php
    $existing = $labour->exists ? $labour->documentList() : collect();
    $canDeleteFile = user_is('MasterAdmin');
    $oldRows = collect(old('documents', []))->filter(fn ($r) => trim((string) ($r['name'] ?? '')) !== '');
@endphp

<datalist id="docNameList">
    @foreach (\App\Models\labour\LabourDocument::SUGGESTED_NAMES as $n)
        <option value="{{ $n }}">
    @endforeach
</datalist>

@if ($existing->isNotEmpty())
    <div class="table-wrap doc-existing border rounded-3">
        <table class="table table-modern mb-0">
            <thead>
                <tr>
                    <th style="width:48px">#</th>
                    <th style="width:28%">ชื่อเอกสาร</th>
                    <th>ไฟล์</th>
                    <th class="d-none d-md-table-cell">ขนาด</th>
                    <th class="d-none d-lg-table-cell">อัปโหลดเมื่อ</th>
                    <th class="td-actions"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($existing as $i => $doc)
                    @php
                        $openUrl = $doc->exists ? $doc->url() : route('labour.document.openLegacy', [$labour->labour_id, $doc->legacy_column]);
                        $deleteUrl = $doc->exists ? route('labour.document.delete', $doc->id) : route('labour.document.deleteLegacy', [$labour->labour_id, $doc->legacy_column]);
                    @endphp
                    <tr>
                        <td class="text-3">{{ $i + 1 }}</td>
                        <td class="fw-name">{{ $doc->name }}
                            @if ($doc->disk === 'r2')<span class="badge-soft badge-soft-info ms-1" title="เก็บบน Cloudflare R2">R2</span>@endif
                        </td>
                        <td>
                            <a href="{{ $openUrl }}" target="_blank" rel="noopener" class="d-inline-flex align-items-center gap-2 text-reset">
                                <i class="mdi {{ $doc->icon() }} text-danger fs-5"></i>
                                <span class="text-truncate" style="max-width:260px" title="{{ $doc->fileName() }}">{{ $doc->fileName() }}</span>
                                <i class="mdi mdi-open-in-new text-3"></i>
                            </a>
                        </td>
                        <td class="d-none d-md-table-cell text-2">{{ $doc->humanSize() }}</td>
                        <td class="d-none d-lg-table-cell text-2">{{ $doc->created_at ? fmt_datetime($doc->created_at) : '-' }}@if ($doc->uploaded_by) <span class="sub d-block">{{ $doc->uploaded_by }}</span>@endif</td>
                        <td class="td-actions">
                            <a href="{{ $openUrl }}" target="_blank" rel="noopener" class="btn btn-soft btn-sm" title="เปิดไฟล์"><i class="mdi mdi-eye"></i></a>
                            @if ($canDeleteFile)
                                <button type="button" class="btn btn-soft-danger btn-sm" title="ลบไฟล์" data-file-delete="{{ $deleteUrl }}" data-file-label="{{ $doc->name }}"><i class="mdi mdi-delete"></i></button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@elseif ($labour->exists)
    <div class="small text-3 mb-3"><i class="mdi mdi-paperclip"></i> ยังไม่มีไฟล์แนบ</div>
@endif

<div class="doc-rows" id="docRows" data-next-index="{{ $oldRows->count() }}">
    <div class="doc-row doc-row-head d-none d-md-grid" {{ $oldRows->isEmpty() ? "hidden" : "" }}>
        <div>#</div><div>ชื่อเอกสาร</div><div>ไฟล์ (PDF, JPG, PNG ไม่เกิน 10 MB)</div><div></div>
    </div>
    @foreach ($oldRows as $i => $row)
        <div class="doc-row">
            <div class="doc-no"></div>
            <div>
                <input type="text" name="documents[{{ $i }}][name]" class="form-control @error("documents.$i.name") is-invalid @enderror" list="docNameList" placeholder="เช่น Passport, Visa" value="{{ $row['name'] ?? '' }}" maxlength="100">
                @error("documents.$i.name")<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <input type="file" name="documents[{{ $i }}][file]" class="form-control @error("documents.$i.file") is-invalid @enderror" accept=".pdf,.jpg,.jpeg,.png">
                @error("documents.$i.file")<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text text-warning">เลือกไฟล์อีกครั้ง (เบราว์เซอร์ไม่เก็บไฟล์ไว้หลังหน้าโหลดใหม่)</div>
            </div>
            <div><button type="button" class="btn btn-soft-danger btn-sm" data-doc-remove title="เอาแถวออก"><i class="mdi mdi-close"></i></button></div>
        </div>
    @endforeach
</div>

<template id="docRowTpl">
    <div class="doc-row">
        <div class="doc-no"></div>
        <div><input type="text" name="documents[__i__][name]" class="form-control" list="docNameList" placeholder="เช่น Passport, Visa" maxlength="100"></div>
        <div><input type="file" name="documents[__i__][file]" class="form-control" accept=".pdf,.jpg,.jpeg,.png"></div>
        <div><button type="button" class="btn btn-soft-danger btn-sm" data-doc-remove title="เอาแถวออก"><i class="mdi mdi-close"></i></button></div>
    </div>
</template>

<button type="button" class="btn btn-soft mt-2" id="addDocRow"><i class="mdi mdi-plus"></i> เพิ่มเอกสาร</button>
<span class="small text-3 ms-2">เพิ่มได้ไม่จำกัด ไฟล์จะถูกบันทึกเมื่อกดปุ่มบันทึกด้านล่าง</span>
