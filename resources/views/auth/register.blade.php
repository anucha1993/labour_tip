@extends('layouts.main_layout')

@section('title', 'เพิ่มสมาชิกใหม่')
@section('crumb', 'สมาชิก / เพิ่มสมาชิก')

@section('content')
    <x-page-header title="เพิ่มสมาชิกใหม่" subtitle="สร้างบัญชีผู้ใช้งานสำหรับเข้าระบบ">
        <a href="{{ route('member.index') }}" class="btn btn-soft"><i class="mdi mdi-arrow-left"></i> กลับ</a>
    </x-page-header>

    <div class="row justify-content-center">
        <div class="col-lg-7 col-xl-6">
            <form method="POST" action="{{ route('register') }}" class="card">
                @csrf
                <div class="card-body">
                    <x-form.input name="name" label="ชื่อ-สกุล" :value="old('name')" required autofocus />
                    <x-form.input name="email" type="email" label="อีเมล (ใช้เข้าสู่ระบบ)" :value="old('email')" required autocomplete="off" />

                    <div class="row">
                        <div class="col-md-6">
                            <label for="password" class="form-label required">รหัสผ่าน</label>
                            <div class="input-group mb-3">
                                <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="new-password">
                                <button class="btn btn-soft" type="button" data-toggle-password="#password"><i class="mdi mdi-eye"></i></button>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <x-form.input wrapper="col-md-6 mb-3" name="password_confirmation" type="password" label="ยืนยันรหัสผ่าน" required autocomplete="new-password" />
                    </div>
                    <div class="form-text mb-3 mt-n2">รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร</div>

                    <x-form.select name="type" label="ระดับการใช้งาน" :value="old('type', 'Member')" required
                        :options="['Member' => 'Member — ดูข้อมูลและออกรายงาน', 'Admin' => 'Admin — เพิ่ม/แก้ไขข้อมูล', 'MasterAdmin' => 'MasterAdmin — สิทธิ์ทั้งหมด รวมถึงลบและจัดการสมาชิก']" />

                    <div class="mb-2">
                        <label class="form-label required d-block">สถานะการใช้งาน</label>
                        <div class="seg">
                            <input type="radio" class="btn-check" name="status" id="status_enable" value="enable" {{ old('status', 'enable') === 'enable' ? 'checked' : '' }} required>
                            <label class="btn seg-success" for="status_enable"><i class="mdi mdi-check-circle"></i> เปิดใช้งาน</label>
                            <input type="radio" class="btn-check" name="status" id="status_disable" value="disable" {{ old('status') === 'disable' ? 'checked' : '' }}>
                            <label class="btn seg-danger" for="status_disable"><i class="mdi mdi-account-off"></i> ปิดใช้งาน</label>
                        </div>
                    </div>
                </div>
                <div class="form-sticky-actions">
                    <a href="{{ route('member.index') }}" class="btn btn-soft">ยกเลิก</a>
                    <button type="submit" class="btn btn-primary"><i class="mdi mdi-account-plus"></i> สร้างบัญชี</button>
                </div>
            </form>
        </div>
    </div>
@endsection
