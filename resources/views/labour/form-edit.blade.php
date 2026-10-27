@extends('layouts.main_layout')

@section('title', 'แก้ไขแรงงาน')
@section('crumb', 'แรงงานต่างด้าว / แก้ไขข้อมูล')

@section('content')
    <x-page-header title="แก้ไขข้อมูล: {{ $labour->labour_prefix }}. {{ $labour->labour_fullname }}" subtitle="เลขที่หนังสือเดินทาง {{ $labour->labour_passport_number }} · แก้ไขล่าสุดโดย {{ $labour->updated_by ?: $labour->created_by ?: '-' }} {{ fmt_datetime($labour->updated_at, '') }}">
        <a href="{{ route('labour.show', $labour->labour_id) }}" class="btn btn-soft"><i class="mdi mdi-eye"></i> ดูข้อมูล</a>
        <a href="{{ route('labour.index') }}" class="btn btn-soft"><i class="mdi mdi-arrow-left"></i> กลับ</a>
    </x-page-header>

    <form method="POST" action="{{ route('labour.update', $labour->labour_id) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        @include('labour._form')
        <div class="form-actions-bar">
            <span class="hint"><i class="mdi mdi-information"></i> การแก้ไขจะถูกบันทึกลงประวัติการแก้ไขอัตโนมัติ</span>
            <a href="{{ route('labour.index') }}" class="btn btn-soft">ยกเลิก</a>
            <button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> บันทึกการแก้ไข</button>
        </div>
    </form>

    @if (user_is('MasterAdmin'))
        <form method="POST" id="fileDeleteForm" class="d-none" data-confirm="" data-confirm-title="ยืนยันการลบไฟล์" data-confirm-ok="ลบไฟล์">
            @csrf
            @method('DELETE')
        </form>
    @endif
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-file-delete]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.getElementById('fileDeleteForm');
            if (!form) return;
            form.action = btn.dataset.fileDelete;
            form.dataset.confirm = 'ต้องการลบไฟล์' + btn.dataset.fileLabel + 'ใช่หรือไม่?';
            form.dataset.confirmed = '0';
            form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        });
    });
</script>
@endpush
