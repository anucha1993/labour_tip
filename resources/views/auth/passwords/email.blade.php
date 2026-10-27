@extends('layouts.app')

@section('title', 'ลืมรหัสผ่าน')

@section('content')
    <h1>ลืมรหัสผ่าน</h1>
    <p class="hint">กรอกอีเมลที่ใช้ลงทะเบียน ระบบจะส่งลิงก์สำหรับตั้งรหัสผ่านใหม่ให้</p>

    @if (session('status'))
        <div class="alert alert-success" role="alert"><i class="mdi mdi-check-circle"></i><div>{{ session('status') }}</div></div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="mb-4">
            <label for="email" class="form-label">อีเมล</label>
            <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100"><i class="mdi mdi-email"></i> ส่งลิงก์ตั้งรหัสผ่านใหม่</button>
    </form>

    <p class="text-center mt-4 mb-0"><a href="{{ route('login') }}"><i class="mdi mdi-arrow-left"></i> กลับไปหน้าเข้าสู่ระบบ</a></p>
@endsection
