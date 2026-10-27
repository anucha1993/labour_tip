@extends('layouts.app')

@section('title', 'ตั้งรหัสผ่านใหม่')

@section('content')
    <h1>ตั้งรหัสผ่านใหม่</h1>
    <p class="hint">กำหนดรหัสผ่านใหม่สำหรับบัญชีของคุณ</p>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="mb-3">
            <label for="email" class="form-label">อีเมล</label>
            <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ $email ?? old('email') }}" required autocomplete="email" autofocus>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">รหัสผ่านใหม่</label>
            <div class="input-group">
                <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="new-password">
                <button class="btn btn-soft" type="button" data-toggle-password="#password"><i class="mdi mdi-eye"></i></button>
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="mb-4">
            <label for="password-confirm" class="form-label">ยืนยันรหัสผ่านใหม่</label>
            <input id="password-confirm" type="password" class="form-control" name="password_confirmation" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100"><i class="mdi mdi-lock"></i> บันทึกรหัสผ่านใหม่</button>
    </form>
@endsection
