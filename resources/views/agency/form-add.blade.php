@extends('layouts.main_layout')

@section('title', 'เพิ่มเอเจนซี่')
@section('crumb', 'เอเจนซี่ / เพิ่มข้อมูล')

@section('content')
    <x-page-header title="เพิ่มเอเจนซี่" subtitle="ช่องที่มี * จำเป็นต้องกรอก">
        <a href="{{ route('agency.index') }}" class="btn btn-soft"><i class="mdi mdi-arrow-left"></i> กลับ</a>
    </x-page-header>

    <div class="row justify-content-center">
        <div class="col-xl-10">
            <form method="POST" action="{{ route('agency.store') }}">
                @csrf
                @include('agency._form')
                <div class="form-actions-bar">
            <span class="hint"><i class="mdi mdi-information"></i> ช่องที่มี * จำเป็นต้องกรอก</span>
            <a href="{{ route('agency.index') }}" class="btn btn-soft">ยกเลิก</a>
            <button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> บันทึกข้อมูล</button>
        </div>
            </form>
        </div>
    </div>
@endsection
