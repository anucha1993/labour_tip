@extends('layouts.app')

@section('title', 'เข้าสู่ระบบ')

@section('content')
    <h1>ยินดีต้อนรับกลับมา</h1>
    <p class="hint">เข้าสู่ระบบด้วยอีเมลและรหัสผ่านของคุณ</p>

    @if (session('error'))
        <div class="alert alert-danger" role="alert"><i class="mdi mdi-alert-circle"></i><div>{{ session('error') }}</div></div>
    @endif
    @if (session('status'))
        <div class="alert alert-success" role="alert"><i class="mdi mdi-check-circle"></i><div>{{ session('status') }}</div></div>
    @endif

    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">อีเมล</label>
            <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email"
                value="{{ old('email') }}" required autocomplete="email" autofocus placeholder="name@company.com">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">รหัสผ่าน</label>
            <div class="input-group">
                <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password"
                    required autocomplete="current-password" placeholder="••••••••">
                <button class="btn btn-soft" type="button" data-toggle-password="#password" aria-label="แสดง/ซ่อนรหัสผ่าน"><i class="mdi mdi-eye"></i></button>
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>
                <label class="form-check-label" for="remember">จดจำการเข้าสู่ระบบ</label>
            </div>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="small">ลืมรหัสผ่าน?</a>
            @endif
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100"><i class="mdi mdi-login"></i> เข้าสู่ระบบ</button>
    </form>

    <p class="text-3 small text-center mt-4 mb-0">ยังไม่มีบัญชี? ติดต่อผู้ดูแลระบบเพื่อขอสิทธิ์การใช้งาน</p>
@endsection
