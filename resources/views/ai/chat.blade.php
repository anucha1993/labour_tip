@extends('layouts.main_layout')

@section('title', 'ผู้ช่วย AI')
@section('crumb', 'ถาม-ตอบข้อมูลแรงงานและสร้างรายงาน Excel')

@push('head')
<script src="https://cdnjs.cloudflare.com/ajax/libs/marked/12.0.2/marked.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js"></script>
@endpush

@section('content')
    <x-page-header title="ผู้ช่วย AI" subtitle="ถามข้อมูลแรงงาน นายจ้าง เอเจนซี่ เอกสารใกล้หมดอายุ หรือสั่งสร้างรายงาน Excel ด้วยภาษาธรรมดา">
        <span class="badge-soft badge-soft-secondary d-none d-md-inline-flex align-items-center gap-1"><i class="mdi mdi-robot"></i> {{ $model }}</span>
        <button type="button" class="btn btn-soft" id="chatClear"><i class="mdi mdi-delete"></i> ล้างบทสนทนา</button>
    </x-page-header>

    @if (! $configured)
        <div class="alert alert-warning"><i class="mdi mdi-alert"></i>
            <div>ยังไม่ได้ตั้งค่า <code>OPENAI_API_KEY</code> ใน <code>.env</code> ใส่คีย์แล้วรัน <code>php artisan config:clear</code> จึงจะใช้งานได้</div>
        </div>
    @endif

    @if ($billing)
        {{-- แพ็กเกจ AI (DataCorp): credits ที่ใช้ + ปุ่มสมัคร/ชำระเงิน — เติมด้วย JS จาก route ai.plan --}}
        <div class="alert alert-info d-none align-items-center gap-3 flex-wrap" id="aiPlan" data-endpoint="{{ route('ai.plan') }}">
            <i class="mdi mdi-robot-outline fs-4"></i>
            <div class="flex-grow-1" style="min-width:220px">
                <div><b id="aiPlanName"></b> <span class="text-3" id="aiPlanRenew"></span></div>
                <div class="progress mt-1" style="height:6px;max-width:420px"><div class="progress-bar" id="aiPlanBar" style="width:0%"></div></div>
                <div class="small text-3 mt-1" id="aiPlanUsage"></div>
            </div>
            <a class="btn btn-primary d-none" id="aiPlanPay" target="_blank" rel="noopener" data-no-loader></a>
            <span class="small text-3 d-none" id="aiPlanAsk">ติดต่อผู้ดูแลระบบเพื่อสมัคร/ชำระค่าบริการ AI</span>
        </div>

        <div class="modal fade" id="aiPayModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title"><i class="mdi mdi-credit-card-outline"></i> <span id="aiPayTitle">ต้องชำระค่าบริการ AI</span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
                    <div class="modal-body">
                        <p id="aiPayText" class="mb-2"></p>
                        <p class="small text-3 mb-0" id="aiPayNote">ชำระเงินแล้วใช้งานต่อได้ทันที ไม่ต้องตั้งค่าใหม่</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-soft" data-bs-dismiss="modal">ไว้ทีหลัง</button>
                        <a class="btn btn-primary" id="aiPayGo" target="_blank" rel="noopener" data-no-loader><i class="mdi mdi-open-in-new"></i> ไปหน้าชำระเงิน</a>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="card chat-wrap" id="chatWrap" data-endpoint="{{ route('ai.message') }}" data-user="{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}" data-avatar="{{ auth()->user()->avatarUrl() ?? '' }}" data-configured="{{ $configured ? 1 : 0 }}">
        <div class="chat-log" id="chatLog">
            <div class="chat-empty" id="chatEmpty">
                <div class="big"><i class="mdi mdi-robot"></i></div>
                <h5>สวัสดี {{ auth()->user()->name }}</h5>
                <p class="text-2">ผมอ่านข้อมูลจากระบบโดยตรง อ่านไฟล์เอกสารที่อัปโหลดไว้หรือไฟล์ที่แนบมากับคำถามได้{{ auth()->user()->type === 'MasterAdmin' ? ' และแก้ไขข้อมูลให้ได้หลังคุณยืนยัน' : '' }} ลองถามแบบนี้ได้เลย</p>
                <div class="chips justify-content-center" id="chatSuggestions">
                    @foreach ($suggestions as $s)
                        <button type="button" data-suggest>{{ $s }}</button>
                    @endforeach
                </div>
            </div>
        </div>
        <form class="chat-input" id="chatForm" data-no-loader>
            <div class="chat-attach d-none" id="chatAttach">
                <i class="mdi mdi-paperclip"></i> <span id="chatAttachName"></span>
                <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-danger" id="chatAttachClear" title="เอาไฟล์ออก"><i class="mdi mdi-close-circle"></i></button>
            </div>
            <div class="d-flex gap-2 align-items-end">
                <input type="file" id="chatFile" class="d-none" accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.csv,application/pdf,image/*,.sheet,.ms-excel">
                <button type="button" class="btn btn-soft" id="chatAttachBtn" style="height:46px" title="แนบไฟล์ (PDF, รูป, Excel, CSV ไม่เกิน 15 MB)" {{ $configured ? '' : 'disabled' }}><i class="mdi mdi-paperclip"></i></button>
                <textarea class="form-control" id="chatText" rows="1" placeholder="พิมพ์คำถาม… (Enter เพื่อส่ง, Shift+Enter ขึ้นบรรทัดใหม่)" {{ $configured ? '' : 'disabled' }}></textarea>
                <button type="submit" class="btn btn-primary" id="chatSend" style="height:46px" {{ $configured ? '' : 'disabled' }}><i class="mdi mdi-send"></i><span class="d-none d-sm-inline"> ส่ง</span></button>
            </div>
            <div class="small text-3 mt-2"><i class="mdi mdi-shield"></i> คำถาม ไฟล์แนบ และผลลัพธ์ที่เกี่ยวข้องจะถูกส่งไปประมวลผลที่ OpenAI{{ config('services.google.vision_key') || config('services.google.translate_key') ? ' และ Google Vision' : '' }} · ไฟล์ Excel และไฟล์แนบเก็บไว้ 24 ชั่วโมง</div>
        </form>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    var wrap = document.getElementById('chatWrap'), log = document.getElementById('chatLog'), form = document.getElementById('chatForm');
    var input = document.getElementById('chatText'), send = document.getElementById('chatSend'), empty = document.getElementById('chatEmpty');
    var fileInput = document.getElementById('chatFile'), attachBox = document.getElementById('chatAttach'), attachName = document.getElementById('chatAttachName');
    function setFile(f) {
        if (f && f.size > 15 * 1024 * 1024) { alert('ไฟล์แนบต้องมีขนาดไม่เกิน 15 MB'); f = null; }
        if (!f) { fileInput.value = ''; attachBox.classList.add('d-none'); attachName.textContent = ''; return; }
        attachName.textContent = f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
        attachBox.classList.remove('d-none'); input.focus();
    }
    document.getElementById('chatAttachBtn').addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () { setFile(fileInput.files[0]); });
    document.getElementById('chatAttachClear').addEventListener('click', function () { setFile(null); });
    ['dragenter', 'dragover'].forEach(function (ev) { form.addEventListener(ev, function (e) { e.preventDefault(); form.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { form.addEventListener(ev, function (e) { e.preventDefault(); form.classList.remove('is-over'); }); });
    form.addEventListener('drop', function (e) { if (e.dataTransfer.files && e.dataTransfer.files[0]) { fileInput.files = e.dataTransfer.files; setFile(fileInput.files[0]); } });
    var endpoint = wrap.dataset.endpoint, userInitial = wrap.dataset.user, userAvatar = wrap.dataset.avatar || '', configured = wrap.dataset.configured === '1';
    var KEY = 'labour-ai-chat';
    var history = [];
    try { history = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { history = []; }
    // Conversation id groups the turns of this tab for the admin log page.
    var convo = '';
    try { convo = sessionStorage.getItem(KEY + ':convo') || ''; } catch (e) {}
    function newConvo() {
        convo = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
        try { sessionStorage.setItem(KEY + ':convo', convo); } catch (e) {}
    }
    if (!convo) newConvo();

    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function render(md) {
        if (!window.marked || !window.DOMPurify) return esc(md).replace(/\n/g, '<br>');
        var html = marked.parse(md, { breaks: true, gfm: true });
        html = DOMPurify.sanitize(html, { ADD_ATTR: ['target'] });
        var div = document.createElement('div'); div.innerHTML = html;
        div.querySelectorAll('a').forEach(function (a) { a.target = '_blank'; a.rel = 'noopener'; });
        return div.innerHTML;
    }
    function time() { return new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }); }

    function addMessage(role, content, reports, meta) {
        if (empty) empty.style.display = 'none';
        var el = document.createElement('div');
        el.className = 'msg ' + (role === 'user' ? 'msg-user' : 'msg-ai');
        var reportHtml = '';
        (reports || []).forEach(function (r) {
            reportHtml += '<div><a class="report-chip" href="' + esc(r.download_url) + '" data-no-loader><i class="mdi mdi-file-excel"></i>' + esc(r.title) + ' · ' + esc(r.rows) + ' แถว' + (r.truncated ? ' (ตัดที่ 5,000)' : '') + ' <i class="mdi mdi-download"></i></a></div>';
        });
        el.innerHTML = (role === 'user' && userAvatar ? '<img class="avatar avatar-img" src="' + esc(userAvatar) + '" alt="">' : '<span class="avatar">' + (role === 'user' ? esc(userInitial) : '<i class="mdi mdi-robot"></i>') + '</span>') +
            '<div><div class="msg-body">' + (role === 'user' ? esc(content).replace(/\n/g, '<br>') : render(content)) + reportHtml + '</div>' +
            '<div class="msg-meta">' + esc(meta || time()) + '</div></div>';
        log.appendChild(el);
        log.scrollTop = log.scrollHeight;
        return el;
    }
    function addTyping() {
        var el = document.createElement('div');
        el.className = 'msg msg-ai'; el.id = 'chatTyping';
        el.innerHTML = '<span class="avatar"><i class="mdi mdi-robot"></i></span><div class="msg-body"><span class="typing"><i></i><i></i><i></i></span></div>';
        log.appendChild(el); log.scrollTop = log.scrollHeight;
    }
    function save() { try { sessionStorage.setItem(KEY, JSON.stringify(history.slice(-40))); } catch (e) {} }

    // restore previous conversation of this browser tab
    history.forEach(function (m) { addMessage(m.role, m.content, m.reports, m.time); });

    async function ask(text) {
        text = text.trim();
        var file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
        if (!text && file) text = 'อ่านไฟล์นี้แล้วสรุปให้หน่อย';
        if (!text || !configured || send.disabled) return;
        var shown = file ? '📎 ' + file.name + '\n' + text : text;
        addMessage('user', shown);
        history.push({ role: 'user', content: shown, time: time() });
        save();
        input.value = ''; autosize();
        send.disabled = true; input.disabled = true;
        addTyping();
        try {
            var csrf = document.querySelector('meta[name="csrf-token"]').content;
            var hist = history.slice(0, -1).slice(-20).map(function (m) { return { role: m.role, content: m.content }; });
            var res;
            if (file) {
                var fd = new FormData();
                fd.append('message', text); fd.append('conversation', convo); fd.append('history', JSON.stringify(hist)); fd.append('attachment', file);
                res = await fetch(endpoint, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: fd });
                setFile(null);
            } else {
                res = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ message: text, conversation: convo, history: hist })
                });
            }
            var data = await res.json().catch(function () { return {}; });
            document.getElementById('chatTyping') && document.getElementById('chatTyping').remove();
            if (res.status === 402 && data.payment) {
                var p = data.payment;
                addMessage('assistant', '**' + p.title + '** — ' + (p.pay_url ? 'กดปุ่มด้านล่างเพื่อชำระเงินแล้วใช้งานต่อได้ทันที' : 'กรุณาติดต่อผู้ดูแลระบบเพื่อชำระค่าบริการ'));
                if (p.pay_url) {
                    var a = document.createElement('a');
                    a.className = 'btn btn-primary btn-sm mt-2'; a.href = p.pay_url; a.target = '_blank'; a.rel = 'noopener'; a.setAttribute('data-no-loader', '');
                    a.innerHTML = '<i class="mdi mdi-credit-card-outline"></i> ชำระเงิน / สมัครใช้ต่อ';
                    log.lastElementChild.querySelector('.msg-body').appendChild(a);
                }
                showPayModal(p.title, p.detail, p.pay_url);
                loadPlan(true);
            } else if (!res.ok) {
                var errText = data.error || data.message || ('HTTP ' + res.status);
                if (data.errors) { errText = Object.values(data.errors).map(function (a) { return a.join(' '); }).join(' '); }
                addMessage('assistant', '**เกิดข้อผิดพลาด:** ' + errText);
            } else {
                var meta = time() + (data.tools_used && data.tools_used.length ? ' · ใช้เครื่องมือ: ' + data.tools_used.join(', ') : '');
                addMessage('assistant', data.answer, data.reports, meta);
                history.push({ role: 'assistant', content: data.answer, reports: data.reports, time: meta });
                save();
            }
        } catch (e) {
            document.getElementById('chatTyping') && document.getElementById('chatTyping').remove();
            addMessage('assistant', '**เชื่อมต่อไม่สำเร็จ:** ' + e.message);
        } finally {
            send.disabled = false; input.disabled = false; input.focus();
        }
    }

    function autosize() { input.style.height = 'auto'; input.style.height = Math.min(160, input.scrollHeight) + 'px'; }
    input.addEventListener('input', autosize);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });
    form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
    document.querySelectorAll('[data-suggest]').forEach(function (b) { b.addEventListener('click', function () { ask(b.textContent); }); });
    document.getElementById('chatClear').addEventListener('click', function () {
        history = []; save(); newConvo();
        log.querySelectorAll('.msg').forEach(function (m) { m.remove(); });
        if (empty) empty.style.display = '';
        input.focus();
    });
    if (configured) input.focus();

    // ---------- แพ็กเกจ AI (DataCorp): แถบ credits + ปุ่มสมัคร/ชำระเงิน + popup เมื่อหมดอายุ/credits หมด ----------
    var planBox = document.getElementById('aiPlan');
    function showPayModal(title, text, url) {
        var m = document.getElementById('aiPayModal');
        if (!m || !window.bootstrap) { if (url && confirm(title + '\n\n' + (text || '') + '\n\nไปหน้าชำระเงินตอนนี้ไหม?')) window.open(url, '_blank'); return; }
        document.getElementById('aiPayTitle').textContent = title;
        document.getElementById('aiPayText').textContent = text || '';
        var go = document.getElementById('aiPayGo');
        if (url) { go.href = url; go.classList.remove('d-none'); document.getElementById('aiPayNote').textContent = 'ชำระเงินแล้วใช้งานต่อได้ทันที ไม่ต้องตั้งค่าใหม่'; }
        else { go.classList.add('d-none'); document.getElementById('aiPayNote').textContent = 'กรุณาติดต่อผู้ดูแลระบบ (MasterAdmin) เพื่อชำระค่าบริการ'; }
        bootstrap.Modal.getOrCreateInstance(m).show();
    }
    window.showPayModal = showPayModal;
    async function loadPlan(fresh) {
        if (!planBox) return;
        try {
            var s = await (await fetch(planBox.dataset.endpoint + (fresh ? '?fresh=1' : ''), { headers: { 'Accept': 'application/json' } })).json();
            if (!s.enabled || !s.plan) return;
            var pct = s.included ? Math.min(100, Math.round(s.used / s.included * 100)) : 0;
            planBox.classList.remove('d-none'); planBox.classList.add('d-flex');
            planBox.classList.toggle('alert-info', !s.blocked && pct < 90);
            planBox.classList.toggle('alert-warning', !s.blocked && pct >= 90);
            planBox.classList.toggle('alert-danger', !!s.blocked);
            document.getElementById('aiPlanName').textContent = 'แพ็กเกจ AI: ' + s.plan;
            document.getElementById('aiPlanRenew').textContent = s.renews_at ? '· ' + (s.blocked ? 'หมดอายุ ' : /trial\s*$/i.test(s.plan || '') ? 'ทดลองใช้ถึง ' : 'ต่ออายุรอบถัดไป ') + String(s.renews_at).slice(0, 10) : '';
            var bar = document.getElementById('aiPlanBar');
            bar.style.width = pct + '%'; bar.className = 'progress-bar' + (pct >= 100 || s.blocked ? ' bg-danger' : pct >= 90 ? ' bg-warning' : '');
            document.getElementById('aiPlanUsage').textContent = s.blocked ? (s.message || 'ใช้งานไม่ได้ — ต้องชำระเงิน')
                : (s.included ? 'ใช้ไป ' + s.used.toLocaleString() + ' / ' + s.included.toLocaleString() + ' credits (เหลือ ' + (s.remaining || 0).toLocaleString() + ')' : 'ใช้ไป ' + s.used.toLocaleString() + ' credits');
            // ปุ่มแสดงเมื่อ: ถูกบล็อก (ชำระเงิน) · ทดลองใช้ (สมัครจริง) · ใกล้หมดอายุภายใน 3 วัน (ต่ออายุ) — แพ็กเกจที่จ่ายแล้วและยังไม่ใกล้หมด ไม่มีปุ่ม
            var trial = /trial\s*$/i.test(s.plan || '');
            var btn = document.getElementById('aiPlanPay'), url = s.blocked ? s.pay_url : ((trial || s.renew_due) ? s.upgrade_url : null);
            if (s.renew_due && !s.blocked) {
                planBox.classList.remove('alert-info'); planBox.classList.add('alert-warning');
                document.getElementById('aiPlanRenew').textContent = '· ' + (s.days_left ? 'หมดอายุในอีก ' + s.days_left + ' วัน' : 'หมดอายุวันนี้') +
                    (s.auto_renew ? ' (ตัดบัตรต่ออายุอัตโนมัติ)' : '');
            }
            if (url) {
                btn.href = url;
                btn.innerHTML = '<i class="mdi mdi-credit-card-outline"></i> ' + (s.blocked ? 'ชำระเงิน / ใช้งานต่อ'
                    : s.renew_due && !trial ? 'ต่ออายุ ' + s.upgrade_plan
                    : 'สมัคร ' + s.upgrade_plan + (s.upgrade_price ? ' ฿' + Number(s.upgrade_price).toLocaleString() + '/เดือน' : ''));
                btn.classList.remove('d-none');
            } else btn.classList.add('d-none');
            document.getElementById('aiPlanAsk').classList.toggle('d-none', !!s.can_pay || !(s.blocked || /trial/i.test(s.plan)));
        } catch (e) { /* แถบแพ็กเกจเป็นข้อมูลเสริม ไม่ขัดการใช้งาน */ }
    }
    loadPlan(false);
})();
</script>
@endpush
