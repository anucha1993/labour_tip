@extends('layouts.main_layout')

@section('title', 'ลิงก์แชร์โฟลเดอร์')
@section('crumb', 'คลังเอกสาร / ลิงก์แชร์')

@section('content')
    <x-page-header title="ลิงก์แชร์โฟลเดอร์" subtitle="ลิงก์ที่สร้างไว้ให้คนนอกเปิดดูเอกสาร ยกเลิกได้ทุกเมื่อ ลิงก์ที่ยกเลิกหรือหมดอายุจะเปิดไม่ได้ทันที">
        <a href="{{ route('documents.index') }}" class="btn btn-soft"><i class="mdi mdi-folder"></i> คลังเอกสาร</a>
    </x-page-header>

    @if ($shares->isEmpty())
        <x-empty-state icon="mdi-link-variant" title="ยังไม่มีลิงก์แชร์" text="เปิดโฟลเดอร์บริษัทหรือแรงงานในคลังเอกสาร แล้วกดปุ่ม แชร์โฟลเดอร์" />
    @else
        <div class="card"><div class="table-wrap"><table class="table table-modern">
            <thead><tr>
                <th>โฟลเดอร์</th>
                <th class="d-none d-md-table-cell">ลิงก์</th>
                <th>สถานะ</th>
                <th class="d-none d-lg-table-cell">เปิดดู</th>
                <th class="d-none d-lg-table-cell">สร้างโดย</th>
                <th class="td-actions"></th>
            </tr></thead>
            <tbody>
            @foreach ($shares as $s)
                @php $active = $s->isActive(); @endphp
                <tr class="{{ $active ? '' : 'text-3' }}">
                    <td>
                        <div class="fw-name"><i class="mdi {{ $s->kind === 'company' ? 'mdi-domain' : 'mdi-account' }}"></i> {{ $s->title() }}</div>
                        @if ($s->emails())<div class="small text-primary"><i class="mdi {{ $s->isRestricted() ? 'mdi-lock' : 'mdi-email' }}"></i> {{ implode(', ', $s->emails()) }}{{ $s->isRestricted() ? ' (เฉพาะอีเมลเหล่านี้)' : '' }}</div>@endif
                        <div class="sub">{{ $s->kind === 'company' ? 'ทั้งบริษัท' : 'แรงงาน 1 คน' }}@if ($s->label) · {{ $s->label }}@endif · {{ $s->can_download ? 'ดาวน์โหลดได้' : 'ดูอย่างเดียว' }}@if ($s->hasPassword()) · <i class="mdi mdi-lock"></i> มีรหัสผ่าน@endif</div>
                    </td>
                    <td class="d-none d-md-table-cell">
                        @if ($active)
                            <div class="input-group input-group-sm" style="max-width:340px">
                                <input type="text" class="form-control text-mono" value="{{ $s->url() }}" readonly onclick="this.select()">
                                <button type="button" class="btn btn-soft" data-copy="{{ $s->url() }}" title="คัดลอก"><i class="mdi mdi-content-copy"></i></button>
                            </div>
                        @else
                            <span class="text-mono small">…{{ substr($s->token, -8) }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($active)<span class="badge-soft badge-soft-success">ใช้งานได้</span>@else<span class="badge-soft badge-soft-secondary">{{ $s->revoked_at ? 'ยกเลิกแล้ว' : 'หมดอายุ' }}</span>@endif
                        <div class="sub">{{ $s->expiryLabel() }}</div>
                    </td>
                    <td class="d-none d-lg-table-cell">{{ number_format($s->views) }} ครั้ง<div class="sub">{{ $s->last_viewed_at ? fmt_datetime($s->last_viewed_at) : 'ยังไม่มีคนเปิด' }}</div></td>
                    <td class="d-none d-lg-table-cell">{{ $s->created_by ?: '-' }}<div class="sub">{{ fmt_datetime($s->created_at) }}</div></td>
                    <td class="td-actions">
                        <a href="{{ route('documents.index', $s->kind === 'company' ? ['company' => $s->target_id] : ['labour' => $s->target_id]) }}" class="btn btn-soft btn-sm" title="เปิดโฟลเดอร์"><i class="mdi mdi-folder"></i></a>
                        @if ($active && $s->emails() && \App\Models\DocumentShare::mailReady())
                            <form method="POST" action="{{ route('documents.share.notify', $s->id) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-soft btn-sm" title="ส่งลิงก์ไปยังอีเมลผู้รับอีกครั้ง"><i class="mdi mdi-email"></i> ส่งลิงก์</button>
                            </form>
                        @endif
                        @if ($active)
                            <form method="POST" action="{{ route('documents.share.revoke', $s->id) }}" data-confirm="ยกเลิกลิงก์แชร์นี้? คนที่มีลิงก์จะเปิดไม่ได้อีก" data-confirm-title="ยกเลิกลิงก์แชร์" data-confirm-ok="ยกเลิกลิงก์">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-soft-danger btn-sm" title="ยกเลิกลิงก์"><i class="mdi mdi-link-variant-off"></i> ยกเลิก</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div></div>
        <div class="mt-3">{{ $shares->links() }}</div>
    @endif
@endsection

@push('scripts')
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]'); if (!b) return;
    navigator.clipboard.writeText(b.dataset.copy).then(function () { b.innerHTML = '<i class="mdi mdi-check"></i>'; setTimeout(function () { b.innerHTML = '<i class="mdi mdi-content-copy"></i>'; }, 1500); });
});
</script>
@endpush
