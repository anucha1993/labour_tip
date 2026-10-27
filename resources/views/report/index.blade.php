@extends('layouts.main_layout')

@section('title', 'รายงาน')
@section('crumb', 'ออกรายงาน Excel / PDF')

@section('content')
    @php
        $companyOptions = ['ALL' => 'ทุกบริษัท'] + $company->pluck('company_name', 'company_id')->all();
        $statusOptions = ['ALL' => 'ทุกสถานะ', 'job' => 'ทำงาน', 'resign' => 'ลาออก', 'escape' => 'หลบหนี'];
        $dateTypes = \App\Http\Controllers\PDF\LabourCustomDateController::DATE_TYPES;
    @endphp

    <x-page-header title="รายงานข้อมูลแรงงาน" subtitle="เลือกเงื่อนไขแล้วดาวน์โหลดเป็น Excel (ข้อมูลครบทุกคอลัมน์) หรือ PDF (สรุปคอลัมน์หลัก)" />

    <div class="row g-3">
        <div class="col-xl-6">
            <form method="POST" action="{{ route('report.customPDF') }}" class="card h-100" target="_blank">
                @csrf
                <div class="card-header">
                    <span class="ic ic-primary" style="width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center"><i class="mdi mdi-account-multiple"></i></span>
                    <h5 class="card-title">รายงานข้อมูลแรงงานทั้งหมด</h5>
                </div>
                <div class="card-body">
                    <x-form.select name="company" id="r1_company" label="บริษัท / นายจ้าง" value="ALL" :options="$companyOptions" search />
                    <x-form.select name="status" id="r1_status" label="สถานะแรงงาน" value="ALL" :options="$statusOptions" />
                    <div>
                        <label class="form-label d-block">รูปแบบรายงาน</label>
                        <div class="seg">
                            <input type="radio" class="btn-check" name="type" id="r1_excel" value="excel" checked>
                            <label class="btn seg-success" for="r1_excel"><i class="mdi mdi-file-excel"></i> Excel</label>
                            <input type="radio" class="btn-check" name="type" id="r1_pdf" value="pdf">
                            <label class="btn seg-danger" for="r1_pdf"><i class="mdi mdi-file-pdf"></i> PDF</label>
                        </div>
                    </div>
                </div>
                <div class="form-sticky-actions">
                    <button type="submit" class="btn btn-primary"><i class="mdi mdi-download"></i> ดาวน์โหลดรายงาน</button>
                </div>
            </form>
        </div>

        <div class="col-xl-6">
            <form method="POST" action="{{ route('report.customdate') }}" class="card h-100" target="_blank">
                @csrf
                <div class="card-header">
                    <span class="ic ic-warning" style="width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center"><i class="mdi mdi-calendar-clock"></i></span>
                    <h5 class="card-title">รายงานตามช่วงวันหมดอายุ</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <x-form.select wrapper="col-md-7 mb-3" name="company" id="r2_company" label="บริษัท / นายจ้าง" value="ALL" :options="$companyOptions" search />
                        <x-form.select wrapper="col-md-5 mb-3" name="status" id="r2_status" label="สถานะแรงงาน" value="ALL" :options="$statusOptions" />
                        <x-form.select wrapper="col-md-12 mb-3" name="type_date" id="r2_type_date" label="ประเภทวันหมดอายุ" :options="$dateTypes" required />
                        <x-form.input wrapper="col-6 mb-3" name="date_start" id="r2_date_start" type="date" label="ตั้งแต่วันที่" :value="now()->toDateString()" required />
                        <x-form.input wrapper="col-6 mb-3" name="date_end" id="r2_date_end" type="date" label="ถึงวันที่" :value="now()->addDays(30)->toDateString()" required />
                    </div>
                    <div>
                        <label class="form-label d-block">รูปแบบรายงาน</label>
                        <div class="seg">
                            <input type="radio" class="btn-check" name="type" id="r2_excel" value="excel" checked>
                            <label class="btn seg-success" for="r2_excel"><i class="mdi mdi-file-excel"></i> Excel</label>
                            <input type="radio" class="btn-check" name="type" id="r2_pdf" value="pdf">
                            <label class="btn seg-danger" for="r2_pdf"><i class="mdi mdi-file-pdf"></i> PDF</label>
                        </div>
                    </div>
                </div>
                <div class="form-sticky-actions">
                    <button type="submit" class="btn btn-primary"><i class="mdi mdi-download"></i> ดาวน์โหลดรายงาน</button>
                </div>
            </form>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header"><i class="mdi mdi-bell-ring text-danger"></i><h5 class="card-title">รายงานเอกสารใกล้หมดอายุ (ตามค่าที่ตั้งไว้)</h5></div>
                <div class="card-body d-flex flex-wrap gap-2">
                    <a href="{{ route('ExportExpire.Visa') }}" class="btn btn-soft-success" data-no-loader><i class="mdi mdi-file-excel"></i> วีซ่า</a>
                    <a href="{{ route('ExportExpire.passport') }}" class="btn btn-soft-success" data-no-loader><i class="mdi mdi-file-excel"></i> หนังสือเดินทาง</a>
                    <a href="{{ route('ExportExpire.workpremit') }}" class="btn btn-soft-success" data-no-loader><i class="mdi mdi-file-excel"></i> ใบอนุญาตทำงาน</a>
                    <a href="{{ route('ExportExpire.day90') }}" class="btn btn-soft-success" data-no-loader><i class="mdi mdi-file-excel"></i> รายงานตัว 90 วัน</a>
                </div>
            </div>
        </div>
    </div>
@endsection
