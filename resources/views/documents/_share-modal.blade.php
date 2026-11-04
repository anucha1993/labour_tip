{{-- One generic "แชร์โฟลเดอร์" modal per page. A trigger carries data-share='{"kind","id","title","shares":[...]}' and JS fills the form. --}}
<div class="modal fade" id="dxShareModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="mdi mdi-link-variant"></i> แชร์โฟลเดอร์ "<span id="shTitle"></span>"</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="POST" action="{{ route('documents.share.store') }}" id="shForm">
                @csrf
                <input type="hidden" name="kind" id="shKind">
                <input type="hidden" name="target_id" id="shTarget">
                <input type="hidden" name="return_to" id="shReturn" value="{{ url()->full() }}">
                <div class="modal-body">
                    <div id="shExistingWrap" class="mb-3 d-none">
                        <div class="form-label">ลิงก์ที่แชร์อยู่ (<span id="shExistingCount"></span>)</div>
                        <div id="shExisting"></div>
                        <hr>
                    </div>
                    <div class="form-label fw-semibold">สร้างลิงก์ใหม่</div>
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label" for="shEmail">แชร์ให้อีเมล (คั่นด้วยเครื่องหมายจุลภาคได้หลายคน)</label>
                            <input type="text" name="recipient_email" id="shEmail" class="form-control" placeholder="เช่น hr@company.com, officer@immigration.go.th" maxlength="500" autocomplete="off">
                            @if (\App\Models\DocumentShare::mailReady())
                                <div class="form-text"><i class="mdi mdi-email-outline"></i> ระบบจะส่งลิงก์ไปยังอีเมลเหล่านี้ให้ทันที</div>
                            @endif
                        </div>
                        @if (\App\Models\DocumentShare::mailReady())
                            <div class="col-12">
                                <label class="form-label" for="shMsg">ข้อความถึงผู้รับ (ไม่บังคับ)</label>
                                <textarea name="message" id="shMsg" class="form-control" rows="2" maxlength="1000" placeholder="เช่น รบกวนตรวจเอกสารต่อวีซ่าของ 2 คนนี้ภายในศุกร์นี้ครับ"></textarea>
                            </div>
                        @endif
                        @php $shMailReady = \App\Models\DocumentShare::mailReady(); @endphp
                        <div class="col-12">
                            <div class="form-label">ใครเปิดลิงก์ได้</div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="access" id="shAccessEmail" value="email" checked>
                                <label class="form-check-label" for="shAccessEmail"><i class="mdi mdi-lock text-success"></i> <b>เฉพาะอีเมลที่ระบุ</b> (แนะนำ) · ผู้รับต้องกรอกอีเมลของตัวเอง{{ $shMailReady ? 'และยืนยันรหัส 6 หลักที่ส่งไปทางอีเมล' : '' }}ก่อนเปิด คนอื่นที่ได้ลิงก์ต่อเปิดไม่ได้ และลบสิทธิ์รายคนได้ภายหลัง</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="access" id="shAccessAnyone" value="anyone">
                                <label class="form-check-label" for="shAccessAnyone"><i class="mdi mdi-lock-open text-warning"></i> ใครก็ได้ที่มีลิงก์ · ไม่ต้องยืนยันตัวตน ใครได้ลิงก์ต่อก็เปิดได้ (อีเมลเป็นแค่บันทึกว่าส่งให้ใคร) ใช้เมื่อจำเป็นเท่านั้น</label>
                            </div>
                            <div class="form-text text-3" id="shAccessHint"></div>
                            @unless ($shMailReady)
                                <div class="form-text text-warning"><i class="mdi mdi-alert"></i> server ยังไม่ได้ตั้งค่าส่งอีเมล (MAIL_* ใน .env) โหมด "เฉพาะอีเมลที่ระบุ" จะตรวจแค่ว่าอีเมลที่กรอกตรงกับรายชื่อ ยังไม่ได้พิสูจน์ว่าเป็นเจ้าของอีเมลจริง</div>
                            @endunless
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="shExp">ใช้ได้นาน</label>
                            <select name="expires_days" id="shExp" class="form-select">
                                <option value="7">7 วัน</option>
                                <option value="1">1 วัน</option>
                                <option value="30">30 วัน</option>
                                <option value="90">90 วัน</option>
                                <option value="0">ไม่มีวันหมดอายุ</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="shPw">รหัสผ่าน (ไม่บังคับ)</label>
                            <input type="text" name="password" id="shPw" class="form-control" placeholder="อย่างน้อย 4 ตัว" maxlength="50" autocomplete="off">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="shLabel">หมายเหตุ (ไม่บังคับ)</label>
                            <input type="text" name="label" id="shLabel" class="form-control" placeholder="เช่น เอกสารต่อวีซ่ารอบ พ.ย." maxlength="150">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="can_download" id="shDl" value="1" checked>
                                <label class="form-check-label" for="shDl">อนุญาตให้ดาวน์โหลดไฟล์ (ไม่ติ๊ก = ดูได้อย่างเดียว)</label>
                            </div>
                        </div>
                    </div>
                    <div class="text-2 small mt-3"><i class="mdi mdi-information"></i> ผู้รับไม่ต้องมีบัญชีในระบบ ลิงก์จะใช้ไม่ได้ทันทีเมื่อกดยกเลิกหรือหมดอายุ และดูได้ว่าใครเปิดกี่ครั้งในหน้า "ลิงก์ที่แชร์"</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-soft" data-bs-dismiss="modal">ปิด</button>
                    <button type="submit" class="btn btn-primary"><i class="mdi mdi-link-variant"></i> สร้างลิงก์</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
// "เฉพาะอีเมลที่ระบุ" is always the default; it needs at least one recipient, so the e-mail field
// becomes required while it is selected. "ใครก็ได้ที่มีลิงก์" has to be chosen on purpose.
(function () {
    var email = document.getElementById('shEmail'), em = document.getElementById('shAccessEmail'), any = document.getElementById('shAccessAnyone'), hint = document.getElementById('shAccessHint');
    if (!email || !em || !any) return;
    function sync() {
        email.required = em.checked;
        hint.textContent = em.checked && email.value.trim() === '' ? 'ต้องใส่อีเมลผู้รับอย่างน้อย 1 คน (หรือเลือก "ใครก็ได้ที่มีลิงก์" หากตั้งใจเปิดสาธารณะ)' : '';
    }
    email.addEventListener('input', sync);
    em.addEventListener('change', sync);
    any.addEventListener('change', sync);
    document.getElementById('dxShareModal').addEventListener('show.bs.modal', function () { setTimeout(sync, 0); });
})();
</script>
<form method="POST" action="#" id="shUpdateForm" class="d-none" data-confirm="ลบสิทธิ์อีเมลนี้ออกจากลิงก์?" data-confirm-title="ลบสิทธิ์ผู้รับ" data-confirm-ok="ลบสิทธิ์">@csrf @method('PATCH')<input type="hidden" name="recipient_email" id="shUpdateEmails"><input type="hidden" name="return_to" value="{{ url()->full() }}"></form>
<form method="POST" action="#" id="shAddForm" class="d-none">@csrf @method('PATCH')<input type="hidden" name="recipient_email" id="shAddEmails"><input type="hidden" name="return_to" value="{{ url()->full() }}"></form>
<form method="POST" action="#" id="shRevokeForm" class="d-none" data-confirm="ยกเลิกลิงก์แชร์นี้? คนที่มีลิงก์จะเปิดไม่ได้อีก" data-confirm-title="ยกเลิกลิงก์แชร์" data-confirm-ok="ยกเลิกลิงก์">@csrf @method('DELETE')</form>

@if (session('share_url'))
    @php
        $doneEmails = (string) session('share_emails');
        $doneSent = (string) session('share_sent');
        $doneMailError = (string) session('share_mail_error');
        $mailto = $doneEmails !== ''
            ? 'mailto:' . rawurlencode(str_replace(' ', '', $doneEmails)) . '?subject=' . rawurlencode('เอกสารแรงงาน - ' . session('share_title')) . '&body=' . rawurlencode("เปิดดูเอกสารได้ที่ลิงก์นี้\n" . session('share_url') . "\n\n" . session('success') . (session('share_has_password') ? "\nลิงก์นี้มีรหัสผ่าน จะแจ้งให้แยกต่างหาก" : '') . "\n\nThe Importer 168 Co., Ltd.")
            : '';
    @endphp
    <div class="modal fade" id="dxShareDone" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h6 class="modal-title"><i class="mdi mdi-check-circle text-success"></i> สร้างลิงก์แชร์แล้ว</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
                <div class="modal-body">
                    <p class="text-2 small mb-2">{{ session('success') }}@if ($doneEmails !== '') · แชร์ให้ <b>{{ $doneEmails }}</b>@endif</p>
                    @if ($doneSent !== '')
                        <div class="alert alert-success py-2 small mb-2"><i class="mdi mdi-email-check"></i> ส่งลิงก์ไปที่ <b>{{ $doneSent }}</b> ทางอีเมลแล้ว{{ session('share_has_password') ? ' (ยังไม่ได้ส่งรหัสผ่าน)' : '' }}</div>
                    @elseif ($doneMailError !== '')
                        <div class="alert alert-warning py-2 small mb-2"><i class="mdi mdi-alert"></i> {{ $doneMailError }}</div>
                    @endif
                    <div class="input-group">
                        <input type="text" class="form-control text-mono" value="{{ session('share_url') }}" readonly onclick="this.select()" id="dxShareDoneUrl">
                        <button type="button" class="btn btn-primary" data-copy="{{ session('share_url') }}"><i class="mdi mdi-content-copy"></i> คัดลอก</button>
                    </div>
                    @if (session('share_restricted'))
                        <div class="text-success small mt-2"><i class="mdi mdi-lock"></i> เปิดได้เฉพาะอีเมลที่ระบุ ผู้รับต้องกรอกอีเมล{{ \App\Models\DocumentShare::mailReady() ? 'และรหัสยืนยันที่ส่งไปทางอีเมล' : '' }}ก่อน คนอื่นที่ได้ลิงก์ต่อเปิดไม่ได้</div>
                    @else
                        <div class="text-warning small mt-2"><i class="mdi mdi-lock-open"></i> ลิงก์นี้ใครก็เปิดได้โดยไม่ต้องยืนยันตัวตน{{ session('share_has_password') ? ' (ต้องรู้รหัสผ่าน)' : '' }} ถ้าต้องการจำกัดผู้เปิด ให้ยกเลิกแล้วสร้างใหม่แบบ "เฉพาะอีเมลที่ระบุ"</div>
                    @endif
                    @if (session('share_has_password'))
                        <div class="text-warning small mt-2"><i class="mdi mdi-key"></i> ลิงก์นี้มีรหัสผ่าน อย่าลืมแจ้งรหัสให้ผู้รับแยกต่างหาก</div>
                    @endif
                </div>
                <div class="modal-footer">
                    @if ($doneEmails !== '' && \App\Models\DocumentShare::mailReady() && session('share_id'))
                        <form method="POST" action="{{ route('documents.share.notify', session('share_id')) }}" class="d-inline">@csrf<button type="submit" class="btn btn-soft"><i class="mdi mdi-email"></i> {{ $doneSent !== '' ? 'ส่งอีเมลอีกครั้ง' : 'ส่งทางอีเมล' }}</button></form>
                    @elseif ($mailto !== '')
                        <a href="{{ $mailto }}" class="btn btn-soft" data-no-loader><i class="mdi mdi-email"></i> ส่งทางอีเมล</a>
                    @endif
                    <a href="{{ session('share_url') }}" target="_blank" rel="noopener" class="btn btn-soft" data-no-loader><i class="mdi mdi-open-in-new"></i> เปิดดูแบบผู้รับ</a>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">เสร็จ</button>
                </div>
            </div>
        </div>
    </div>
@endif
