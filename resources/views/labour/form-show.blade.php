@extends('layouts.main_layout')

@section('title', 'ข้อมูลแรงงาน')
@section('crumb', 'แรงงานต่างด้าว / รายละเอียด')

@section('content')
    @php
        $canEdit = user_is('Admin', 'MasterAdmin');
        $documents = $labour->documentList();
        $docs = [
            ['icon' => 'mdi-book-open-page-variant', 'title' => 'หนังสือเดินทาง', 'number' => $labour->labour_passport_number,
             'rows' => [['วันที่ออกเล่ม', fmt_date($labour->labour_passport_date_start)], ['วันหมดอายุ', fmt_date($labour->labour_passport_date_end)]],
             'end' => $labour->labour_passport_date_end],
            ['icon' => 'mdi-airplane', 'title' => 'วีซ่า', 'number' => $labour->labour_visa_number,
             'rows' => [['วันที่เดินทางเข้ามา', fmt_date($labour->labour_visa_date_in)], ['วันที่เริ่มวีซ่า', fmt_date($labour->labour_visa_date_start)], ['วันหมดอายุ', fmt_date($labour->labour_visa_date_end)]],
             'end' => $labour->labour_visa_date_end],
            ['icon' => 'mdi-briefcase', 'title' => 'ใบอนุญาตทำงาน', 'number' => $labour->labour_workpremit_number,
             'rows' => [['วันที่เริ่มต้น', fmt_date($labour->labour_workpremit_date_start)], ['วันที่สิ้นสุด', fmt_date($labour->labour_workpremit_date_end)]],
             'end' => $labour->labour_workpremit_date_end],
            ['icon' => 'mdi-calendar-clock', 'title' => 'รายงานตัว 90 วัน', 'number' => null,
             'rows' => [['เริ่มต้น', fmt_date($labour->labour_day90_date_start)], ['สิ้นสุด', fmt_date($labour->labour_day90_date_end)], ['เลขที่ ตม.', $labour->labour_tm_number ?: '-']],
             'end' => $labour->labour_day90_date_end],
        ];
    @endphp

    <x-page-header title="รายละเอียดแรงงาน">
        @if ($canEdit)
            <a href="{{ route('labour.edit', $labour->labour_id) }}" class="btn btn-primary"><i class="mdi mdi-pencil"></i> แก้ไข</a>
        @endif
        @if (user_is('MasterAdmin'))
            <x-delete-form :action="route('labour.delete', $labour->labour_id)" size="" confirm="ต้องการลบข้อมูลแรงงาน {{ $labour->labour_fullname }} ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้" title="ยืนยันการลบแรงงาน">ลบ</x-delete-form>
        @endif
        <a href="{{ route('labour.index') }}" class="btn btn-soft"><i class="mdi mdi-arrow-left"></i> กลับ</a>
    </x-page-header>

    {{-- ---------------------------------------------------------- profile --}}
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            @if ($labour->hasPhoto())
                <a href="{{ $labour->photoUrl() }}" target="_blank" rel="noopener" title="เปิดรูปขนาดเต็ม"><img src="{{ $labour->photoUrl() }}" alt="รูปแรงงาน" class="avatar avatar-img labour-photo-lg"></a>
            @else
                <span class="avatar avatar-lg">{{ mb_strtoupper(mb_substr($labour->labour_fullname, 0, 1)) }}</span>
            @endif
            <div class="flex-grow-1 min-w-0">
                <h4 class="mb-1">{{ $labour->labour_prefix }}. {{ $labour->labour_fullname }}</h4>
                <div class="d-flex flex-wrap gap-2 align-items-center text-2 small">
                    <span><i class="mdi mdi-earth"></i> {{ $nationalityName }}</span>
                    <span>·</span>
                    <span>{{ sex_label($labour->labour_sex) }}</span>
                    @if (! is_blank_date($labour->labour_birthday))<span>·</span><span>เกิด {{ fmt_date($labour->labour_birthday) }} ({{ age_text($labour->labour_birthday) }})</span><span>·</span><span title="รหัสผ่านสำหรับ Worker App (วันเกิด ddmmyyyy)">รหัส Worker App <code>{{ fmt_date($labour->labour_birthday, 'dmY') }}</code></span>@endif
                    @if ($labour->labour_number)<span>·</span><span>รหัส {{ $labour->labour_number }}</span>@endif
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <x-status-badge :value="$labour->labour_status_job" type="job" class="fs-6" />
                <x-status-badge :value="$labour->labour_status" type="enable" class="fs-6" />
            </div>
        </div>
        <div class="card-body border-top pt-3">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-3 small">บริษัท / นายจ้าง</div>
                    <div class="fw-semibold">{{ $labour->company->company_name ?? '-' }}</div>
                    @if ($labour->company && $labour->company->company_contact_sale)<div class="small text-2">ผู้ประสานงาน: {{ $labour->company->company_contact_sale }}</div>@endif
                </div>
                <div class="col-md-4">
                    <div class="text-3 small">เอเจนซี่</div>
                    <div class="fw-semibold">{{ $labour->agency->agency_name ?? '-' }}</div>
                </div>
                <div class="col-md-2">
                    <div class="text-3 small">เริ่มเข้าทำงาน</div>
                    <div class="fw-semibold">{{ fmt_date($labour->labour_jobdate_start) }}</div>
                </div>
                <div class="col-md-2">
                    @if ($labour->labour_status_job === 'resign')
                        <div class="text-3 small">วันที่ลาออก</div><div class="fw-semibold">{{ fmt_date($labour->labour_resing_date) }}</div>
                    @elseif ($labour->labour_status_job === 'escape')
                        <div class="text-3 small">วันที่หลบหนี</div><div class="fw-semibold text-danger">{{ fmt_date($labour->labour_escape_date) }}</div>
                    @else
                        <div class="text-3 small">รหัสพนักงานที่นายจ้าง</div><div class="fw-semibold">{{ $labour->labour_labour_number ?: '-' }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------- documents --}}
    <div class="row g-3 mb-3">
        @foreach ($docs as $doc)
            <div class="col-md-6 col-xl-3">
                <div class="card doc-card">
                    <div class="card-body">
                        <div class="doc-head">
                            <h6><i class="mdi {{ $doc['icon'] }}"></i>{{ $doc['title'] }}</h6>
                            <x-days-left :date="$doc['end']" />
                        </div>
                        @if ($doc['number'] !== null)
                            <div class="num text-mono mb-2">{{ $doc['number'] ?: '-' }}</div>
                        @endif
                        <dl class="kv mb-0" style="grid-template-columns: 1fr auto">
                            @foreach ($doc['rows'] as [$k, $v])
                                <dt>{{ $k }}</dt><dd class="text-end">{{ $v }}</dd>
                            @endforeach
                        </dl>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ---------------------------------------------------------- documents --}}
    <div class="card mb-3">
        <div class="card-header">
            <i class="mdi mdi-paperclip"></i><h6 class="card-title">ไฟล์เอกสาร</h6>
            <span class="badge-soft badge-soft-secondary">{{ $documents->count() }} ไฟล์</span>
            @if ($canEdit)<a href="{{ route('labour.edit', $labour->labour_id) }}#sec-files" class="btn btn-soft btn-sm ms-auto"><i class="mdi mdi-plus"></i> เพิ่มเอกสาร</a>@endif
        </div>
        @if ($documents->isEmpty())
            <x-empty-state icon="mdi-paperclip" title="ยังไม่มีไฟล์แนบ" />
        @else
            <div class="table-wrap">
                <table class="table table-modern">
                    <thead>
                        <tr>
                            <th style="width:48px">#</th>
                            <th>ชื่อเอกสาร</th>
                            <th>ไฟล์</th>
                            <th class="d-none d-md-table-cell">ขนาด</th>
                            <th class="d-none d-lg-table-cell">อัปโหลดเมื่อ</th>
                            <th class="td-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $i => $doc)
                            @php $openUrl = $doc->exists ? $doc->url() : route('labour.document.openLegacy', [$labour->labour_id, $doc->legacy_column]); @endphp
                            <tr>
                                <td class="text-3">{{ $i + 1 }}</td>
                                <td class="fw-name">{{ $doc->name }} @if ($doc->disk === 'r2')<span class="badge-soft badge-soft-info ms-1">R2</span>@endif</td>
                                <td><a href="{{ $openUrl }}" target="_blank" rel="noopener" class="d-inline-flex align-items-center gap-2 text-reset"><i class="mdi {{ $doc->icon() }} text-danger fs-5"></i><span class="text-truncate" style="max-width:280px">{{ $doc->fileName() }}</span></a></td>
                                <td class="d-none d-md-table-cell text-2">{{ $doc->humanSize() }}</td>
                                <td class="d-none d-lg-table-cell text-2">{{ $doc->created_at ? fmt_datetime($doc->created_at) : '-' }}</td>
                                <td class="td-actions"><a href="{{ $openUrl }}" target="_blank" rel="noopener" class="btn btn-soft btn-sm"><i class="mdi mdi-open-in-new"></i> เปิด</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ---------------------------------------------------------- note & meta --}}
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header"><i class="mdi mdi-clipboard-text"></i><h6 class="card-title">หมายเหตุ</h6></div>
                <div class="card-body" style="white-space: pre-wrap">{{ $labour->labour_note ?: 'ไม่มีหมายเหตุ' }}</div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header"><i class="mdi mdi-history"></i><h6 class="card-title">ข้อมูลระบบ</h6></div>
                <div class="card-body">
                    <dl class="kv mb-0" style="grid-template-columns: 110px 1fr">
                        <dt>สร้างโดย</dt><dd>{{ $labour->created_by ?: '-' }}</dd>
                        <dt>สร้างเมื่อ</dt><dd>{{ fmt_datetime($labour->created_at) }}</dd>
                        <dt>แก้ไขโดย</dt><dd>{{ $labour->updated_by ?: '-' }}</dd>
                        <dt>แก้ไขเมื่อ</dt><dd>{{ fmt_datetime($labour->updated_at) }}</dd>
                        <dt>รหัสในระบบ</dt><dd class="text-mono">#{{ $labour->labour_id }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
