{{-- Shared company form fields. Expects: $company, $provinces, $amphures, $districts --}}
@php
    $provinceId = old('company_province', $company->company_province);
    $amphurId = old('company_amphur', $company->company_amphur);
    $districtId = old('company_district', $company->company_district);
    $status = old('company_status', $company->company_status ?: 'enable');
@endphp

<x-form.nav :items="[
    'sec-company' => ['icon' => 'mdi-domain', 'label' => 'ข้อมูลบริษัท'],
    'sec-address' => ['icon' => 'mdi-map-marker', 'label' => 'ที่อยู่'],
    'sec-contact' => ['icon' => 'mdi-account', 'label' => 'ผู้ติดต่อ'],
    'sec-directors' => ['icon' => 'mdi-account-multiple', 'label' => 'กรรมการ'],
    'sec-other' => ['icon' => 'mdi-clipboard-text', 'label' => 'อื่นๆ'],
]" />

<x-form.card id="sec-company" icon="mdi-domain" title="ข้อมูลบริษัท" subtitle="ชื่อและทะเบียนนิติบุคคลของนายจ้าง">
    <div class="row g-3">
        <x-form.input wrapper="col-lg-6" name="company_name" label="ชื่อบริษัท / ชื่อนายจ้าง" :value="$company->company_name" required />
        <x-form.input wrapper="col-sm-6 col-lg-3" name="company_tax" label="เลขทะเบียนบริษัท" :value="$company->company_tax" required class="text-mono" />
        <x-form.input wrapper="col-sm-6 col-lg-3" name="company_type" label="ประเภทกิจการ" :value="$company->company_type" required placeholder="เช่น ก่อสร้าง, โรงงาน" />
    </div>
</x-form.card>

<x-form.card id="sec-address" icon="mdi-map-marker" title="ที่อยู่" subtitle="เลือกจังหวัดก่อน ระบบจะโหลดอำเภอ ตำบล และรหัสไปรษณีย์ให้" color="info">
    <div class="row g-3">
        <x-form.input wrapper="col-12" name="company_address" label="ที่อยู่ (บ้านเลขที่ หมู่ ซอย ถนน)" :value="$company->company_address" required />
        <div class="col-sm-6 col-lg-3">
            <label class="form-label required" for="company_province">จังหวัด</label>
            <select name="company_province" id="company_province" class="form-select js-select2 @error('company_province') is-invalid @enderror" data-placeholder="เลือกจังหวัด" required>
                <option value="">เลือกจังหวัด</option>
                @foreach ($provinces as $p)
                    <option value="{{ $p->id }}" {{ (string) $provinceId === (string) $p->id ? 'selected' : '' }}>{{ $p->name_th }}</option>
                @endforeach
            </select>
            @error('company_province')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-sm-6 col-lg-3">
            <label class="form-label required" for="company_amphur">อำเภอ / เขต</label>
            <select name="company_amphur" id="company_amphur" class="form-select js-select2 @error('company_amphur') is-invalid @enderror" data-placeholder="เลือกอำเภอ/เขต" required>
                <option value="">เลือกอำเภอ/เขต</option>
                @foreach ($amphures as $a)
                    <option value="{{ $a->id }}" {{ (string) $amphurId === (string) $a->id ? 'selected' : '' }}>{{ $a->name_th }}</option>
                @endforeach
            </select>
            @error('company_amphur')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-sm-6 col-lg-3">
            <label class="form-label required" for="company_district">ตำบล / แขวง</label>
            <select name="company_district" id="company_district" class="form-select js-select2 @error('company_district') is-invalid @enderror" data-placeholder="เลือกตำบล/แขวง" required>
                <option value="">เลือกตำบล/แขวง</option>
                @foreach ($districts as $d)
                    <option value="{{ $d->id }}" {{ (string) $districtId === (string) $d->id ? 'selected' : '' }}>{{ $d->name_th }}</option>
                @endforeach
            </select>
            @error('company_district')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <x-form.input wrapper="col-sm-6 col-lg-3" name="company_zipcode" label="รหัสไปรษณีย์" :value="$company->company_zipcode" required id="company_zipcode" inputmode="numeric" maxlength="10" />
    </div>
</x-form.card>

<x-form.card id="sec-contact" icon="mdi-account" title="ผู้ติดต่อ" subtitle="ผู้ติดต่อฝั่งนายจ้าง และผู้ประสานงานฝั่งเรา" color="success">
    <div class="row g-3">
        <x-form.input wrapper="col-lg-6" name="company_contact" label="ชื่อ-สกุล ผู้ติดต่อ / นายจ้าง" :value="$company->company_contact" required />
        <x-form.input wrapper="col-sm-6 col-lg-3" name="company_phone" label="เบอร์ติดต่อ" :value="$company->company_phone" required inputmode="tel" />
        <x-form.input wrapper="col-sm-6 col-lg-3" name="company_fax" label="แฟกซ์" :value="$company->company_fax" />
        <x-form.input wrapper="col-lg-6" name="company_email" type="email" label="อีเมล" :value="$company->company_email" />
        <x-form.input wrapper="col-lg-6" name="company_contact_sale" label="ผู้ประสานงานการตลาด (ฝั่งเรา)" :value="$company->company_contact_sale" required />
    </div>
</x-form.card>

<x-form.card id="sec-directors" icon="mdi-account-multiple" title="กรรมการผู้ลงนาม" subtitle="กรอกได้สูงสุด 5 ท่าน" color="warning">
    <div class="row g-3">
        @for ($n = 1; $n <= 5; $n++)
            <x-form.input wrapper="col-md-6 col-lg-4" name="company_contact{{ $n }}" label="กรรมการท่านที่ {{ $n }}" :value="$company->{'company_contact' . $n}" placeholder="ชื่อ-สกุล" />
        @endfor
    </div>
</x-form.card>

<x-form.card id="sec-other" icon="mdi-clipboard-text" title="อื่นๆ" subtitle="หมายเหตุและสถานะการใช้งาน" color="secondary">
    <div class="row g-3">
        <x-form.textarea wrapper="col-lg-8" name="company_note" label="หมายเหตุ" :value="$company->company_note" rows="3" />
        <div class="col-lg-4">
            <label class="form-label required d-block">สถานะ</label>
            <div class="seg">
                <input type="radio" class="btn-check" name="company_status" id="company_status_enable" value="enable" {{ $status === 'enable' ? 'checked' : '' }} required>
                <label class="btn seg-success" for="company_status_enable"><i class="mdi mdi-check-circle"></i> เปิดใช้งาน</label>
                <input type="radio" class="btn-check" name="company_status" id="company_status_disable" value="disable" {{ $status === 'disable' ? 'checked' : '' }}>
                <label class="btn seg-danger" for="company_status_disable"><i class="mdi mdi-close-circle"></i> ปิดใช้งาน</label>
            </div>
        </div>
    </div>
</x-form.card>

@push('scripts')
<script>
    (function () {
        var token = document.querySelector('meta[name="csrf-token"]').content;
        var $prov = jQuery('#company_province'), $amp = jQuery('#company_amphur'), $dis = jQuery('#company_district'), $zip = jQuery('#company_zipcode');

        function post(url, data) {
            return jQuery.ajax({ url: url, method: 'POST', data: data, headers: { 'X-CSRF-TOKEN': token } });
        }
        $prov.on('change', function () {
            $amp.html('<option value="">กำลังโหลด...</option>').trigger('change');
            $dis.html('<option value="">เลือกตำบล/แขวง</option>').trigger('change');
            if (!this.value) { $amp.html('<option value="">เลือกอำเภอ/เขต</option>').trigger('change'); return; }
            post(@json(route('province')), { province: this.value }).done(function (html) { $amp.html(html).trigger('change'); });
        });
        $amp.on('change', function () {
            if (!this.value) return;
            $dis.html('<option value="">กำลังโหลด...</option>').trigger('change');
            post(@json(route('amphur')), { amphur: this.value }).done(function (html) { $dis.html(html).trigger('change'); });
        });
        $dis.on('change', function () {
            if (!this.value) return;
            post(@json(route('district')), { district: this.value }).done(function (zip) { if (zip) $zip.val(zip); });
        });
    })();
</script>
@endpush
