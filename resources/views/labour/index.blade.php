@extends('layouts.main_layout')

@section('title', 'แรงงานต่างด้าว')
@section('crumb', 'ข้อมูลแรงงานต่างด้าว')

@section('content')
    @php $canEdit = user_is('Admin', 'MasterAdmin'); $canDelete = user_is('MasterAdmin'); @endphp

    <x-page-header title="ข้อมูลแรงงานต่างด้าว" subtitle="ทั้งหมด {{ number_format($labours->total()) }} รายการตามเงื่อนไขที่เลือก">
        @if ($canEdit)
            <a href="{{ route('labour.create') }}" class="btn btn-primary"><i class="mdi mdi-plus"></i> เพิ่มแรงงาน</a>
            <a href="{{ route('labour.importform') }}" class="btn btn-soft-success"><i class="mdi mdi-file-excel"></i> นำเข้าจาก Excel</a>
        @endif
        <a href="{{ asset('file/from-import.xlsx') }}" class="btn btn-soft" download data-no-loader><i class="mdi mdi-download"></i> ฟอร์มกรอกข้อมูล</a>
    </x-page-header>

    {{-- ---------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('labour.index') }}" class="card mb-3" id="filterForm">
        <div class="card-body pb-2">
            <div class="row g-3">
                <div class="col-md-6 col-xl-4">
                    <label class="form-label" for="keyword">ค้นหา</label>
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0"><i class="mdi mdi-magnify"></i></span>
                        <input type="search" name="keyword" id="keyword" class="form-control border-start-0" placeholder="ชื่อ, เลขพาสปอร์ต, วีซ่า, ใบอนุญาต, บริษัท, เอเจนซี่" value="{{ $filters['keyword'] }}">
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label" for="company_id">บริษัท / นายจ้าง</label>
                    <select name="company_id" id="company_id" class="form-select js-select2" data-placeholder="ทั้งหมด" data-allow-clear>
                        <option value="">ทั้งหมด</option>
                        @foreach ($companys as $item)
                            <option value="{{ $item->company_id }}" {{ $filters['company_id'] === (int) $item->company_id ? 'selected' : '' }}>{{ $item->company_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="labour_status_job">สถานะแรงงาน</label>
                    <select name="labour_status_job" id="labour_status_job_filter" class="form-select">
                        <option value="">ทั้งหมด</option>
                        <option value="job" {{ $filters['labour_status_job'] === 'job' ? 'selected' : '' }}>ทำงาน</option>
                        <option value="resign" {{ $filters['labour_status_job'] === 'resign' ? 'selected' : '' }}>ลาออก</option>
                        <option value="escape" {{ $filters['labour_status_job'] === 'escape' ? 'selected' : '' }}>หลบหนี</option>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-3">
                    <label class="form-label" for="labour_status">สถานะในระบบ</label>
                    <select name="labour_status" id="labour_status" class="form-select">
                        <option value="">ทั้งหมด</option>
                        <option value="enable" {{ $filters['labour_status'] === 'enable' ? 'selected' : '' }}>เปิดใช้งาน</option>
                        <option value="disable" {{ $filters['labour_status'] === 'disable' ? 'selected' : '' }}>ปิดใช้งาน</option>
                    </select>
                </div>

                <div class="col-md-4 col-xl-3">
                    <label class="form-label" for="column_name_type">กรองตามวันหมดอายุ</label>
                    <select name="column_name_type" id="column_name_type" class="form-select">
                        <option value="">ไม่กรองตามวันที่</option>
                        @foreach ($dateColumns as $col => $label)
                            <option value="{{ $col }}" {{ $filters['column_name_type'] === $col ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <x-form.input wrapper="col-6 col-md-3 col-xl-2" name="start_date" type="date" label="ตั้งแต่วันที่" :value="$filters['start_date']" />
                <x-form.input wrapper="col-6 col-md-3 col-xl-2" name="end_date" type="date" label="ถึงวันที่" :value="$filters['end_date']" />
                <div class="col-md-2 col-xl-2">
                    <label class="form-label" for="per_page">แสดงต่อหน้า</label>
                    <select name="per_page" id="per_page" class="form-select" data-autosubmit>
                        @foreach ($perPageOptions as $n)
                            <option value="{{ $n }}" {{ $perPage === $n ? 'selected' : '' }}>{{ $n }} รายการ</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary flex-fill"><i class="mdi mdi-filter"></i> ค้นหา</button>
                    <a href="{{ route('labour.index') }}" class="btn btn-soft"><i class="mdi mdi-refresh"></i> ล้าง</a>
                </div>
            </div>
        </div>
    </form>

    {{-- ---------------------------------------------------------- list --}}
    <form method="POST" action="{{ route('labour.massupdate') }}" id="massForm"
        data-confirm="ยืนยันอัปเดตวันที่ให้แรงงานที่เลือกทั้งหมด?" data-confirm-title="Mass Update" data-confirm-ok="อัปเดต" data-confirm-class="btn-primary">
        @csrf
        <div class="card">
            <div class="card-header flex-wrap">
                <h5 class="card-title">รายการแรงงาน</h5>
                <span class="badge-soft badge-soft-secondary">{{ number_format($labours->total()) }} รายการ</span>
                @if ($canEdit)
                    <div class="form-check form-switch ms-auto mb-0">
                        <input class="form-check-input" type="checkbox" id="massToggle">
                        <label class="form-check-label small" for="massToggle"><i class="mdi mdi-table-edit"></i> Mass Update วันหมดอายุ</label>
                    </div>
                @endif
            </div>

            @if ($canEdit)
                <div class="mass-bar mx-3 mt-3" id="massBar">
                    <div class="flex-grow-1" style="min-width:220px">
                        <label class="form-label required" for="column_name">เงื่อนไขที่ต้องการอัปเดต</label>
                        <select name="column_name" id="column_name" class="form-select" required>
                            <option value="">เลือกเงื่อนไข</option>
                            @foreach ($dateColumns as $col => $label)
                                <option value="{{ $col }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="min-width:180px">
                        <label class="form-label required" for="mass_date">วันที่ใหม่</label>
                        <input type="date" name="mass_date" id="mass_date" class="form-control" required>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary" id="massSubmit" disabled><i class="mdi mdi-content-save"></i> อัปเดต <span class="badge bg-white text-primary ms-1" id="massCount">0</span> รายการ</button>
                    </div>
                </div>
            @endif

            @if ($labours->count() === 0)
                <x-empty-state icon="mdi-account-off" title="ไม่พบข้อมูลแรงงาน" text="ลองปรับเงื่อนไขการค้นหา หรือเพิ่มแรงงานใหม่">
                    <a href="{{ route('labour.index') }}" class="btn btn-soft btn-sm"><i class="mdi mdi-refresh"></i> ล้างการค้นหา</a>
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table class="table table-modern">
                        <thead>
                            <tr>
                                @if ($canEdit)<th style="width:40px"><input type="checkbox" class="form-check-input" id="checkAll" title="เลือกทั้งหมดในหน้านี้"></th>@endif
                                <th style="width:48px">#</th>
                                <th>ชื่อ-สกุล</th>
                                <th>หนังสือเดินทาง</th>
                                <th>วีซ่า</th>
                                <th>บริษัท / นายจ้าง</th>
                                @if ($filters['column_name_type'])<th>{{ $dateColumns[$filters['column_name_type']] }}</th>@endif
                                <th>เอเจนซี่</th>
                                <th>สถานะ</th>
                                <th class="td-actions"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($labours as $i => $item)
                                <tr>
                                    @if ($canEdit)<td><input type="checkbox" class="form-check-input" name="labour_ids[]" value="{{ $item->labour_id }}"></td>@endif
                                    <td class="text-3">{{ $labours->firstItem() + $i }}</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            @if ($item->hasPhoto())
                                                <img src="{{ $item->photoUrl() }}" alt="" class="avatar avatar-img" style="width:34px;height:34px" loading="lazy">
                                            @else
                                                <span class="avatar" style="width:34px;height:34px;font-size:.8rem">{{ mb_strtoupper(mb_substr($item->labour_fullname, 0, 1)) }}</span>
                                            @endif
                                            <div class="min-w-0">
                                                <a href="{{ route('labour.show', $item->labour_id) }}" class="fw-name">{{ $item->labour_prefix }}. {{ $item->labour_fullname }}</a>
                                                <div class="sub">{{ sex_label($item->labour_sex) }}@if ($item->labour_labour_number) · รหัส {{ $item->labour_labour_number }}@endif</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-mono">{{ $item->labour_passport_number ?: '-' }}</div>
                                        <div class="sub">{{ days_left_text($item->labour_passport_date_end) }}</div>
                                    </td>
                                    <td>
                                        <div class="text-mono">{{ $item->labour_visa_number ?: '-' }}</div>
                                        <div class="sub">{{ days_left_text($item->labour_visa_date_end) }}</div>
                                    </td>
                                    <td>{{ $item->company->company_name ?? '-' }}</td>
                                    @if ($filters['column_name_type'])
                                        <td>
                                            <div>{{ fmt_date($item->{$filters['column_name_type']}) }}</div>
                                            <x-days-left :date="$item->{$filters['column_name_type']}" />
                                        </td>
                                    @endif
                                    <td>{{ $item->agency->agency_name ?? '-' }}</td>
                                    <td>
                                        <div class="d-flex flex-column align-items-start gap-1">
                                            <x-status-badge :value="$item->labour_status_job" type="job" />
                                            @if ($item->labour_status !== 'enable')<x-status-badge :value="$item->labour_status" type="enable" />@endif
                                        </div>
                                    </td>
                                    <td class="td-actions">
                                        <a href="{{ route('labour.show', $item->labour_id) }}" class="btn btn-soft btn-sm" title="ดูข้อมูล"><i class="mdi mdi-eye"></i></a>
                                        @if ($canEdit)
                                            <a href="{{ route('labour.edit', $item->labour_id) }}" class="btn btn-soft btn-sm" title="แก้ไข"><i class="mdi mdi-pencil"></i></a>
                                        @endif
                                        @if ($canDelete)
                                            <button type="button" class="btn btn-soft-danger btn-sm" title="ลบ" data-delete-url="{{ route('labour.delete', $item->labour_id) }}" data-delete-name="{{ $item->labour_fullname }}"><i class="mdi mdi-delete"></i></button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="table-footer">{{ $labours->links() }}</div>
            @endif
        </div>
    </form>

    @if ($canDelete)
        {{-- One delete form outside the mass-update form (forms cannot nest). --}}
        <form method="POST" id="deleteForm" data-confirm="" data-confirm-title="ยืนยันการลบแรงงาน" data-confirm-ok="ลบ" class="d-none">
            @csrf
            @method('DELETE')
        </form>
    @endif
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-delete-url]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.getElementById('deleteForm');
            form.action = btn.dataset.deleteUrl;
            form.dataset.confirm = 'ต้องการลบข้อมูลแรงงาน "' + btn.dataset.deleteName + '" ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้';
            form.dataset.confirmed = '0';
            form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        });
    });
</script>
@endpush
