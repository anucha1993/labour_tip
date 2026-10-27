@extends('layouts.main_layout')

@section('title', 'แก้ไขเอเจนซี่')
@section('crumb', 'เอเจนซี่ / แก้ไขข้อมูล')

@section('content')
    <x-page-header title="แก้ไข: {{ $agency->agency_name }}" subtitle="แก้ไขล่าสุดโดย {{ $agency->updated_by ?: $agency->created_by ?: '-' }} {{ fmt_datetime($agency->updated_at, '') }}">
        <a href="{{ route('agency.index') }}" class="btn btn-soft"><i class="mdi mdi-arrow-left"></i> กลับ</a>
    </x-page-header>

    <div class="row justify-content-center">
        <div class="col-xl-10">
            <form method="POST" action="{{ route('agency.update', $agency->agency_id) }}">
                @csrf
                @method('PUT')
                @include('agency._form')
                <div class="form-actions-bar">
            <span class="hint"><i class="mdi mdi-information"></i> ช่องที่มี * จำเป็นต้องกรอก</span>
            <a href="{{ route('agency.index') }}" class="btn btn-soft">ยกเลิก</a>
            <button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> บันทึกการแก้ไข</button>
        </div>
            </form>
        </div>
    </div>
@endsection
