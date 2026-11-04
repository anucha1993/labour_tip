@extends('layouts.public')

@section('title', 'ยืนยันตัวตน')

@section('content')
    @php $step = $step ?? 'email'; @endphp
    <div class="card mx-auto" style="max-width:440px">
        <div class="card-body p-4 text-center">
            <span class="avatar avatar-lg mx-auto mb-3" style="background:var(--brand-50);color:var(--brand-600)"><i class="mdi {{ $step === 'code' ? 'mdi-email' : 'mdi-account-key' }}"></i></span>

            @if (session('status'))
                <div class="alert alert-success py-2 small text-start"><i class="mdi mdi-check-circle"></i> {{ session('status') }}</div>
            @endif
            @if ($step === 'code')
                <h1 class="h5">กรอกรหัสที่ส่งไปทางอีเมล</h1>
                <p class="text-2 small">เราส่งรหัส 6 หลักไปที่ <b>{{ $email }}</b> แล้ว รหัสใช้ได้ 10 นาที</p>
                <form method="POST" action="{{ route('share.unlock', $share->token) }}" class="text-start">
                    @csrf
                    <input type="hidden" name="email" value="{{ $email }}">
                    <label class="form-label" for="code">รหัสยืนยัน</label>
                    <input type="text" name="code" id="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" class="form-control form-control-lg text-center text-mono @error('code') is-invalid @enderror" autofocus required autocomplete="one-time-code">
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <button type="submit" class="btn btn-primary w-100 mt-3"><i class="mdi mdi-lock-open"></i> เปิดโฟลเดอร์</button>
                    <button type="submit" name="resend" value="1" class="btn btn-soft w-100 mt-2" formnovalidate>ส่งรหัสใหม่</button>
                </form>
            @else
                <h1 class="h5">{{ $share->isRestricted() ? 'โฟลเดอร์นี้เปิดได้เฉพาะอีเมลที่ได้รับสิทธิ์' : 'โฟลเดอร์นี้มีรหัสผ่าน' }}</h1>
                <p class="text-2 small">
                    @if ($share->isRestricted())
                        กรอกอีเมลที่ได้รับลิงก์นี้{{ $share->hasPassword() ? ' และรหัสผ่านของลิงก์' : '' }} เพื่อเปิดดู{{ $share->kind === 'company' ? 'เอกสารของบริษัท' : 'เอกสารของแรงงาน' }}
                    @else
                        ใส่รหัสผ่านที่ได้รับพร้อมลิงก์เพื่อเปิดดู{{ $share->kind === 'company' ? 'เอกสารของบริษัท' : 'เอกสารของแรงงาน' }}
                    @endif
                </p>
                <form method="POST" action="{{ route('share.unlock', $share->token) }}" class="text-start">
                    @csrf
                    @php $verifiedEmail = $verifiedEmail ?? null; @endphp
                    @if ($share->isRestricted())
                        <label class="form-label" for="email">อีเมลของคุณ</label>
                        @if ($verifiedEmail)
                            <input type="email" name="email" id="email" class="form-control" value="{{ $verifiedEmail }}" readonly>
                            <div class="form-text text-success"><i class="mdi mdi-check-circle"></i> ยืนยันอีเมลจากลิงก์ในอีเมลแล้ว</div>
                        @else
                            <input type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}" autofocus required autocomplete="email">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @endif
                    @endif
                    @if ($share->hasPassword())
                        <label class="form-label mt-3" for="password">รหัสผ่านของลิงก์</label>
                        <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" {{ $share->isRestricted() && ! $verifiedEmail ? '' : 'autofocus' }} required autocomplete="off">
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    @endif
                    <button type="submit" class="btn btn-primary w-100 mt-3"><i class="mdi mdi-lock-open"></i> {{ $share->isRestricted() && $mailReady && ! $verifiedEmail ? 'ส่งรหัสยืนยันไปที่อีเมล' : 'เปิดโฟลเดอร์' }}</button>
                </form>
            @endif
        </div>
    </div>
@endsection
