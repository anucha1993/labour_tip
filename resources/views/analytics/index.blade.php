@extends('layouts.main_layout')

@section('title', 'Dashboard')
@section('crumb', 'วิเคราะห์ข้อมูลแรงงาน' . ($companyName ? ' · ' . $companyName : ''))

@section('content')
    @php
        $s = $data['summary'];
        $ov = $data['overdue'];
        $tl = $data['timeline'];
        $cp = $data['completeness'];
        $tr = $data['trend'];
        $dm = $data['demographics'];
        $colors = ['visa' => '#2563eb', 'passport' => '#7c3aed', 'work' => '#ea580c', 'ninety' => '#16a34a'];
        $listUrl = fn (array $q = []) => route('labour.index', array_filter(['company_id' => $company ?: null] + $q, fn ($v) => $v !== null && $v !== ''));
        $today = \Carbon\Carbon::today();
    @endphp

    <x-page-header title="Dashboard" subtitle="ปริมาณงานล่วงหน้า เอกสารเลยกำหนด เปรียบเทียบบริษัท และแนวโน้มกำลังคน · ข้อมูลคำนวณล่าสุด {{ fmt_datetime($data['generated_at']) }} (อัปเดตทุก {{ $cacheMinutes }} นาที)">
        <form method="GET" action="{{ route('analytics.index') }}" class="d-flex flex-wrap gap-2 align-items-center" id="dashFilter">
            <select name="company" class="form-select form-select-sm js-select2" style="min-width:240px" data-autosubmit aria-label="บริษัท">
                <option value="0">ทุกบริษัท</option>
                @foreach ($companies as $c)
                    <option value="{{ $c->company_id }}" {{ $company === (int) $c->company_id ? 'selected' : '' }}>{{ $c->company_name }}</option>
                @endforeach
            </select>
            <select name="months" class="form-select form-select-sm" data-autosubmit aria-label="ช่วงเวลา">
                @foreach ($monthOptions as $m)
                    <option value="{{ $m }}" {{ $months === $m ? 'selected' : '' }}>{{ $m }} เดือน</option>
                @endforeach
            </select>
        </form>
    </x-page-header>

    {{-- ------------------------------------------------------------ headline --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><x-stat-card label="กำลังทำงาน" :value="$s['job']" icon="mdi-briefcase" color="success" :href="$listUrl(['labour_status_job' => 'job'])" /></div>
        <div class="col-6 col-md-3"><x-stat-card label="เอกสารเลยกำหนด (ยังทำงานอยู่)" :value="$ov['total']" icon="mdi-alert-octagon" :color="$ov['total'] > 0 ? 'danger' : 'success'" href="#overdue" /></div>
        <div class="col-6 col-md-3"><x-stat-card label="งานต่ออายุเดือนนี้" :value="$tl['totals'][0] ?? 0" icon="mdi-calendar-check" color="primary" href="#timeline" /></div>
        <div class="col-6 col-md-3"><x-stat-card label="เข้าใหม่ / ออก 30 วัน" :value="'+' . number_format($s['joined_30']) . ' / −' . number_format($s['resigned_30'] + $s['escaped_30'])" icon="mdi-swap-vertical" color="info" href="#trend" /></div>
    </div>

    <div class="row g-3">
        {{-- ------------------------------------------------------------ 1. renewal timeline --}}
        <div class="col-12" id="timeline">
            <div class="card h-100">
                <div class="card-header flex-wrap">
                    <h5 class="card-title"><i class="mdi mdi-chart-bar text-primary"></i> ปริมาณงานต่ออายุล่วงหน้า {{ $months }} เดือน</h5>
                    <span class="text-2 small ms-auto">รวม {{ number_format(array_sum($tl['totals'])) }} รายการ · เดือนที่หนักสุด {{ number_format($tl['peak']) }} รายการ · กดที่แท่งเพื่อดูรายชื่อ</span>
                </div>
                <div class="card-body">
                    @if (array_sum($tl['totals']) === 0)
                        <x-empty-state icon="mdi-calendar-check" title="ไม่มีเอกสารครบกำหนดในช่วงนี้" />
                    @else
                        <div style="height:300px"><canvas id="chTimeline"></canvas></div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ 2. overdue --}}
        <div class="col-12 col-xl-5" id="overdue">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title"><i class="mdi mdi-alert-octagon text-danger"></i> เอกสารหมดอายุแล้วแต่ยังทำงานอยู่</h5></div>
                <div class="card-body">
                    <div class="row g-2">
                        @foreach ($types as $type => $column)
                            @php $o = $ov[$type]; @endphp
                            <div class="col-6">
                                <a href="{{ $listUrl(['labour_status_job' => 'job', 'column_name_type' => $column, 'start_date' => '2000-01-01', 'end_date' => $today->copy()->subDay()->toDateString()]) }}" class="card text-reset h-100 p-3 {{ $o['count'] > 0 ? 'border-danger' : '' }}" style="border-left-width:4px">
                                    <div class="sub">{{ $labels[$type] }}</div>
                                    <div class="fs-3 fw-bold {{ $o['count'] > 0 ? 'text-danger' : 'text-success' }}">{{ number_format($o['count']) }}</div>
                                    <div class="sub">{{ $o['last_30'] > 0 ? 'เลยกำหนดใน 30 วันล่าสุด ' . number_format($o['last_30']) . ' คน' : ($o['count'] > 0 ? 'ค้างเกิน 30 วันทั้งหมด' : 'ไม่มีรายการค้าง') }}</div>
                                </a>
                            </div>
                        @endforeach
                    </div>
                    <div class="text-2 small mt-2"><i class="mdi mdi-information-outline"></i> นับเฉพาะคนที่สถานะ "ทำงาน" และวันหมดอายุผ่านไปแล้ว ถ้าคนไหนลาออกจริงให้แก้สถานะ ตัวเลขจะลดเอง</div>
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ 4. completeness --}}
        <div class="col-12 col-xl-7" id="completeness">
            <div class="card h-100">
                <div class="card-header flex-wrap"><h5 class="card-title"><i class="mdi mdi-clipboard-check text-info"></i> ความครบถ้วนของข้อมูล (คนที่ทำงานอยู่ {{ number_format($cp['total']) }} คน)</h5><span class="text-2 small ms-auto">เรียงจากที่ขาดมากสุด</span></div>
                <div class="card-body">
                    @if (! $cp['items'])
                        <x-empty-state icon="mdi-clipboard-text" title="ยังไม่มีแรงงานที่ทำงานอยู่" />
                    @else
                        @foreach ($cp['items'] as $k => $it)
                            @php $cls = $it['pct'] >= 90 ? 'bg-success' : ($it['pct'] >= 60 ? 'bg-warning' : 'bg-danger'); @endphp
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <div style="width:200px" class="small text-truncate" title="{{ $it['label'] }}">{{ $it['label'] }}</div>
                                <div class="progress flex-grow-1" style="height:10px" role="progressbar" aria-valuenow="{{ $it['pct'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $it['label'] }}"><div class="progress-bar {{ $cls }}" style="width:{{ $it['pct'] }}%"></div></div>
                                <div style="width:130px" class="small text-end"><b>{{ $it['pct'] }}%</b> <span class="text-3">ขาด {{ number_format($cp['total'] - $it['have']) }}</span></div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ 3. companies --}}
        @if ($data['companies'])
            <div class="col-12" id="companies">
                <div class="card">
                    <div class="card-header flex-wrap"><h5 class="card-title"><i class="mdi mdi-domain text-warning"></i> บริษัทที่ต้องตามงาน</h5><span class="text-2 small ms-auto">เรียงจากเอกสารเลยกำหนดมากสุด แสดง {{ count($data['companies']) }} บริษัทแรก · ช่องสีเข้ม = ตัวเลขสูง</span></div>
                    <div class="table-wrap"><table class="table table-modern mb-0 dash-heat">
                        <thead><tr><th>บริษัท</th><th class="text-end">ทำงานอยู่</th><th class="text-end">ใกล้หมดอายุ</th><th class="text-end">เลยกำหนด</th><th class="text-end">หลบหนีสะสม</th><th class="text-end d-none d-md-table-cell">ไม่มีรูป</th><th class="text-end d-none d-md-table-cell">ไฟล์/คน</th><th></th></tr></thead>
                        <tbody>
                        @php $mx = fn ($k) => max(1, max(array_column($data['companies'], $k))); @endphp
                        @foreach ($data['companies'] as $c)
                            <tr>
                                <td class="fw-name"><a href="{{ route('analytics.index', ['company' => $c['id'], 'months' => $months]) }}" class="text-reset">{{ $c['name'] }}</a></td>
                                <td class="text-end">{{ number_format($c['job']) }}</td>
                                <td class="text-end heat-warn" style="--h:{{ round($c['soon'] / $mx('soon'), 2) }}">{{ number_format($c['soon']) }}</td>
                                <td class="text-end heat-danger" style="--h:{{ round($c['overdue'] / $mx('overdue'), 2) }}">{{ number_format($c['overdue']) }}</td>
                                <td class="text-end heat-danger" style="--h:{{ round($c['escape_rate'] / max(1, max(array_column($data['companies'], 'escape_rate'))), 2) }}">{{ number_format($c['escape']) }} <span class="text-3 small">({{ $c['escape_rate'] }}%)</span></td>
                                <td class="text-end d-none d-md-table-cell">{{ number_format($c['no_photo']) }}</td>
                                <td class="text-end d-none d-md-table-cell">{{ $c['files_per_worker'] }}</td>
                                <td class="td-actions"><a href="{{ route('labour.index', ['company_id' => $c['id'], 'labour_status_job' => 'job']) }}" class="btn btn-soft btn-sm">รายชื่อ</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </div>
            </div>
        @endif

        {{-- ------------------------------------------------------------ 5. workforce trend --}}
        <div class="col-12 col-xl-7" id="trend">
            <div class="card h-100">
                <div class="card-header flex-wrap"><h5 class="card-title"><i class="mdi mdi-chart-line text-success"></i> แนวโน้มกำลังคน {{ $months }} เดือนย้อนหลัง</h5><span class="text-2 small ms-auto">เข้าใหม่จากวันเริ่มงาน · ออกจากวันลาออก/หลบหนี · เส้นประ = จำนวนคนทำงาน ณ สิ้นเดือน</span></div>
                <div class="card-body"><div style="height:300px"><canvas id="chTrend"></canvas></div></div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ 6. demographics --}}
        <div class="col-12 col-xl-5" id="demographics">
            <div class="card h-100">
                <div class="card-header flex-wrap"><h5 class="card-title"><i class="mdi mdi-account-group text-primary"></i> สัดส่วนคนที่ทำงานอยู่</h5>@if ($dm['avg_age'])<span class="text-2 small ms-auto">อายุเฉลี่ย {{ $dm['avg_age'] }} ปี</span>@endif</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12"><div class="sub mb-1">สัญชาติ</div><div style="height:{{ 30 + 28 * max(1, count($dm['nationality'])) }}px"><canvas id="chNat"></canvas></div></div>
                        <div class="col-5"><div class="sub mb-1">เพศ</div><div style="height:170px"><canvas id="chSex"></canvas></div></div>
                        <div class="col-7"><div class="sub mb-1">ช่วงอายุ</div><div style="height:170px"><canvas id="chAge"></canvas></div></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ 7. agencies --}}
        <div class="col-12 col-xl-6" id="agencies">
            <div class="card h-100">
                <div class="card-header flex-wrap"><h5 class="card-title"><i class="mdi mdi-earth text-danger"></i> อัตราหลบหนีต่อเอเจนซี่</h5><span class="text-2 small ms-auto">หลบหนี ÷ ทุกคนที่เอเจนซี่เคยนำเข้า (ตลอดเวลา)</span></div>
                <div class="card-body">
                    @if (! $data['agencies'])
                        <x-empty-state icon="mdi-earth" title="ไม่มีข้อมูลเอเจนซี่" />
                    @else
                        <div style="height:{{ 40 + 30 * count($data['agencies']) }}px"><canvas id="chAgency"></canvas></div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ 8. tenure --}}
        @if ($data['tenure'])
            <div class="col-12 col-xl-6" id="tenure">
                <div class="card h-100">
                    <div class="card-header flex-wrap"><h5 class="card-title"><i class="mdi mdi-timer-sand text-info"></i> อายุงานเฉลี่ยรายบริษัท (เดือน)</h5><span class="text-2 small ms-auto">เฉลี่ยทั้งระบบ {{ $data['tenure']['overall'] ?? '-' }} เดือน · เฉพาะบริษัทที่มี 5 คนขึ้นไป และวันเริ่มงานตั้งแต่ปี 2543</span></div>
                    <div class="card-body">
                        @if (! $data['tenure']['rows'])
                            <x-empty-state icon="mdi-timer-sand" title="ยังไม่มีวันเริ่มงานพอคำนวณ" />
                        @else
                            <div style="height:{{ 40 + 30 * count($data['tenure']['rows']) }}px"><canvas id="chTenure"></canvas></div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- ------------------------------------------------------------ 9. system --}}
        @if (! empty($data['system']))
            @php $sy = $data['system']; @endphp
            <div class="col-12" id="system">
                <div class="card">
                    <div class="card-header flex-wrap"><h5 class="card-title"><i class="mdi mdi-monitor-dashboard text-secondary"></i> การใช้งานระบบ 30 วัน</h5><span class="text-2 small ms-auto">แก้ไขข้อมูล {{ number_format($sy['edits_total']) }} ครั้ง · ถาม AI {{ number_format($sy['ai_total']) }} ครั้ง ({{ number_format($sy['ai_tokens']) }} token)</span></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-lg-8"><div style="height:220px"><canvas id="chSystem"></canvas></div></div>
                            <div class="col-12 col-lg-4">
                                <div class="sub mb-1">แก้ไขมากที่สุด</div>
                                @forelse ($sy['top_editors'] as $e)
                                    <div class="d-flex justify-content-between small py-1 border-bottom"><span>{{ $e['name'] }}</span><b>{{ number_format($e['count']) }}</b></div>
                                @empty
                                    <div class="text-3 small">ยังไม่มีการแก้ไข</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <style>
        .dash-heat td.heat-warn { background: rgba(245, 158, 11, calc(var(--h, 0) * .45)); }
        .dash-heat td.heat-danger { background: rgba(220, 38, 38, calc(var(--h, 0) * .45)); }
        [data-bs-theme="dark"] .dash-heat td.heat-warn { background: rgba(245, 158, 11, calc(var(--h, 0) * .55)); }
        [data-bs-theme="dark"] .dash-heat td.heat-danger { background: rgba(220, 38, 38, calc(var(--h, 0) * .55)); }
    </style>
@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var D = @json($data);
    var LABELS = @json($labels), TYPES = @json($types), COLORS = @json($colors), MONTHS = {{ (int) $months }};
    var LIST = @json(route('labour.index')), COMPANY = {{ (int) $company }};
    var css = getComputedStyle(document.documentElement);
    function v(name, fb) { var x = css.getPropertyValue(name).trim(); return x || fb; }
    var textColor = v('--text-2', '#64748b'), gridColor = v('--border', '#e2e8f0');
    Chart.defaults.color = textColor; Chart.defaults.borderColor = gridColor;
    Chart.defaults.font.family = v('--font-sans', 'sans-serif'); Chart.defaults.font.size = 12;
    Chart.defaults.plugins.legend.labels.boxWidth = 12; Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
    function listUrl(q) { var p = new URLSearchParams(q); if (COMPANY) p.set('company_id', COMPANY); return LIST + '?' + p.toString(); }
    function monthRange(key) { var y = +key.slice(0, 4), m = +key.slice(5, 7); var last = new Date(y, m, 0).getDate(); return [key + '-01', key + '-' + String(last).padStart(2, '0')]; }
    function nf(n) { return Number(n).toLocaleString('th-TH'); }

    // 1. renewal timeline: stacked bars, click = list filtered to that month + document
    var elT = document.getElementById('chTimeline');
    if (elT) {
        var tl = D.timeline;
        new Chart(elT, {
            type: 'bar',
            data: { labels: tl.labels, datasets: Object.keys(TYPES).map(function (t) { return { label: LABELS[t], data: tl.series[t], backgroundColor: COLORS[t], borderRadius: 3, stack: 'a', _type: t }; }) },
            options: { maintainAspectRatio: false, responsive: true,
                scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
                plugins: { tooltip: { callbacks: { footer: function (items) { var i = items[0].dataIndex; return 'รวม ' + nf(tl.totals[i]) + ' รายการ'; } } } },
                onClick: function (e, els) { if (!els.length) return; var ds = this.data.datasets[els[0].datasetIndex], r = monthRange(tl.keys[els[0].index]);
                    location.href = listUrl({ labour_status_job: 'job', column_name_type: TYPES[ds._type], start_date: r[0], end_date: r[1] }); },
                onHover: function (e, els) { e.native.target.style.cursor = els.length ? 'pointer' : 'default'; } }
        });
    }

    // 5. workforce trend: lines + dashed head-count on a second axis
    var elTr = document.getElementById('chTrend');
    if (elTr) {
        var tr = D.trend;
        new Chart(elTr, {
            type: 'line',
            data: { labels: tr.labels, datasets: [
                { label: 'เข้าใหม่', data: tr.series.joined, borderColor: '#16a34a', backgroundColor: '#16a34a', tension: .3, pointRadius: 3 },
                { label: 'ลาออก', data: tr.series.resigned, borderColor: '#64748b', backgroundColor: '#64748b', tension: .3, pointRadius: 3 },
                { label: 'หลบหนี', data: tr.series.escaped, borderColor: '#dc2626', backgroundColor: '#dc2626', tension: .3, pointRadius: 3 },
                { label: 'คนทำงาน (สิ้นเดือน)', data: tr.series.working, borderColor: '#2563eb', backgroundColor: '#2563eb', borderDash: [5, 4], tension: .3, pointRadius: 2, yAxisID: 'y2' }
            ] },
            options: { maintainAspectRatio: false, responsive: true, interaction: { mode: 'index', intersect: false },
                scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'คน/เดือน' } },
                          y2: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { precision: 0 }, title: { display: true, text: 'คนทำงาน' } } } }
        });
    }

    // 6. demographics
    function hbar(id, rows, color, opts) {
        var el = document.getElementById(id); if (!el) return;
        opts = opts || {};
        new Chart(el, { type: 'bar', data: { labels: rows.map(function (r) { return r.label; }), datasets: [{ data: rows.map(function (r) { return r.count !== undefined ? r.count : r.value; }), backgroundColor: color, borderRadius: 3, barThickness: 16 }] },
            options: { indexAxis: 'y', maintainAspectRatio: false, responsive: true, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return (opts.suffix ? nf(c.raw) + opts.suffix : nf(c.raw) + ' คน') + (opts.extra ? opts.extra(rows[c.dataIndex]) : ''); } } } },
                scales: { x: { beginAtZero: true, ticks: { precision: 0, callback: function (val) { return opts.suffix ? val + opts.suffix : val; } } }, y: { grid: { display: false }, ticks: { autoSkip: false, callback: function (val) { var l = this.getLabelForValue(val); return l.length > 34 ? l.slice(0, 32) + '…' : l; } } } },
                onClick: opts.onClick, onHover: opts.onClick ? function (e, els) { e.native.target.style.cursor = els.length ? 'pointer' : 'default'; } : undefined } });
    }
    hbar('chNat', D.demographics.nationality, '#2563eb', { onClick: function (e, els) { if (!els.length) return; location.href = listUrl({ labour_status_job: 'job', keyword: D.demographics.nationality[els[0].index].code }); } });
    hbar('chAge', D.demographics.age, '#0ea5e9');
    var elS = document.getElementById('chSex');
    if (elS) new Chart(elS, { type: 'doughnut', data: { labels: D.demographics.sex.map(function (r) { return r.label; }), datasets: [{ data: D.demographics.sex.map(function (r) { return r.count; }), backgroundColor: ['#2563eb', '#ec4899'], borderWidth: 0 }] },
        options: { maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (c) { var t = c.dataset.data.reduce(function (a, b) { return a + b; }, 0); return ' ' + nf(c.raw) + ' คน (' + (t ? Math.round(c.raw * 100 / t) : 0) + '%)'; } } } } } });

    // 7. agency escape rate (%), with the base count in the tooltip
    hbar('chAgency', D.agencies.map(function (a) { return { label: a.name + ' (' + nf(a.total) + ' คน)', value: a.rate, raw: a }; }), function (ctx) { var r = ctx.raw; return r >= 15 ? '#dc2626' : r >= 7 ? '#f59e0b' : '#16a34a'; },
        { suffix: '%', extra: function (row) { return ' · หลบหนี ' + nf(row.raw.escape) + ' จาก ' + nf(row.raw.total) + ' คน'; } });

    // 8. tenure (months)
    if (D.tenure && D.tenure.rows) hbar('chTenure', D.tenure.rows.map(function (r) { return { label: r.name + ' (' + nf(r.n) + ' คน)', value: r.months }; }), '#0891b2', { suffix: ' เดือน' });

    // 9. system use
    var elSy = document.getElementById('chSystem');
    if (elSy && D.system) new Chart(elSy, { type: 'bar', data: { labels: D.system.labels, datasets: [
            { label: 'แก้ไขข้อมูล', data: D.system.edits, backgroundColor: '#64748b', borderRadius: 2 },
            { label: 'ถาม AI', data: D.system.ai, backgroundColor: '#7c3aed', borderRadius: 2 } ] },
        options: { maintainAspectRatio: false, responsive: true, scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }, y: { beginAtZero: true, ticks: { precision: 0 } } } } });

})();
</script>
@endpush
