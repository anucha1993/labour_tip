{{-- Shared labour form fields. Expects: $labour (model), $nationality, $agency, $company --}}
@php
    $nationalityOptions = $nationality->pluck('name_th', 'code')->all();
    $agencyOptions = $agency->pluck('agency_name', 'agency_id')->all();
    $companyOptions = $company->pluck('company_name', 'company_id')->all();
    $statusJob = old('labour_status_job', $labour->labour_status_job ?: 'job');
    $status = old('labour_status', $labour->labour_status ?: 'enable');
@endphp

<x-form.nav :items="[
    'sec-personal' => ['icon' => 'mdi-account', 'label' => 'ข้อมูลส่วนตัว'],
    'sec-employer' => ['icon' => 'mdi-domain', 'label' => 'นายจ้าง'],
    'sec-documents' => ['icon' => 'mdi-file-document', 'label' => 'เอกสาร'],
    'sec-status' => ['icon' => 'mdi-flag', 'label' => 'สถานะ'],
    'sec-files' => ['icon' => 'mdi-paperclip', 'label' => 'ไฟล์แนบ'],
    'sec-note' => ['icon' => 'mdi-clipboard-text', 'label' => 'หมายเหตุ'],
]" />

{{-- ============================================================ 1. personal --}}
<x-form.card id="sec-personal" icon="mdi-account" title="ข้อมูลส่วนตัว" subtitle="ชื่อตามหนังสือเดินทาง สัญชาติ และเอเจนซี่ที่จัดส่ง">
    <div class="row g-3">
        {{-- profile photo --}}
        <div class="col-12">
            <div class="labour-photo-pick" data-photo-pick>
                <label for="labour_photo" class="labour-photo-frame" title="เลือกรูปแรงงาน">
                    @if ($labour->hasPhoto())
                        <img src="{{ $labour->photoUrl() }}" alt="รูปแรงงาน" data-photo-preview>
                    @else
                        <img src="" alt="" data-photo-preview hidden>
                    @endif
                    <span class="placeholder" data-photo-placeholder @if ($labour->hasPhoto()) hidden @endif><i class="mdi mdi-account"></i><small>ไม่มีรูป</small></span>
                    <span class="cam"><i class="mdi mdi-camera"></i></span>
                </label>
                <div class="labour-photo-meta">
                    <div class="fw-semibold">รูปแรงงาน</div>
                    <div class="text-3 small mb-2">JPG, PNG, WEBP ไม่เกิน 5 MB · ระบบย่อและตัดเป็นสี่เหลี่ยมจัตุรัสให้ · แสดงใน Worker App ด้วย</div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <input type="file" name="labour_photo" id="labour_photo" accept="image/jpeg,image/png,image/webp" class="d-none" data-photo-input>
                        <button type="button" class="btn btn-soft btn-sm" data-photo-choose><i class="mdi mdi-upload"></i> เลือกรูป</button>
                        <button type="button" class="btn btn-soft btn-sm d-none" data-photo-clear><i class="mdi mdi-close"></i> ยกเลิกรูปที่เลือก</button>
                        @if ($labour->hasPhoto())
                            <div class="form-check form-check-inline ms-1 mb-0">
                                <input type="hidden" name="remove_photo" value="0">
                                <input type="checkbox" class="form-check-input" name="remove_photo" id="remove_photo" value="1" data-photo-remove>
                                <label class="form-check-label text-danger" for="remove_photo">ลบรูปเดิม</label>
                            </div>
                        @endif
                    </div>
                    @error('labour_photo')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <x-form.select wrapper="col-6 col-lg-2" name="labour_prefix" label="คำนำหน้า" :value="$labour->labour_prefix ?: 'Mr'" required :options="['Mr' => 'Mr.', 'Ms' => 'Ms.', 'Mrs' => 'Mrs.']" />
        <x-form.input wrapper="col-lg-6" name="labour_fullname" label="ชื่อ-สกุล (ภาษาอังกฤษตามหนังสือเดินทาง)" :value="$labour->labour_fullname" required placeholder="เช่น RATH CHHON" />
        <x-form.select wrapper="col-6 col-lg-2" name="labour_sex" label="เพศ" :value="$labour->labour_sex === 'man' ? 'male' : $labour->labour_sex" required :options="['male' => 'ชาย', 'female' => 'หญิง']" />
        <x-form.select wrapper="col-lg-2" name="labour_nationality" label="สัญชาติ" :value="$labour->labour_nationality" required placeholder="เลือก" :options="$nationalityOptions" />

        <div class="col-sm-6 col-lg-3">
            <x-form.input name="labour_birthday" type="date" label="วันเกิด" :value="$labour->labour_birthday" />
            <div class="form-text" data-birthday-hint="#labour_birthday"></div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <label class="form-label" for="age_year">อายุ</label>
            <input type="text" id="age_year" class="form-control" readonly data-age-for="#labour_birthday" placeholder="-">
        </div>
        <x-form.input wrapper="col-sm-6 col-lg-3" name="labour_number" label="รหัสพนักงาน" :value="$labour->labour_number" />
        <x-form.select wrapper="col-sm-6 col-lg-4" name="labour_agency" label="เอเจนซี่" :value="$labour->labour_agency" placeholder="ไม่ระบุ / ไม่มีเอเจนซี่" :options="$agencyOptions" search data-allow-clear />
    </div>
</x-form.card>

{{-- ============================================================ 2. employer --}}
<x-form.card id="sec-employer" icon="mdi-domain" title="บริษัท / นายจ้าง" subtitle="สถานที่ทำงานปัจจุบันของแรงงาน" color="info">
    <div class="row g-3">
        <x-form.select wrapper="col-lg-6" name="company_id" label="บริษัท / นายจ้าง" :value="$labour->company_id" placeholder="เลือกบริษัท" :options="$companyOptions" search data-allow-clear />
        <x-form.input wrapper="col-sm-6 col-lg-3" name="labour_jobdate_start" type="date" label="วันที่เริ่มเข้าทำงาน" :value="$labour->labour_jobdate_start" />
        <x-form.input wrapper="col-sm-6 col-lg-3" name="labour_labour_number" label="รหัสพนักงานที่นายจ้าง (ถ้ามี)" :value="$labour->labour_labour_number" />
    </div>
</x-form.card>

{{-- ============================================================ 3. documents --}}
<div id="sec-documents" class="form-card">
    <div class="doc-group-title">
        <h6><i class="mdi mdi-file-document text-primary"></i> เอกสารประจำตัว</h6>
        <span class="small text-3">สถานะอายุเอกสารจะคำนวณให้ทันทีเมื่อกรอกวันหมดอายุ</span>
    </div>
    <div class="doc-grid">

        <x-form.card id="doc-passport" icon="mdi-book-open-page-variant" title="หนังสือเดินทาง" subtitle="Passport">
            <x-slot name="right"><span class="pill pill-none" data-days-left-for="#labour_passport_date_end">-</span></x-slot>
            <div class="row g-3">
                <x-form.input wrapper="col-12" name="labour_passport_number" label="เลขที่หนังสือเดินทาง" :value="$labour->labour_passport_number" required placeholder="เช่น T0699636" class="text-uppercase" help="ใช้เป็นชื่อโฟลเดอร์เก็บไฟล์เอกสาร ห้ามซ้ำกับคนอื่น" />
                <x-form.input wrapper="col-sm-6" name="labour_passport_date_start" type="date" label="วันที่ออกเล่ม" :value="$labour->labour_passport_date_start" />
                <x-form.input wrapper="col-sm-6" name="labour_passport_date_end" type="date" label="วันหมดอายุ" :value="$labour->labour_passport_date_end" />
            </div>
        </x-form.card>

        <x-form.card id="doc-visa" icon="mdi-airplane" title="วีซ่า" subtitle="Visa">
            <x-slot name="right"><span class="pill pill-none" data-days-left-for="#labour_visa_date_end">-</span></x-slot>
            <div class="row g-3">
                <x-form.input wrapper="col-12" name="labour_visa_number" label="เลขที่วีซ่า" :value="$labour->labour_visa_number" />
                <x-form.input wrapper="col-sm-4" name="labour_visa_date_in" type="date" label="วันที่เดินทางเข้ามา" :value="$labour->labour_visa_date_in" />
                <x-form.input wrapper="col-sm-4" name="labour_visa_date_start" type="date" label="วันที่เริ่มวีซ่า" :value="$labour->labour_visa_date_start" />
                <x-form.input wrapper="col-sm-4" name="labour_visa_date_end" type="date" label="วันหมดอายุ" :value="$labour->labour_visa_date_end" />
            </div>
        </x-form.card>

        <x-form.card id="doc-work" icon="mdi-briefcase" title="ใบอนุญาตทำงาน" subtitle="Work permit">
            <x-slot name="right"><span class="pill pill-none" data-days-left-for="#labour_workpremit_date_end">-</span></x-slot>
            <div class="row g-3">
                <x-form.input wrapper="col-12" name="labour_workpremit_number" label="เลขที่ใบอนุญาตทำงาน" :value="$labour->labour_workpremit_number" />
                <x-form.input wrapper="col-sm-6" name="labour_workpremit_date_start" type="date" label="วันที่ใบอนุญาตเริ่มต้น" :value="$labour->labour_workpremit_date_start" />
                <x-form.input wrapper="col-sm-6" name="labour_workpremit_date_end" type="date" label="วันที่ใบอนุญาตสิ้นสุด" :value="$labour->labour_workpremit_date_end" />
            </div>
        </x-form.card>

        <x-form.card id="doc-90" icon="mdi-calendar-clock" title="รายงานตัว 90 วัน และ ตม." subtitle="90-day report / Immigration">
            <x-slot name="right"><span class="pill pill-none" data-days-left-for="#labour_day90_date_end">-</span></x-slot>
            <div class="row g-3">
                <x-form.input wrapper="col-12" name="labour_tm_number" label="เลขที่ ตม." :value="$labour->labour_tm_number" />
                <x-form.input wrapper="col-sm-6" name="labour_day90_date_start" type="date" label="รายงานตัว 90 วัน เริ่มต้น" :value="$labour->labour_day90_date_start" />
                <x-form.input wrapper="col-sm-6" name="labour_day90_date_end" type="date" label="รายงานตัว 90 วัน สิ้นสุด" :value="$labour->labour_day90_date_end" />
            </div>
        </x-form.card>

    </div>
</div>

{{-- ============================================================ 4. status --}}
<x-form.card id="sec-status" icon="mdi-flag" title="สถานะแรงงาน" subtitle="สถานะการทำงานและการเปิดใช้งานในระบบ" color="warning">
    <div class="row g-3 align-items-end">
        <div class="col-sm-6 col-lg-3">
            <label class="form-label required" for="labour_status_job">สถานะการทำงาน</label>
            <select name="labour_status_job" id="labour_status_job" class="form-select @error('labour_status_job') is-invalid @enderror" required>
                <option value="job" {{ $statusJob === 'job' ? 'selected' : '' }}>ทำงาน</option>
                <option value="resign" {{ $statusJob === 'resign' ? 'selected' : '' }}>ลาออก</option>
                <option value="escape" {{ $statusJob === 'escape' ? 'selected' : '' }}>หลบหนี</option>
            </select>
        </div>
        <div class="col-sm-6 col-lg-3" data-status-box="resign" hidden>
            <x-form.input wrapper="" name="labour_resing_date" type="date" label="วันที่ลาออก" :value="$labour->labour_resing_date" />
        </div>
        <div class="col-sm-6 col-lg-3" data-status-box="escape" hidden>
            <x-form.input wrapper="" name="labour_escape_date" type="date" label="วันที่หลบหนี" :value="$labour->labour_escape_date" />
        </div>
        <div class="col-lg-6">
            <label class="form-label required d-block">สถานะในระบบ</label>
            <div class="seg">
                <input type="radio" class="btn-check" name="labour_status" id="labour_status_enable" value="enable" {{ $status === 'enable' ? 'checked' : '' }} required>
                <label class="btn seg-success" for="labour_status_enable"><i class="mdi mdi-check-circle"></i> เปิดใช้งาน</label>
                <input type="radio" class="btn-check" name="labour_status" id="labour_status_disable" value="disable" {{ $status === 'disable' ? 'checked' : '' }}>
                <label class="btn seg-danger" for="labour_status_disable"><i class="mdi mdi-close-circle"></i> ปิดใช้งาน</label>
            </div>
        </div>
    </div>
</x-form.card>

{{-- ============================================================ 5. files --}}
<x-form.card id="sec-files" icon="mdi-paperclip" title="ไฟล์เอกสาร" subtitle="เพิ่มเอกสารได้ไม่จำกัด ตั้งชื่อเอกสารเองได้ เช่น Passport, Visa, Work Permit" color="success">
    @include('labour._documents')
</x-form.card>

{{-- ============================================================ 6. note --}}
<x-form.card id="sec-note" icon="mdi-clipboard-text" title="หมายเหตุ" subtitle="บันทึกเพิ่มเติม เช่น เงื่อนไขพิเศษของนายจ้าง" color="secondary">
    <x-form.textarea wrapper="" name="labour_note" :value="$labour->labour_note" rows="4" placeholder="พิมพ์หมายเหตุ..." />
</x-form.card>
