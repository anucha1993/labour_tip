@extends('layouts.main_layout')

@section('title', 'แก้ไขบริษัท')
@section('crumb', 'บริษัท / นายจ้าง / แก้ไขข้อมูล')

@section('content')
    <x-page-header title="แก้ไข: {{ $company->company_name }}" subtitle="แก้ไขล่าสุดโดย {{ $company->updated_by ?: $company->created_by ?: '-' }} {{ fmt_datetime($company->updated_at, '') }}">
        <a href="{{ route('labour.index', ['company_id' => $company->company_id]) }}" class="btn btn-soft"><i class="mdi mdi-account-multiple"></i> ดูแรงงานของบริษัทนี้</a>
        <a href="{{ route('company.index') }}" class="btn btn-soft"><i class="mdi mdi-arrow-left"></i> กลับ</a>
    </x-page-header>

    <form method="POST" action="{{ route('company.update', $company->company_id) }}">
        @csrf
        @method('PUT')
        @include('company._form')
        <div class="form-actions-bar">
            <span class="hint"><i class="mdi mdi-information"></i> ช่องที่มี * จำเป็นต้องกรอก</span>
            <a href="{{ route('company.index') }}" class="btn btn-soft">ยกเลิก</a>
            <button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> บันทึกการแก้ไข</button>
        </div>
    </form>
@endsection
