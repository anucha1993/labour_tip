@extends('layouts.main_layout')

@section('title', 'โปรไฟล์สิทธิ์')
@section('crumb', 'กำหนดว่าแต่ละโปรไฟล์ทำอะไรได้บ้าง')

@section('content')
    @php
        $isMaster = $current['key'] === $master;
        $badge = ['MasterAdmin' => 'danger', 'Admin' => 'primary', 'Member' => 'info'];
        $total = count(\App\Support\Permissions::all());
        $locked = $isMaster || ! $ready;
    @endphp

    <x-page-header title="โปรไฟล์สิทธิ์" subtitle="เลือกโปรไฟล์ เปิด/ปิดแต่ละเมนู แล้วเลือกว่าทำอะไรได้บ้าง (ดู เพิ่ม แก้ไข ลบ) กดบันทึกแล้วมีผลกับทุกคนในโปรไฟล์ทันที">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#roleAddModal"><i class="mdi mdi-plus"></i> สร้างโปรไฟล์</button>
        <a href="{{ route('member.index') }}" class="btn btn-soft"><i class="mdi mdi-account-key"></i> สมาชิก</a>
    </x-page-header>

    @if (! $ready)
        <div class="alert alert-warning"><i class="mdi mdi-database-alert"></i> ยังไม่ได้สร้างตารางสิทธิ์ ระบบกำลังใช้ค่าเริ่มต้น (MasterAdmin ทำได้ทุกอย่าง, Admin เพิ่ม/แก้ไข, Member ดูอย่างเดียว) กรุณารัน <code>php artisan migrate</code> บน server ก่อนจึงจะแก้ได้</div>
    @endif
    @if ($orphans->isNotEmpty())
        <div class="alert alert-danger"><i class="mdi mdi-account-alert"></i> มีผู้ใช้ที่อยู่ในโปรไฟล์ที่ไม่มีแล้ว: <b>{{ $orphans->implode(', ') }}</b> ผู้ใช้เหล่านี้จะไม่มีสิทธิ์อะไรเลย กรุณาเปลี่ยนโปรไฟล์ให้ในหน้า <a href="{{ route('member.index') }}">สมาชิก</a></div>
    @endif

    <div class="row g-3">
        {{-- ------------------------------------------------ profile list --}}
        <div class="col-lg-3">
            <div class="d-lg-none mb-2">
                <select class="form-select" onchange="location.href=this.value">
                    @foreach ($roles as $r)
                        <option value="{{ route('roles.index', $r['key']) }}" {{ $r['key'] === $current['key'] ? 'selected' : '' }}>{{ $r['name'] }} ({{ $r['key'] }}) · {{ $r['users'] }} คน</option>
                    @endforeach
                </select>
            </div>
            <div class="card d-none d-lg-block">
                <div class="list-group list-group-flush" id="profileList">
                    @foreach ($roles as $r)
                        <a href="{{ route('roles.index', $r['key']) }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2 {{ $r['key'] === $current['key'] ? 'active' : '' }}">
                            <i class="mdi {{ $r['key'] === $master ? 'mdi-security' : ($r['is_system'] ? 'mdi-security' : 'mdi-shield-outline') }} fs-5"></i>
                            <span class="flex-grow-1 text-truncate">
                                <span class="d-block fw-name">{{ $r['name'] }}</span>
                                <span class="sub">{{ $r['key'] }} · {{ number_format($r['users']) }} คน · {{ count($r['permissions']) }}/{{ $total }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------ selected profile --}}
        <div class="col-lg-9">
            <form method="POST" action="{{ route('roles.update', $current['key']) }}" id="permForm" class="card">
                @csrf
                <div class="card-header flex-wrap gap-2">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h5 class="card-title mb-0">{{ $current['name'] }}</h5>
                            <span class="badge-soft badge-soft-{{ $badge[$current['key']] ?? 'warning' }}">{{ $current['key'] }}</span>
                            @if ($current['is_system'])<span class="badge-soft badge-soft-secondary">โปรไฟล์พื้นฐาน</span>@endif
                            @if ($isMaster)<span class="badge-soft badge-soft-danger"><i class="mdi mdi-lock"></i> ทุกสิทธิ์ แก้ไม่ได้</span>@endif
                        </div>
                        <div class="sub mt-1">{{ $current['description'] ?: 'ไม่มีคำอธิบาย' }} · ผู้ใช้ {{ number_format($current['users']) }} คน · <span id="permCount">{{ count($current['permissions']) }}</span>/{{ $total }} สิทธิ์</div>
                    </div>
                    @if ($ready)
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-soft btn-sm" data-rename-role data-key="{{ $current['key'] }}" data-name="{{ $current['name'] }}" data-description="{{ $current['description'] }}" data-action="{{ route('roles.rename', $current['key']) }}"><i class="mdi mdi-pencil"></i> ชื่อ / คำอธิบาย</button>
                            @if ($current['is_system'] && ! $isMaster)
                                <button type="button" class="btn btn-soft btn-sm" data-post="{{ route('roles.reset', $current['key']) }}" data-confirm="คืนค่าเริ่มต้นของโปรไฟล์ {{ $current['name'] }}? สิทธิ์ที่ตั้งไว้จะถูกแทนที่" data-confirm-title="คืนค่าเริ่มต้น" data-confirm-ok="คืนค่า"><i class="mdi mdi-restore"></i> คืนค่าเริ่มต้น</button>
                            @endif
                            @if (! $current['is_system'])
                                <button type="button" class="btn btn-soft-danger btn-sm" data-post="{{ route('roles.destroy', $current['key']) }}" data-method="DELETE" data-confirm="ลบโปรไฟล์ {{ $current['name'] }}? (ต้องไม่มีผู้ใช้อยู่ในโปรไฟล์นี้)" data-confirm-title="ยืนยันการลบ" data-confirm-ok="ลบ"><i class="mdi mdi-delete"></i> ลบ</button>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="perm-modules">
                    <div class="perm-head">สิทธิ์พื้นฐาน</div>
                    @foreach ($groups as $gkey => $g)
                        @php
                            $keys = array_keys($g['items']);
                            $on = $isMaster ? $keys : array_values(array_intersect($keys, $current['permissions']));
                        @endphp
                        <div class="perm-module {{ $on ? 'on' : '' }}" data-module="{{ $gkey }}">
                            <div class="name"><i class="mdi {{ $g['icon'] }}"></i> {{ $g['label'] }}</div>
                            <div class="switch">
                                <div class="form-check form-switch m-0">
                                    <input type="checkbox" class="form-check-input mod-toggle" role="switch" id="mod_{{ $gkey }}" {{ $on ? 'checked' : '' }} {{ $locked ? 'disabled' : '' }} aria-label="เปิด/ปิด {{ $g['label'] }}">
                                </div>
                            </div>
                            <div class="acts">
                                <div class="dropdown">
                                    <button type="button" class="btn btn-link p-0 text-reset text-decoration-none mod-summary {{ $on ? '' : 'd-none' }}" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" {{ $locked ? 'disabled' : '' }}>
                                        <span class="txt">{{ collect($on)->map(fn ($p) => $g['items'][$p][0])->implode(', ') }}</span> <i class="mdi mdi-chevron-down"></i>
                                    </button>
                                    <div class="dropdown-menu p-2 shadow-sm" style="min-width:300px">
                                        @foreach ($g['items'] as $perm => [$short, $desc])
                                            <label class="dropdown-item d-flex align-items-start gap-2 py-1 rounded" for="p_{{ str_replace('.', '_', $perm) }}" style="white-space:normal;cursor:pointer">
                                                <input type="checkbox" class="form-check-input mt-1 perm" name="perms[]" value="{{ $perm }}" id="p_{{ str_replace('.', '_', $perm) }}" data-short="{{ $short }}" {{ in_array($perm, $on, true) ? 'checked' : '' }} {{ $locked ? 'disabled' : '' }}>
                                                <span><span class="d-block fw-name">{{ $short }}</span><span class="sub">{{ $desc }}</span></span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <span class="text-3 small mod-off {{ $on ? 'd-none' : '' }}">ปิด ไม่เห็นเมนูนี้</span>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="card-footer d-flex flex-wrap align-items-center gap-2">
                    <span class="text-2 small"><i class="mdi mdi-information-outline"></i> @if ($isMaster) โปรไฟล์นี้มีทุกสิทธิ์เสมอ ใช้สำหรับผู้ดูแลระบบสูงสุดเท่านั้น @else ผู้ที่กำลังใช้งานอยู่จะได้สิทธิ์ใหม่ทันทีในหน้าถัดไป ไม่ต้องออกจากระบบ โปรไฟล์ของคุณเองจะคง "โปรไฟล์สิทธิ์: จัดการ" ไว้เสมอ @endif</span>
                    @unless ($isMaster)
                        <button type="submit" class="btn btn-primary ms-auto" {{ $locked ? 'disabled' : '' }}><i class="mdi mdi-content-save"></i> บันทึกสิทธิ์</button>
                    @endunless
                </div>
            </form>
        </div>
    </div>

    {{-- add profile --}}
    <div class="modal fade" id="roleAddModal" tabindex="-1" aria-labelledby="roleAddTitle" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('roles.store') }}" class="modal-content">
                @csrf
                <div class="modal-header"><h5 class="modal-title" id="roleAddTitle"><i class="mdi mdi-account-key"></i> สร้างโปรไฟล์ใหม่</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required" for="role_key">รหัสโปรไฟล์ (อังกฤษ)</label>
                        <input type="text" class="form-control {{ $errors->has('key') ? 'is-invalid' : '' }}" name="key" id="role_key" required pattern="[A-Za-z][A-Za-z0-9_]{1,29}" placeholder="เช่น Head, HR_Staff" value="{{ old('key') }}">
                        @error('key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">ใช้ในระบบ เปลี่ยนภายหลังไม่ได้ อักษรอังกฤษ ตัวเลข หรือขีดล่าง 2-30 ตัว</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required" for="role_name">ชื่อที่แสดง</label>
                        <input type="text" class="form-control" name="name" id="role_name" required maxlength="100" placeholder="เช่น หัวหน้าฝ่าย" value="{{ old('name') }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="role_desc">คำอธิบาย</label>
                        <input type="text" class="form-control" name="description" id="role_desc" maxlength="255" value="{{ old('description') }}">
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="role_copy">เริ่มจากสิทธิ์ของ</label>
                        <select class="form-select" name="copy_from" id="role_copy">
                            <option value="">— ยังไม่มีสิทธิ์ (เปิดเองทีหลัง) —</option>
                            @foreach ($roles as $r)
                                <option value="{{ $r['key'] }}" {{ old('copy_from') === $r['key'] ? 'selected' : '' }}>{{ $r['name'] }} ({{ $r['key'] }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-soft" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-primary" {{ $ready ? '' : 'disabled' }}><i class="mdi mdi-plus"></i> สร้างโปรไฟล์</button></div>
            </form>
        </div>
    </div>

    {{-- rename profile --}}
    <div class="modal fade" id="roleRenameModal" tabindex="-1" aria-labelledby="roleRenameTitle" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="" class="modal-content" id="roleRenameForm">
                @csrf
                @method('PATCH')
                <div class="modal-header"><h5 class="modal-title" id="roleRenameTitle"><i class="mdi mdi-pencil"></i> แก้ไขโปรไฟล์ <code id="roleRenameKey"></code></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label required" for="rename_name">ชื่อที่แสดง</label><input type="text" class="form-control" name="name" id="rename_name" required maxlength="100"></div>
                    <div class="mb-1"><label class="form-label" for="rename_desc">คำอธิบาย</label><input type="text" class="form-control" name="description" id="rename_desc" maxlength="255"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-soft" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> บันทึก</button></div>
            </form>
        </div>
    </div>

    <form method="POST" id="roleActionForm" class="d-none" data-confirm="" data-confirm-title="" data-confirm-ok="">@csrf<input type="hidden" name="_method" value="POST" id="roleActionMethod"></form>

    <style>
        .perm-head { padding: 14px 20px 6px; font-weight: 700; }
        .perm-module { display: grid; grid-template-columns: minmax(170px, 1fr) 60px 2fr; align-items: center; gap: 12px; padding: 12px 20px; border-top: 1px solid var(--border); }
        .perm-module .name { font-weight: 600; }
        .perm-module .name .mdi { color: var(--text-3, #94a3b8); margin-right: 4px; }
        .perm-module.on .name .mdi { color: var(--brand-600); }
        .perm-module:not(.on) .name { color: var(--text-3, #94a3b8); }
        .perm-module .form-check-input { width: 2.4em; height: 1.3em; cursor: pointer; }
        .perm-module .form-check-input:checked { background-color: #16a34a; border-color: #16a34a; }
        .mod-summary { font-weight: 500; }
        .mod-summary .mdi { color: var(--text-3, #94a3b8); }
        #profileList .list-group-item.active .sub { color: rgba(255, 255, 255, .8); }
        @media (max-width: 575px) { .perm-module { grid-template-columns: 1fr 60px; } .perm-module .acts { grid-column: 1 / -1; } }
    </style>
@endsection

@push('scripts')
<script>
(function () {
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    function refresh(mod) {
        var boxes = $$('.perm', mod), on = boxes.filter(function (b) { return b.checked; });
        var toggle = mod.querySelector('.mod-toggle'), sum = mod.querySelector('.mod-summary'), off = mod.querySelector('.mod-off');
        toggle.checked = on.length > 0;
        mod.classList.toggle('on', on.length > 0);
        sum.classList.toggle('d-none', on.length === 0); off.classList.toggle('d-none', on.length > 0);
        sum.querySelector('.txt').textContent = on.map(function (b) { return b.dataset.short; }).join(', ');
        var c = document.getElementById('permCount'); if (c) c.textContent = $$('.perm').filter(function (b) { return b.checked; }).length;
    }
    $$('.perm-module').forEach(function (mod) {
        var toggle = mod.querySelector('.mod-toggle');
        toggle.addEventListener('change', function () {
            var boxes = $$('.perm', mod);
            if (toggle.checked) {
                // switching on: bring back what was picked before, else everything in the module
                var prev = (mod.dataset.prev || '').split(',').filter(Boolean);
                boxes.forEach(function (b) { b.checked = prev.length ? prev.indexOf(b.value) >= 0 : true; });
            } else {
                mod.dataset.prev = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; }).join(',');
                boxes.forEach(function (b) { b.checked = false; });
            }
            refresh(mod);
        });
        mod.addEventListener('change', function (e) { if (e.target.classList.contains('perm')) refresh(mod); });
    });
    $$('[data-rename-role]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('roleRenameForm').action = btn.dataset.action;
            document.getElementById('roleRenameKey').textContent = btn.dataset.key;
            document.getElementById('rename_name').value = btn.dataset.name;
            document.getElementById('rename_desc').value = btn.dataset.description || '';
            new bootstrap.Modal(document.getElementById('roleRenameModal')).show();
        });
    });
    // reset / delete: a hidden form that reuses the app's confirm dialog (data-confirm)
    $$('[data-post]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var f = document.getElementById('roleActionForm');
            f.action = btn.dataset.post;
            document.getElementById('roleActionMethod').value = btn.dataset.method || 'POST';
            f.dataset.confirm = btn.dataset.confirm; f.dataset.confirmTitle = btn.dataset.confirmTitle; f.dataset.confirmOk = btn.dataset.confirmOk;
            f.requestSubmit ? f.requestSubmit() : f.submit();
        });
    });
    @if ($errors->has('key') || $errors->has('name') && old('key') !== null)
        new bootstrap.Modal(document.getElementById('roleAddModal')).show();
    @endif
})();
</script>
@endpush
