@extends('layouts.app')

@section('title', 'ยืนยันรหัสผ่าน')

@section('content')
    <h1>ยืนยันรหัสผ่าน</h1>
    <p class="hint">กรุณายืนยันรหัสผ่านก่อนดำเนินการต่อ</p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <div class="mb-4">
            <label for="password" class="form-label">รหัสผ่าน</label>
            <div class="input-group">
                <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="current-password" autofocus>
                <button class="btn btn-soft" type="button" data-toggle-password="#password"><i class="mdi mdi-eye"></i></button>
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100">ยืนยัน</button>
        @if (Route::has('password.request'))
            <p class="text-center mt-3 mb-0"><a href="{{ route('password.request') }}" class="small">ลืมรหัสผ่าน?</a></p>
        @endif
    </form>
@endsection
