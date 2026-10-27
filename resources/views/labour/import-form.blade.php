@extends('layouts.main_layout')

@section('title', 'นำเข้าข้อมูลจาก Excel')
@section('crumb', 'แรงงานต่างด้าว / นำเข้า Excel')

@section('content')
    <x-page-header title="นำเข้าข้อมูลแรงงานจาก Excel" subtitle="แรงงานทุกคนในไฟล์จะถูกผูกกับบริษัท เอเจนซี่ และสัญชาติที่เลือกด้านล่าง">
        <a href="{{ asset('file/from-import.xlsx') }}" class="btn btn-soft" download data-no-loader><i class="mdi mdi-download"></i> ดาวน์โหลดฟอร์มกรอกข้อมูล</a>
        <a href="{{ route('labour.index') }}" class="btn btn-soft"><i class="mdi mdi-arrow-left"></i> กลับ</a>
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('import-excel.labour') }}" enctype="multipart/form-data" class="card">
                @csrf
                <div class="card-body">
                    <x-form.select name="company" label="บริษัท / นายจ้าง" :value="old('company')" required placeholder="เลือกบริษัท" :options="$company->pluck('company_name', 'company_id')->all()" search />
                    <x-form.select name="agency" label="เอเจนซี่" :value="old('agency')" required placeholder="เลือกเอเจนซี่" :options="$agency->pluck('agency_name', 'agency_id')->all()" search />
                    <x-form.select name="nationality" label="สัญชาติ" :value="old('nationality')" required placeholder="เลือกสัญชาติ" :options="$nationality->pluck('name_th', 'code')->all()" />
                    <x-form.input name="file" type="file" label="ไฟล์ Excel (.xlsx, .xls, .csv)" required accept=".xlsx,.xls,.csv" help="ใช้หัวคอลัมน์ตามฟอร์มกรอกข้อมูล แถวที่ไม่มีเลขหนังสือเดินทางจะถูกข้าม" />
                </div>
                <div class="form-sticky-actions">
                    <a href="{{ route('labour.index') }}" class="btn btn-soft">ยกเลิก</a>
                    <button type="submit" class="btn btn-primary"><i class="mdi mdi-file-import"></i> นำเข้าข้อมูล</button>
                </div>
            </form>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><i class="mdi mdi-information"></i><h6 class="card-title">ข้อควรรู้ก่อนนำเข้า</h6></div>
                <div class="card-body small">
                    <ul class="mb-0 ps-3 d-grid gap-2">
                        <li>เลขหนังสือเดินทางที่ <strong>มีในระบบแล้ว</strong> หรือ <strong>ซ้ำกันในไฟล์</strong> จะถูกข้าม และแจ้งจำนวนหลังนำเข้าเสร็จ</li>
                        <li>ช่องวันที่รองรับทั้งรูปแบบวันที่ของ Excel, <code>วว/ดด/ปปปป</code> และ <code>ปปปป-ดด-วว</code> ช่องว่างจะถูกบันทึกเป็น "ไม่ระบุ"</li>
                        <li>คำนำหน้า: Mr / Ms / Mrs (หรือ นาย / นางสาว / นาง) · เพศ: male / female</li>
                        <li>สถานะการทำงาน: job / resign / escape (ค่าว่าง = job) · สถานะในระบบ: enable / disable (ค่าว่าง = enable)</li>
                        <li>หากมีแถวใดผิดพลาดจนบันทึกไม่ได้ ระบบจะยกเลิกการนำเข้าทั้งไฟล์ เพื่อไม่ให้ข้อมูลเข้าเพียงบางส่วน</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
