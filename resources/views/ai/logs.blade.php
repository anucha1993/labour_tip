@extends('layouts.main_layout')

@section('title', 'ประวัติการใช้ผู้ช่วย AI')
@section('crumb', 'การจัดการ / AI Chat Logs')

@push('head')
<script src="https://cdnjs.cloudflare.com/ajax/libs/marked/12.0.2/marked.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js"></script>
<style>
    .ai-q { white-space: pre-wrap; word-break: break-word; }
    .ai-q.clamp { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .ai-tools .badge-soft { font-size: .68rem; margin-right: 2px; }
    .ai-bubble { border-radius: 14px; padding: 12px 14px; margin-bottom: 10px; max-width: 100%; }
    .ai-bubble.user { background: var(--brand-50); }
    .ai-bubble.assistant { background: var(--surface-2); }
    .ai-bubble .who { font-size: .72rem; color: var(--text-2); margin-bottom: 4px; }
    .ai-bubble .md table { width: 100%; font-size: .85rem; margin: 8px 0; }
    .ai-bubble .md table td, .ai-bubble .md table th { border: 1px solid var(--border); padding: 4px 8px; }
    .ai-bubble .md p:last-child { margin-bottom: 0; }
    .ai-bubble.error { background: rgba(220, 38, 38, .08); color: var(--danger); }
    .ai-top-users .row-item { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; border-bottom: 1px dashed var(--border); font-size: .88rem; }
    .ai-top-users .row-item:last-child { border-bottom: 0; }
</style>
@endpush

@section('content')
    @php $f = $filters; @endphp

    <x-page-header title="ประวัติการใช้ผู้ช่วย AI" subtitle="ทุกคำถามที่สมาชิกถามผู้ช่วย AI พร้อมคำตอบ เครื่องมือที่ใช้ และจำนวน token">
        <a href="{{ route('ai.chat') }}" class="btn btn-soft"><i class="mdi mdi-robot"></i> ไปหน้าแชท</a>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><x-stat-card label="คำถาม (ตามตัวกรอง)" :value="(int) ($summary->total ?? 0)" icon="mdi-comment-question-outline" color="primary" /></div>
        <div class="col-6 col-md-3"><x-stat-card label="ผู้ใช้ที่ถาม" :value="(int) ($summary->users ?? 0)" icon="mdi-account-multiple" color="info" /></div>
        <div class="col-6 col-md-3"><x-stat-card label="Token ที่ใช้" :value="(int) ($summary->tokens ?? 0)" icon="mdi-counter" color="warning" /></div>
        <div class="col-6 col-md-3"><x-stat-card label="ตอบไม่สำเร็จ" :value="(int) ($summary->errors ?? 0)" icon="mdi-alert-circle" color="{{ ($summary->errors ?? 0) > 0 ? 'danger' : 'secondary' }}" :href="route('ai.logs', ['status' => 'error'])" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-9">
            <div class="card">
                <form method="GET" action="{{ route('ai.logs') }}" class="card-body pb-0">
                    @if ($f['conversation'])
                        <input type="hidden" name="conversation" value="{{ $f['conversation'] }}">
                        <div class="alert alert-info py-2 d-flex justify-content-between align-items-center mb-3">
                            <span><i class="mdi mdi-forum"></i> กำลังดูบทสนทนาเดียว (เรียงจากเก่าไปใหม่) รหัส <code>{{ $f['conversation'] }}</code></span>
                            <a href="{{ route('ai.logs') }}" class="btn btn-sm btn-soft">ดูทั้งหมด</a>
                        </div>
                    @endif
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0"><i class="mdi mdi-magnify"></i></span>
                                <input type="search" name="search" class="form-control border-start-0" placeholder="คำถาม, คำตอบ, ชื่อผู้ใช้" value="{{ $f['search'] }}">
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <select name="user_id" class="form-select">
                                <option value="">ทุกผู้ใช้</option>
                                @foreach ($users as $u)
                                    <option value="{{ $u->id }}" {{ $f['userId'] === (int) $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <select name="tool" class="form-select">
                                <option value="">ทุกเครื่องมือ</option>
                                @foreach ($tools as $t)
                                    <option value="{{ $t }}" {{ $f['tool'] === $t ? 'selected' : '' }}>{{ $t }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <select name="status" class="form-select">
                                <option value="">ทุกสถานะ</option>
                                <option value="ok" {{ $f['status'] === 'ok' ? 'selected' : '' }}>ตอบสำเร็จ</option>
                                <option value="error" {{ $f['status'] === 'error' ? 'selected' : '' }}>ผิดพลาด</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2"><input type="date" name="from" class="form-control" value="{{ $f['from'] }}" title="ตั้งแต่วันที่"></div>
                        <div class="col-6 col-md-2"><input type="date" name="to" class="form-control" value="{{ $f['to'] }}" title="ถึงวันที่"></div>
                        <div class="col-12 col-md-auto d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="mdi mdi-magnify"></i> ค้นหา</button>
                            <a href="{{ route('ai.logs') }}" class="btn btn-soft" title="ล้าง"><i class="mdi mdi-refresh"></i></a>
                        </div>
                    </div>
                </form>

                @if ($logs->count() === 0)
                    <x-empty-state icon="mdi-comment-multiple-outline" title="ยังไม่มีประวัติการถาม AI" />
                @else
                    <div class="table-wrap mt-3">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th style="width:130px">เวลา</th>
                                    <th style="width:140px">ผู้ใช้</th>
                                    <th>คำถาม</th>
                                    <th style="width:170px">เครื่องมือ</th>
                                    <th style="width:110px" class="text-end">Token / เวลา</th>
                                    <th class="td-actions"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($logs as $log)
                                    <tr>
                                        <td class="text-2" style="white-space:nowrap">
                                            {{ fmt_datetime($log->created_at) }}
                                            @if ($log->conversation_id && ! $f['conversation'])
                                                <div><a href="{{ route('ai.logs', ['conversation' => $log->conversation_id]) }}" class="sub" title="ดูบทสนทนานี้ทั้งหมด"><i class="mdi mdi-forum"></i> บทสนทนา</a></div>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="fw-name">{{ optional($log->user)->name ?? '-' }}</div>
                                            <div class="sub text-mono">{{ $log->ip }}</div>
                                        </td>
                                        <td>
                                            <div class="ai-q clamp">{{ $log->question }}</div>
                                            @if ($log->status === 'error')
                                                <span class="badge-soft badge-soft-danger mt-1"><i class="mdi mdi-alert"></i> ผิดพลาด</span>
                                            @elseif ($log->reports)
                                                <span class="badge-soft badge-soft-success mt-1"><i class="mdi mdi-file-excel"></i> สร้าง Excel {{ count($log->reports) }} ไฟล์</span>
                                            @endif
                                        </td>
                                        <td class="ai-tools">
                                            @forelse ($log->tools_used ?? [] as $t)
                                                <span class="badge-soft badge-soft-secondary">{{ $t }}</span>
                                            @empty
                                                <span class="text-3">-</span>
                                            @endforelse
                                        </td>
                                        <td class="text-end text-2" style="white-space:nowrap">
                                            {{ number_format($log->totalTokens()) }}
                                            <div class="sub">{{ number_format($log->duration_ms / 1000, 1) }} วิ</div>
                                        </td>
                                        <td class="td-actions">
                                            <button type="button" class="btn btn-soft btn-sm" data-ai-view
                                                data-user="{{ optional($log->user)->name ?? '-' }}" data-time="{{ fmt_datetime($log->created_at) }}"
                                                data-question="{{ $log->question }}" data-answer="{{ $log->answer }}" data-error="{{ $log->error }}"
                                                data-tools="{{ implode(', ', $log->tools_used ?? []) }}" data-tokens="{{ $log->prompt_tokens }} + {{ $log->completion_tokens }}">
                                                <i class="mdi mdi-eye"></i> ดู
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">{{ $logs->links() }}</div>
                @endif
            </div>
        </div>

        <div class="col-xl-3">
            <div class="card">
                <div class="card-body ai-top-users">
                    <h6 class="mb-2"><i class="mdi mdi-trophy"></i> ถามมากที่สุด 30 วันล่าสุด</h6>
                    @forelse ($topUsers as $row)
                        <div class="row-item">
                            <div>
                                <a href="{{ route('ai.logs', ['user_id' => $row->user_id]) }}">{{ optional($row->user)->name ?? ('#' . $row->user_id) }}</a>
                                <div class="sub">ล่าสุด {{ fmt_datetime($row->last_at) }}</div>
                            </div>
                            <strong>{{ number_format($row->total) }}</strong>
                        </div>
                    @empty
                        <div class="text-3">ยังไม่มีข้อมูล</div>
                    @endforelse
                </div>
            </div>
            <div class="card mt-3">
                <div class="card-body text-2" style="font-size:.85rem">
                    <i class="mdi mdi-information-outline"></i> ระบบบันทึกทุกคำถามที่ส่งไปยัง AI พร้อมคำตอบ (ข้อมูลแรงงานที่ AI ดึงไปดูไม่ได้ถูกเก็บ เก็บเฉพาะชื่อเครื่องมือ)
                    ลิงก์ดาวน์โหลด Excel ในคำตอบหมดอายุหลัง 24 ชั่วโมง
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="aiLogModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="aiLogTitle">รายละเอียด</h5>
                        <div class="sub" id="aiLogMeta"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body" id="aiLogBody"></div>
                <div class="modal-footer"><button type="button" class="btn btn-soft" data-bs-dismiss="modal">ปิด</button></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        var modal = new bootstrap.Modal(document.getElementById('aiLogModal'));
        var body = document.getElementById('aiLogBody'), title = document.getElementById('aiLogTitle'), meta = document.getElementById('aiLogMeta');

        function esc(s) { return String(s || '').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
        function md(s) {
            if (!window.marked || !window.DOMPurify) return esc(s).replace(/\n/g, '<br>');
            return DOMPurify.sanitize(marked.parse(s || '', { breaks: true, gfm: true }), { ADD_ATTR: ['target'] });
        }

        document.querySelectorAll('[data-ai-view]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var d = btn.dataset;
                title.textContent = d.user + ' · ' + d.time;
                meta.textContent = (d.tools ? 'เครื่องมือ: ' + d.tools + ' · ' : '') + 'token: ' + d.tokens;
                var html = '<div class="ai-bubble user"><div class="who"><i class="mdi mdi-account"></i> ' + esc(d.user) + '</div><div class="ai-q">' + esc(d.question) + '</div></div>';
                if (d.error) {
                    html += '<div class="ai-bubble error"><div class="who"><i class="mdi mdi-alert"></i> ผิดพลาด</div><div class="ai-q">' + esc(d.error) + '</div></div>';
                } else {
                    html += '<div class="ai-bubble assistant"><div class="who"><i class="mdi mdi-robot"></i> ผู้ช่วย AI</div><div class="md">' + md(d.answer) + '</div></div>';
                }
                body.innerHTML = html;
                body.querySelectorAll('a').forEach(function (a) { a.target = '_blank'; a.rel = 'noopener'; });
                modal.show();
            });
        });
    })();
</script>
@endpush
