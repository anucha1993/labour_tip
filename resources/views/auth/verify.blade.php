@extends('layouts.app')

@section('title', 'ยืนยันอีเมล')

@section('content')
    <h1>ยืนยันอีเมลของคุณ</h1>
    @if (session('resent'))
        <div class="alert alert-success" role="alert"><i class="mdi mdi-check-circle"></i><div>ส่งลิงก์ยืนยันไปยังอีเมลของคุณแล้ว</div></div>
    @endif
    <p class="hint">กรุณาตรวจสอบอีเมลเพื่อยืนยันบัญชีก่อนใช้งาน หากไม่ได้รับอีเมล</p>
    <form method="POST" action="{{ route('verification.resend') }}">
        @csrf
        <button type="submit" class="btn btn-primary w-100">ส่งอีเมลยืนยันอีกครั้ง</button>
    </form>
@endsection
