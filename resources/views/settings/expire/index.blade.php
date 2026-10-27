@extends('layouts.main_layout')

@section('title', 'ตั้งค่าวันแจ้งเตือน')
@section('crumb', 'การจัดการ / ตั้งค่าวันแจ้งเตือนล่วงหน้า')

@section('content')
    @php
        $icons = ['visa' => 'mdi-airplane', 'passport' => 'mdi-book-open-page-variant', 'work' => 'mdi-briefcase', 'ninety' => 'mdi-calendar-clock'];
    @endphp
    <x-page-header title="ตั้งค่าวันแจ้งเตือนล่วงหน้า" subtitle="ระบบจะแสดงเอกสารในหน้าแรกเมื่อเหลือเวลาน้อยกว่าหรือเท่ากับจำนวนวันที่กำหนด" />

    <div class="row justify-content-center">
        <div class="col-lg-7 col-xl-6">
            <form method="POST" action="{{ route('setting.update') }}" class="card">
                @csrf
                <div class="card-body p-0">
                    @foreach ($settings as $i => $s)
                        <div class="d-flex align-items-center gap-3 px-4 py-3 {{ $loop->last ? '' : 'border-bottom' }}">
                            <input type="hidden" name="set_id[]" value="{{ $s->set_id }}">
                            <span class="ic ic-primary" style="width:42px;height:42px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:1.3rem"><i class="mdi {{ $icons[$s->set_name] ?? 'mdi-bell' }}"></i></span>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">{{ $labels[$s->set_name] ?? $s->set_name }}</div>
                                <div class="small text-3">แจ้งเตือนก่อนหมดอายุ</div>
                            </div>
                            <div class="input-group" style="width:150px">
                                <input type="number" name="set_expire[]" class="form-control text-end @error('set_expire.' . $i) is-invalid @enderror" value="{{ old('set_expire.' . $i, $s->set_expire) }}" min="0" max="3650" required>
                                <span class="input-group-text">วัน</span>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="form-sticky-actions">
                    <button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> บันทึกการตั้งค่า</button>
                </div>
            </form>
        </div>
    </div>
@endsection
