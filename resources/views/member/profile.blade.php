@extends('layouts.main_layout')

@section('title', 'โปรไฟล์ของฉัน')
@section('crumb', 'บัญชีผู้ใช้ / โปรไฟล์')

@push('head')
<style>
    .avatar-xl { width: 128px; height: 128px; font-size: 3rem; }
    .avatar-pick { position: relative; display: inline-block; }
    .avatar-pick .edit { position: absolute; right: 2px; bottom: 2px; width: 36px; height: 36px; border-radius: 50%; border: 2px solid var(--surface); background: var(--brand-600); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
    .avatar-pick .edit:hover { background: var(--brand-700); }
    .avatar-drop { border: 2px dashed var(--border); border-radius: 14px; padding: 16px; text-align: center; color: var(--text-2); font-size: .88rem; }
    .avatar-drop.is-over { border-color: var(--brand-600); background: var(--brand-50); }
</style>
@endpush

@section('content')
    <x-page-header title="โปรไฟล์ของฉัน" subtitle="รูปโปรไฟล์ ชื่อที่แสดง และรหัสผ่านของบัญชีนี้" />

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body text-center">
                    <form method="POST" action="{{ route('profile.avatar') }}" enctype="multipart/form-data" id="avatarForm">
                        @csrf
                        <div class="avatar-pick mb-3">
                            <x-avatar :user="$user" :size="128" class="avatar-xl" />
                            <label for="avatarInput" class="edit" title="เปลี่ยนรูป"><i class="mdi mdi-camera"></i></label>
                        </div>
                        <div class="fw-semibold fs-5">{{ $user->name }}</div>
                        <div class="text-3 small">{{ $user->email }}</div>
                        <span class="badge-soft badge-soft-primary mt-1 d-inline-block">{{ role_label($user->type) }}</span>

                        <div class="avatar-drop mt-3" id="avatarDrop">
                            <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/webp,image/gif" class="d-none">
                            <i class="mdi mdi-image-plus fs-4 d-block"></i>
                            แตะที่ไอคอนกล้อง หรือลากรูปมาวางที่นี่<br>
                            <span class="text-3">JPG, PNG, WEBP ไม่เกิน 5 MB · ระบบจะตัดเป็นสี่เหลี่ยมจัตุรัสให้</span>
                            <div class="mt-2 d-none" id="avatarPreviewWrap">
                                <img id="avatarPreview" alt="" class="avatar avatar-img" style="width:72px;height:72px">
                                <div class="mt-2 d-flex gap-2 justify-content-center">
                                    <button type="submit" class="btn btn-primary btn-sm"><i class="mdi mdi-content-save"></i> บันทึกรูปนี้</button>
                                    <button type="button" class="btn btn-soft btn-sm" id="avatarCancel">ยกเลิก</button>
                                </div>
                            </div>
                        </div>
                        @error('avatar')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    </form>

                    @if ($user->avatar)
                        <form method="POST" action="{{ route('profile.avatar.delete') }}" class="mt-2" onsubmit="return confirm('ลบรูปโปรไฟล์และกลับไปใช้ตัวอักษรย่อ?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-link btn-sm text-danger"><i class="mdi mdi-delete"></i> ลบรูปโปรไฟล์</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card">
                <form method="POST" action="{{ route('profile.update') }}" class="card-body" autocomplete="off">
                    @csrf
                    <h6 class="mb-3"><i class="mdi mdi-account-edit"></i> ข้อมูลบัญชี</h6>
                    <div class="row">
                        <div class="col-md-6"><x-form.input name="name" label="ชื่อ-สกุล" :value="$user->name" required /></div>
                        <div class="col-md-6"><x-form.input name="email" label="อีเมล (เปลี่ยนได้โดย MasterAdmin)" :value="$user->email" readonly disabled /></div>
                    </div>

                    <h6 class="mt-2 mb-1"><i class="mdi mdi-lock-reset"></i> เปลี่ยนรหัสผ่าน</h6>
                    <p class="text-3 small mb-3">เว้นว่างทั้งหมดถ้าไม่ต้องการเปลี่ยน</p>
                    <div class="row">
                        <div class="col-md-4"><x-form.input name="current_password" type="password" label="รหัสผ่านปัจจุบัน" autocomplete="current-password" /></div>
                        <div class="col-md-4"><x-form.input name="password" type="password" label="รหัสผ่านใหม่" help="อย่างน้อย 6 ตัวอักษร" autocomplete="new-password" /></div>
                        <div class="col-md-4"><x-form.input name="password_confirmation" type="password" label="ยืนยันรหัสผ่านใหม่" autocomplete="new-password" /></div>
                    </div>

                    <div class="d-flex gap-2 mt-2">
                        <button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> บันทึก</button>
                        <a href="{{ route('dashboard') }}" class="btn btn-soft">กลับหน้าหลัก</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        var input = document.getElementById('avatarInput'), drop = document.getElementById('avatarDrop');
        var wrap = document.getElementById('avatarPreviewWrap'), img = document.getElementById('avatarPreview');

        function show(file) {
            if (!file || !file.type.match(/^image\//)) return;
            if (file.size > 5 * 1024 * 1024) { alert('รูปต้องมีขนาดไม่เกิน 5 MB'); return; }
            var reader = new FileReader();
            reader.onload = function (e) { img.src = e.target.result; wrap.classList.remove('d-none'); };
            reader.readAsDataURL(file);
        }
        input.addEventListener('change', function () { show(input.files[0]); });
        document.getElementById('avatarCancel').addEventListener('click', function () { input.value = ''; wrap.classList.add('d-none'); });

        ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
        drop.addEventListener('drop', function (e) {
            if (e.dataTransfer.files && e.dataTransfer.files[0]) { input.files = e.dataTransfer.files; show(input.files[0]); }
        });
        drop.addEventListener('click', function (e) { if (e.target.closest('button, img')) return; input.click(); });
    })();
</script>
@endpush
