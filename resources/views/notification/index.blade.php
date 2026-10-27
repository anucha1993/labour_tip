@extends('layouts.main_layout')

@section('title', 'หน้าแรก')
@section('crumb', 'ภาพรวมระบบและเอกสารใกล้หมดอายุ')

@section('content')
    <x-page-header title="สวัสดี, {{ auth()->user()->name }}" subtitle="ภาพรวมข้อมูลแรงงานและเอกสารที่ต้องดำเนินการ ณ วันที่ {{ fmt_date(now()) }}">
        @if (user_is('Admin', 'MasterAdmin'))
            <a href="{{ route('labour.create') }}" class="btn btn-primary"><i class="mdi mdi-plus"></i> เพิ่มแรงงาน</a>
        @endif
        <a href="{{ route('report.index') }}" class="btn btn-soft"><i class="mdi mdi-file-chart"></i> ออกรายงาน</a>
    </x-page-header>

    {{-- ---------------------------------------------------------- stats --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl-2"><x-stat-card label="แรงงานทั้งหมด" :value="$stats['total']" icon="mdi-account-multiple" color="primary" :href="route('labour.index')" /></div>
        <div class="col-6 col-md-4 col-xl-2"><x-stat-card label="กำลังทำงาน" :value="$stats['job']" icon="mdi-briefcase" color="success" :href="route('labour.index', ['labour_status_job' => 'job'])" /></div>
        <div class="col-6 col-md-4 col-xl-2"><x-stat-card label="ลาออก" :value="$stats['resign']" icon="mdi-exit-to-app" color="secondary" :href="route('labour.index', ['labour_status_job' => 'resign'])" /></div>
        <div class="col-6 col-md-4 col-xl-2"><x-stat-card label="หลบหนี" :value="$stats['escape']" icon="mdi-run" color="danger" :href="route('labour.index', ['labour_status_job' => 'escape'])" /></div>
        <div class="col-6 col-md-4 col-xl-2"><x-stat-card label="บริษัท / นายจ้าง" :value="$stats['company']" icon="mdi-domain" color="info" :href="route('company.index')" /></div>
        <div class="col-6 col-md-4 col-xl-2"><x-stat-card label="เอเจนซี่" :value="$stats['agency']" icon="mdi-earth" color="warning" :href="route('agency.index')" /></div>
    </div>

    {{-- ---------------------------------------------------------- expiring documents --}}
    @php
        $tabMeta = [
            'visa' => ['icon' => 'mdi-airplane', 'number' => 'labour_visa_number', 'numberLabel' => 'เลขที่วีซ่า'],
            'passport' => ['icon' => 'mdi-book-open-page-variant', 'number' => 'labour_passport_number', 'numberLabel' => 'เลขที่หนังสือเดินทาง'],
            'work' => ['icon' => 'mdi-briefcase', 'number' => 'labour_workpremit_number', 'numberLabel' => 'เลขที่ใบอนุญาตทำงาน'],
            'ninety' => ['icon' => 'mdi-calendar-clock', 'number' => 'labour_passport_number', 'numberLabel' => 'เลขที่หนังสือเดินทาง'],
        ];
        $first = array_key_first($lists);
    @endphp
    <div class="card">
        <div class="card-header flex-wrap">
            <i class="mdi mdi-bell-ring text-danger fs-5"></i>
            <h5 class="card-title">แจ้งเตือนเอกสารใกล้หมดอายุ</h5>
            <span class="badge-soft badge-soft-danger">{{ number_format($counts['total']) }} รายการ</span>
            <span class="ms-auto small text-3">นับเฉพาะแรงงานที่สถานะ "ทำงาน" · ตั้งค่าจำนวนวันล่วงหน้าได้ที่
                @if (user_is('Admin', 'MasterAdmin'))<a href="{{ route('setting.index') }}">ตั้งค่าวันแจ้งเตือน</a>@else เมนูตั้งค่า @endif
            </span>
        </div>

        <div class="px-3 pt-2">
            <ul class="nav nav-tabs nav-tabs-modern" role="tablist">
                @foreach ($lists as $type => $list)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link {{ $type === $first ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#{{ $type }}" data-tab-memory type="button" role="tab">
                            <i class="mdi {{ $tabMeta[$type]['icon'] }}"></i>{{ $labels[$type] }}
                            <span class="count {{ $counts[$type] > 0 ? 'hot' : '' }}">{{ number_format($counts[$type]) }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="tab-content">
            @foreach ($lists as $type => $list)
                @php $column = $columns[$type]; $meta = $tabMeta[$type]; @endphp
                <div class="tab-pane fade {{ $type === $first ? 'show active' : '' }}" id="{{ $type }}" role="tabpanel">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-3">
                        <div class="small text-2">
                            <i class="mdi mdi-information"></i>
                            แสดง{{ $labels[$type] }}ที่หมดอายุแล้ว หรือจะหมดอายุภายใน <strong>{{ $thresholds[$type] }} วัน</strong> (ก่อนวันที่ {{ fmt_date(now()->addDays($thresholds[$type])) }})
                        </div>
                        <a href="{{ $exportRoutes[$type] }}" class="btn btn-soft-success btn-sm" data-no-loader><i class="mdi mdi-file-excel"></i> ดาวน์โหลด Excel</a>
                    </div>

                    @if ($list->count() === 0)
                        <x-empty-state icon="mdi-check-circle" title="ไม่มี{{ $labels[$type] }}ที่ใกล้หมดอายุ" text="ทุกอย่างเรียบร้อยดี" />
                    @else
                        <div class="table-wrap">
                            <table class="table table-modern">
                                <thead>
                                    <tr>
                                        <th style="width:48px">#</th>
                                        <th>ชื่อแรงงาน</th>
                                        <th>บริษัท / นายจ้าง</th>
                                        <th>{{ $meta['numberLabel'] }}</th>
                                        <th>วันหมดอายุ</th>
                                        <th>สถานะ</th>
                                        <th class="td-actions"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($list as $i => $row)
                                        <tr>
                                            <td class="text-3">{{ $list->firstItem() + $i }}</td>
                                            <td>
                                                <div class="fw-name">{{ $row->labour_prefix }}. {{ $row->labour_fullname }}</div>
                                                <div class="sub text-mono">{{ $row->labour_passport_number }}</div>
                                            </td>
                                            <td>
                                                <div>{{ $row->company_name ?? '-' }}</div>
                                                @if ($row->company_contact_sale)<div class="sub">ผู้ประสานงาน: {{ $row->company_contact_sale }}</div>@endif
                                            </td>
                                            <td class="text-mono">{{ $row->{$meta['number']} ?: '-' }}</td>
                                            <td>{{ fmt_date($row->{$column}) }}</td>
                                            <td><x-days-left :date="$row->{$column}" :warn="$thresholds[$type]" /></td>
                                            <td class="td-actions">
                                                <a href="{{ route('labour.show', $row->labour_id) }}" class="btn btn-soft btn-sm"><i class="mdi mdi-eye"></i> ดูข้อมูล</a>
                                                @if (user_is('Admin', 'MasterAdmin'))
                                                    <a href="{{ route('labour.edit', $row->labour_id) }}" class="btn btn-soft btn-sm"><i class="mdi mdi-pencil"></i></a>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="table-footer">{{ $list->links() }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endsection
