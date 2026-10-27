@extends('layouts.main_layout')

@section('title', 'สมาชิก / ผู้ใช้งาน')
@section('crumb', 'จัดการบัญชีผู้ใช้งานระบบ')

@section('content')
    <x-page-header title="สมาชิก / ผู้ใช้งาน" subtitle="ทั้งหมด {{ number_format($users->total()) }} บัญชี">
        <a href="{{ route('register') }}" class="btn btn-primary"><i class="mdi mdi-account-plus"></i> เพิ่มสมาชิก</a>
    </x-page-header>

    <div class="card">
        <form method="GET" action="{{ route('member.index') }}" class="card-body pb-0">
            <div class="row g-2">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0"><i class="mdi mdi-magnify"></i></span>
                        <input type="search" name="keyword" class="form-control border-start-0" placeholder="ชื่อ หรืออีเมล" value="{{ $keyword }}">
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="mdi mdi-magnify"></i> ค้นหา</button>
                    @if ($keyword)<a href="{{ route('member.index') }}" class="btn btn-soft"><i class="mdi mdi-refresh"></i></a>@endif
                </div>
            </div>
        </form>

        <div class="table-wrap mt-3">
            <table class="table table-modern">
                <thead>
                    <tr>
                        <th style="width:48px">#</th>
                        <th>ชื่อ-สกุล</th>
                        <th>อีเมล</th>
                        <th>สิทธิ์การใช้งาน</th>
                        <th>สถานะ</th>
                        <th>สร้างเมื่อ</th>
                        <th class="td-actions"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $i => $u)
                        <tr>
                            <td class="text-3">{{ $users->firstItem() + $i }}</td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar" style="width:32px;height:32px;font-size:.75rem">{{ mb_strtoupper(mb_substr($u->name, 0, 1)) }}</span>
                                    <div>
                                        <div class="fw-name">{{ $u->name }}</div>
                                        @if ($u->id === auth()->id())<div class="sub">บัญชีของคุณ</div>@endif
                                    </div>
                                </div>
                            </td>
                            <td>{{ $u->email }}</td>
                            <td><x-status-badge :value="$u->type" type="role" /> <span class="sub d-block">{{ role_label($u->type) }}</span></td>
                            <td><x-status-badge :value="$u->status" type="enable" /></td>
                            <td class="text-2">{{ fmt_date($u->created_at) }}</td>
                            <td class="td-actions">
                                <button type="button" class="btn btn-soft btn-sm" data-edit-user
                                    data-id="{{ $u->id }}" data-name="{{ $u->name }}" data-email="{{ $u->email }}" data-type="{{ $u->type }}" data-status="{{ $u->status }}"
                                    data-action="{{ route('member.update', $u->id) }}"><i class="mdi mdi-pencil"></i> แก้ไข</button>
                                @if ($u->id !== auth()->id())
                                    <x-delete-form :action="route('member.delete', $u->id)" confirm="ต้องการลบสมาชิก {{ $u->name }} ใช่หรือไม่?" title="ยืนยันการลบสมาชิก" />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty-state icon="mdi-account-off" title="ไม่พบสมาชิก" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="table-footer">{{ $users->links() }}</div>
    </div>

    {{-- ---------------------------------------------------------- edit modal --}}
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content" id="editUserForm">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title"><i class="mdi mdi-account-edit"></i> แก้ไขสมาชิก</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required" for="edit_name">ชื่อ-สกุล</label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required" for="edit_email">อีเมล</label>
                        <input type="email" class="form-control" name="email" id="edit_email" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="edit_password">รหัสผ่านใหม่</label>
                            <input type="password" class="form-control" name="password" id="edit_password" autocomplete="new-password" placeholder="เว้นว่างถ้าไม่เปลี่ยน">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="edit_password_confirmation">ยืนยันรหัสผ่านใหม่</label>
                            <input type="password" class="form-control" name="password_confirmation" id="edit_password_confirmation" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required" for="edit_type">ระดับการใช้งาน</label>
                        <select class="form-select" name="type" id="edit_type" required>
                            @foreach ($roles as $r)
                                <option value="{{ $r }}">{{ $r }} — {{ role_label($r) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label required d-block">สถานะ</label>
                        <div class="seg">
                            <input type="radio" class="btn-check" name="status" id="edit_status_enable" value="enable">
                            <label class="btn seg-success" for="edit_status_enable"><i class="mdi mdi-check-circle"></i> เปิดใช้งาน</label>
                            <input type="radio" class="btn-check" name="status" id="edit_status_disable" value="disable">
                            <label class="btn seg-danger" for="edit_status_disable"><i class="mdi mdi-account-off"></i> ปิดใช้งาน</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-soft" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> บันทึก</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        var modalEl = document.getElementById('editUserModal');
        var modal = new bootstrap.Modal(modalEl);
        var form = document.getElementById('editUserForm');
        document.querySelectorAll('[data-edit-user]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                form.action = btn.dataset.action;
                form.querySelector('#edit_name').value = btn.dataset.name;
                form.querySelector('#edit_email').value = btn.dataset.email;
                form.querySelector('#edit_password').value = '';
                form.querySelector('#edit_password_confirmation').value = '';
                form.querySelector('#edit_type').value = btn.dataset.type;
                form.querySelector('#edit_status_' + btn.dataset.status).checked = true;
                modal.show();
            });
        });
    })();
</script>
@endpush
