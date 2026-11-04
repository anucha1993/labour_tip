@extends('layouts.public')

@section('title', 'ลิงก์ใช้ไม่ได้แล้ว')

@section('content')
    <div class="card mx-auto" style="max-width:460px">
        <div class="card-body p-4 text-center">
            <span class="avatar avatar-lg mx-auto mb-3" style="background:rgba(220,38,38,.12);color:var(--danger)"><i class="mdi mdi-link-variant-off"></i></span>
            <h1 class="h5">ลิงก์นี้ใช้ไม่ได้แล้ว</h1>
            <p class="text-2 mb-0">
                @if ($share && $share->revoked_at)
                    ผู้แชร์ยกเลิกการแชร์โฟลเดอร์นี้แล้ว
                @elseif ($share && $share->expires_at && $share->expires_at->isPast())
                    ลิงก์หมดอายุเมื่อ {{ fmt_datetime($share->expires_at) }}
                @else
                    ไม่พบลิงก์นี้ในระบบ อาจพิมพ์ผิดหรือถูกลบไปแล้ว
                @endif
                <br>กรุณาติดต่อ The Importer 168 เพื่อขอลิงก์ใหม่
            </p>
        </div>
    </div>
@endsection
