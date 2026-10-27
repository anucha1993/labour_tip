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

    <div class="card chat-wrap" id="chatWrap" data-endpoint="{{ route('ai.message') }}" data-user="{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}" data-configured="{{ $configured ? 1 : 0 }}">
        <div class="chat-log" id="chatLog">
            <div class="chat-empty" id="chatEmpty">
                <div class="big"><i class="mdi mdi-robot"></i></div>
                <h5>สวัสดี {{ auth()->user()->name }}</h5>
                <p class="text-2">ผมอ่านข้อมูลจากระบบโดยตรง (อ่านอย่างเดียว) ลองถามแบบนี้ได้เลย</p>
                <div class="chips justify-content-center" id="chatSuggestions">
                    @foreach ($suggestions as $s)
                        <button type="button" data-suggest>{{ $s }}</button>
                    @endforeach
                </div>
            </div>
        </div>
        <form class="chat-input" id="chatForm" data-no-loader>
            <div class="d-flex gap-2 align-items-end">
                <textarea class="form-control" id="chatText" rows="1" placeholder="พิมพ์คำถาม… (Enter เพื่อส่ง, Shift+Enter ขึ้นบรรทัดใหม่)" {{ $configured ? '' : 'disabled' }}></textarea>
                <button type="submit" class="btn btn-primary" id="chatSend" style="height:46px" {{ $configured ? '' : 'disabled' }}><i class="mdi mdi-send"></i><span class="d-none d-sm-inline"> ส่ง</span></button>
            </div>
            <div class="small text-3 mt-2"><i class="mdi mdi-shield"></i> คำถามและผลลัพธ์ที่เกี่ยวข้องจะถูกส่งไปประมวลผลที่ OpenAI · ไฟล์ Excel ที่สร้างเก็บไว้ 24 ชั่วโมง</div>
        </form>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    var wrap = document.getElementById('chatWrap'), log = document.getElementById('chatLog'), form = document.getElementById('chatForm');
    var input = document.getElementById('chatText'), send = document.getElementById('chatSend'), empty = document.getElementById('chatEmpty');
    var endpoint = wrap.dataset.endpoint, userInitial = wrap.dataset.user, configured = wrap.dataset.configured === '1';
    var KEY = 'labour-ai-chat';
    var history = [];
    try { history = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { history = []; }

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
        el.innerHTML = '<span class="avatar">' + (role === 'user' ? esc(userInitial) : '<i class="mdi mdi-robot"></i>') + '</span>' +
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
        if (!text || !configured || send.disabled) return;
        addMessage('user', text);
        history.push({ role: 'user', content: text, time: time() });
        save();
        input.value = ''; autosize();
        send.disabled = true; input.disabled = true;
        addTyping();
        try {
            var res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ message: text, history: history.slice(0, -1).slice(-20).map(function (m) { return { role: m.role, content: m.content }; }) })
            });
            var data = await res.json().catch(function () { return {}; });
            document.getElementById('chatTyping') && document.getElementById('chatTyping').remove();
            if (!res.ok) {
                addMessage('assistant', '**เกิดข้อผิดพลาด:** ' + (data.error || data.message || ('HTTP ' + res.status)));
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
        history = []; save();
        log.querySelectorAll('.msg').forEach(function (m) { m.remove(); });
        if (empty) empty.style.display = '';
        input.focus();
    });
    if (configured) input.focus();
})();
</script>
@endpush
