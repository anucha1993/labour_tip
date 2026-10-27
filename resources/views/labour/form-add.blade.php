@extends('layouts.main_layout')

@section('title', 'เพิ่มแรงงาน')
@section('crumb', 'แรงงานต่างด้าว / เพิ่มข้อมูล')

@section('content')
    <x-page-header title="เพิ่มข้อมูลแรงงานต่างด้าว" subtitle="กรอกข้อมูลทีละหมวด ช่องที่มี * จำเป็นต้องกรอก">
        <a href="{{ route('labour.index') }}" class="btn btn-soft"><i class="mdi mdi-arrow-left"></i> กลับ</a>
    </x-page-header>

    <form method="POST" action="{{ route('labour.store') }}" enctype="multipart/form-data">
        @csrf
        @include('labour._form')
        <div class="form-actions-bar">
            <span class="hint"><i class="mdi mdi-information"></i> ข้อมูลจะถูกบันทึกเมื่อกดปุ่มบันทึกเท่านั้น</span>
            <a href="{{ route('labour.index') }}" class="btn btn-soft">ยกเลิก</a>
            <button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> บันทึกข้อมูล</button>
        </div>
    </form>
@endsection
