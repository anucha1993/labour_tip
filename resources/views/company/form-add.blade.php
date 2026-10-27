@extends('layouts.main_layout')

@section('title', 'เพิ่มบริษัท')
@section('crumb', 'บริษัท / นายจ้าง / เพิ่มข้อมูล')

@section('content')
    <x-page-header title="เพิ่มบริษัท / นายจ้าง" subtitle="ช่องที่มี * จำเป็นต้องกรอก">
        <a href="{{ route('company.index') }}" class="btn btn-soft"><i class="mdi mdi-arrow-left"></i> กลับ</a>
    </x-page-header>

    <form method="POST" action="{{ route('company.store') }}">
        @csrf
        @include('company._form')
        <div class="form-actions-bar">
            <span class="hint"><i class="mdi mdi-information"></i> ช่องที่มี * จำเป็นต้องกรอก</span>
            <a href="{{ route('company.index') }}" class="btn btn-soft">ยกเลิก</a>
            <button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> บันทึกข้อมูล</button>
        </div>
    </form>
@endsection
