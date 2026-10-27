{{-- Shared agency form fields. Expects: $agency, $nationalities --}}
<x-form.card id="sec-agency" icon="mdi-earth" title="ข้อมูลเอเจนซี่" subtitle="บริษัทจัดหาแรงงานในประเทศต้นทาง">
    <div class="row g-3">
        <x-form.input wrapper="col-lg-8" name="agency_name" label="ชื่อบริษัท / เอเจนซี่" :value="$agency->agency_name" required />
        <x-form.select wrapper="col-sm-6 col-lg-4" name="agency_nationality" label="สัญชาติ / ประเทศ" :value="$agency->agency_nationality" required placeholder="เลือกสัญชาติ" :options="$nationalities->pluck('name_th', 'code')->all()" />
        <x-form.input wrapper="col-sm-6 col-lg-4" name="agency_tax" label="เลขทะเบียนบริษัท" :value="$agency->agency_tax" required maxlength="20" class="text-mono" />
        <x-form.textarea wrapper="col-lg-8" name="agency_address" label="ที่อยู่" :value="$agency->agency_address" required rows="2" />
    </div>
</x-form.card>

<x-form.card id="sec-contact" icon="mdi-account" title="ผู้ติดต่อ" subtitle="กรรมการหรือผู้ประสานงานของเอเจนซี่" color="success">
    <div class="row g-3">
        <x-form.input wrapper="col-md-4" name="agency_contact" label="ชื่อกรรมการ / ผู้ติดต่อ" :value="$agency->agency_contact" />
        <x-form.input wrapper="col-md-4" name="agency_phone" label="เบอร์ติดต่อ" :value="$agency->agency_phone" />
        <x-form.input wrapper="col-md-4" name="agency_email" type="email" label="อีเมล" :value="$agency->agency_email" />
    </div>
</x-form.card>

<x-form.card id="sec-note" icon="mdi-clipboard-text" title="หมายเหตุ" subtitle="ไม่เกิน 150 ตัวอักษร" color="secondary">
    <x-form.textarea wrapper="" name="agency_note" :value="$agency->agency_note" rows="2" maxlength="150" placeholder="พิมพ์หมายเหตุ..." />
</x-form.card>
