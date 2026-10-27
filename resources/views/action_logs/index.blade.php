@extends('layouts.main_layout')

@section('title', 'ประวัติการแก้ไข')
@section('crumb', 'การจัดการ / Action Logs')

@section('content')
    @php
        $actionMeta = [
            'created' => ['success', 'mdi-plus-circle', 'เพิ่ม'],
            'updated' => ['warning', 'mdi-pencil', 'แก้ไข'],
            'deleted' => ['danger', 'mdi-delete', 'ลบ'],
        ];
    @endphp

    <x-page-header title="ประวัติการแก้ไขข้อมูลแรงงาน" subtitle="บันทึกการเพิ่ม แก้ไข และลบข้อมูลแรงงานทุกครั้ง พร้อมค่าก่อน/หลัง" />

    <div class="card">
        <form method="GET" action="{{ route('action-logs.index') }}" class="card-body pb-0">
            <div class="row g-2">
                <div class="col-md-3">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0"><i class="mdi mdi-magnify"></i></span>
                        <input type="search" name="search" class="form-control border-start-0" placeholder="ชื่อแรงงาน, พาสปอร์ต, ผู้ทำรายการ" value="{{ $search }}">
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <select name="action" class="form-select">
                        <option value="">ทุกการกระทำ</option>
                        @foreach ($actionMeta as $k => [$c, $ic, $label])
                            <option value="{{ $k }}" {{ $action === $k ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="user_id" class="form-select">
                        <option value="">ทุกผู้ใช้</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" {{ $userId === (int) $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2"><input type="date" name="from" class="form-control" value="{{ $from }}" title="ตั้งแต่วันที่"></div>
                <div class="col-6 col-md-2"><input type="date" name="to" class="form-control" value="{{ $to }}" title="ถึงวันที่"></div>
                <div class="col-12 col-md-auto d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="mdi mdi-magnify"></i> ค้นหา</button>
                    <a href="{{ route('action-logs.index') }}" class="btn btn-soft" title="ล้าง"><i class="mdi mdi-refresh"></i></a>
                </div>
            </div>
        </form>

        @if ($logs->count() === 0)
            <x-empty-state icon="mdi-history" title="ไม่พบประวัติ" />
        @else
            <div class="table-wrap mt-3">
                <table class="table table-modern">
                    <thead>
                        <tr>
                            <th>เวลา</th>
                            <th>การกระทำ</th>
                            <th>แรงงาน</th>
                            <th>ผู้ทำรายการ</th>
                            <th>รายการที่เปลี่ยน</th>
                            <th class="td-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            @php
                                [$color, $icon, $label] = $actionMeta[$log->action_type] ?? ['secondary', 'mdi-information', $log->action_type];
                                $changed = $log->action_type === 'updated' && is_array($log->new_values) ? count(array_diff_key($log->new_values, ['updated_at' => 1, 'updated_by' => 1])) : null;
                            @endphp
                            <tr>
                                <td class="text-2" style="white-space:nowrap">{{ fmt_datetime($log->created_at) }}</td>
                                <td><span class="badge-soft badge-soft-{{ $color }}"><i class="mdi {{ $icon }}"></i> {{ $label }}</span></td>
                                <td>
                                    @if ($log->record)
                                        <div class="fw-name">{{ $log->record->labour_prefix }}. {{ $log->record->labour_fullname }}
                                            @if (! empty($log->record->deleted))<span class="badge-soft badge-soft-secondary ms-1">ถูกลบแล้ว</span>@endif
                                        </div>
                                        <div class="sub text-mono">{{ $log->record->labour_passport_number }} · #{{ $log->model_id }}</div>
                                    @else
                                        <span class="text-3">#{{ $log->model_id }}</span>
                                    @endif
                                </td>
                                <td>{{ optional($log->user)->name ?? '-' }}</td>
                                <td class="text-2">{{ $changed === null ? '-' : $changed . ' ฟิลด์' }}</td>
                                <td class="td-actions">
                                    <button type="button" class="btn btn-soft btn-sm" data-log-view data-action="{{ $log->action_type }}"
                                        data-old='@json($log->old_values ?? [])' data-new='@json($log->new_values ?? [])'
                                        data-title="{{ $label }} · {{ $log->record->labour_fullname ?? ('#' . $log->model_id) }} · {{ fmt_datetime($log->created_at) }}">
                                        <i class="mdi mdi-eye"></i> รายละเอียด
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="table-footer">{{ $logs->links() }}</div>
        @endif
    </div>

    <div class="modal fade" id="logModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="logModalTitle">รายละเอียด</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="table-wrap">
                        <table class="table table-modern mb-0">
                            <thead><tr><th style="width:30%">ฟิลด์</th><th>ค่าเดิม</th><th>ค่าใหม่</th></tr></thead>
                            <tbody id="logModalBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-soft" data-bs-dismiss="modal">ปิด</button></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        var labels = {
            labour_prefix: 'คำนำหน้า', labour_number: 'รหัสพนักงาน', labour_fullname: 'ชื่อ-สกุล', labour_sex: 'เพศ', labour_nationality: 'สัญชาติ',
            labour_agency: 'เอเจนซี่ (ID)', labour_birthday: 'วันเกิด', company_id: 'บริษัท (ID)', labour_passport_number: 'เลขที่หนังสือเดินทาง',
            labour_passport_date_start: 'วันที่ออกเล่ม', labour_passport_date_end: 'วันหมดอายุหนังสือเดินทาง', labour_visa_number: 'เลขที่วีซ่า',
            labour_visa_date_in: 'วันที่เดินทางเข้ามา', labour_visa_date_start: 'วันที่เริ่มวีซ่า', labour_visa_date_end: 'วันหมดอายุวีซ่า',
            labour_workpremit_number: 'เลขที่ใบอนุญาตทำงาน', labour_labour_number: 'รหัสพนักงานที่นายจ้าง', labour_workpremit_date_start: 'ใบอนุญาตเริ่มต้น',
            labour_workpremit_date_end: 'ใบอนุญาตสิ้นสุด', labour_day90_date_start: 'รายงานตัว 90 วัน เริ่มต้น', labour_day90_date_end: 'รายงานตัว 90 วัน สิ้นสุด',
            labour_tm_number: 'เลขที่ ตม.', labour_jobdate_start: 'วันที่เริ่มเข้าทำงาน', labour_resing_date: 'วันที่ลาออก', labour_escape_date: 'วันที่หลบหนี',
            labour_status_job: 'สถานะการทำงาน', labour_status: 'สถานะในระบบ', labour_note: 'หมายเหตุ', labour_file_passport: 'ไฟล์หนังสือเดินทาง',
            labour_file_visa: 'ไฟล์วีซ่า', labour_file_work: 'ไฟล์ใบอนุญาตทำงาน', created_by: 'สร้างโดย', updated_by: 'แก้ไขโดย',
            created_at: 'สร้างเมื่อ', updated_at: 'แก้ไขเมื่อ', labour_id: 'รหัสในระบบ'
        };
        var modal = new bootstrap.Modal(document.getElementById('logModal'));
        var body = document.getElementById('logModalBody');
        var title = document.getElementById('logModalTitle');

        function parse(raw) {
            try { var v = JSON.parse(raw || '{}'); if (typeof v === 'string') v = JSON.parse(v); return v && typeof v === 'object' ? v : {}; } catch (e) { return {}; }
        }
        function esc(v) {
            if (v === null || v === undefined || v === '') return '<span class="text-3">—</span>';
            return String(v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
        }

        document.querySelectorAll('[data-log-view]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var oldV = parse(btn.dataset.old), newV = parse(btn.dataset.new), action = btn.dataset.action;
                var keys = action === 'updated' ? Object.keys(newV) : Object.keys(action === 'created' ? newV : oldV);
                keys = keys.filter(function (k) { return k !== 'updated_at'; });
                title.textContent = btn.dataset.title;
                if (!keys.length) {
                    body.innerHTML = '<tr><td colspan="3" class="text-center text-3 py-4">ไม่มีรายละเอียดการเปลี่ยนแปลง</td></tr>';
                } else {
                    body.innerHTML = keys.map(function (k) {
                        var o = action === 'created' ? null : oldV[k], n = action === 'deleted' ? null : newV[k];
                        return '<tr><td><div class="fw-semibold">' + esc(labels[k] || k) + '</div><div class="sub text-mono">' + esc(k) + '</div></td>' +
                            '<td class="text-danger" style="white-space:pre-wrap">' + esc(o) + '</td>' +
                            '<td class="text-success" style="white-space:pre-wrap">' + esc(n) + '</td></tr>';
                    }).join('');
                }
                modal.show();
            });
        });
    })();
</script>
@endpush
