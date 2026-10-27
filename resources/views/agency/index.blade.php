@extends('layouts.main_layout')

@section('title', 'เอเจนซี่')
@section('crumb', 'ข้อมูลเอเจนซี่จัดหาแรงงาน')

@section('content')
    @php $canEdit = user_is('Admin', 'MasterAdmin'); @endphp

    <x-page-header title="เอเจนซี่" subtitle="ทั้งหมด {{ number_format($agencies->total()) }} เอเจนซี่">
        @if ($canEdit)
            <a href="{{ route('agency.create') }}" class="btn btn-primary"><i class="mdi mdi-plus"></i> เพิ่มเอเจนซี่</a>
        @endif
    </x-page-header>

    <div class="card">
        <form method="GET" action="{{ route('agency.index') }}" class="card-body pb-0">
            <div class="row g-2">
                <div class="col-md-6 col-lg-5">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0"><i class="mdi mdi-magnify"></i></span>
                        <input type="search" name="keyword" class="form-control border-start-0" placeholder="ชื่อเอเจนซี่, ผู้ติดต่อ, สัญชาติ" value="{{ $keyword }}">
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="mdi mdi-magnify"></i> ค้นหา</button>
                    @if ($keyword)<a href="{{ route('agency.index') }}" class="btn btn-soft"><i class="mdi mdi-refresh"></i></a>@endif
                </div>
            </div>
        </form>

        @if ($agencies->count() === 0)
            <x-empty-state icon="mdi-earth" title="ไม่พบเอเจนซี่" />
        @else
            <div class="table-wrap mt-3">
                <table class="table table-modern">
                    <thead>
                        <tr>
                            <th style="width:48px">#</th>
                            <th>ชื่อเอเจนซี่</th>
                            <th>สัญชาติ</th>
                            <th>ผู้ติดต่อ</th>
                            <th>อีเมล</th>
                            <th class="text-center">แรงงาน</th>
                            <th class="td-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($agencies as $i => $a)
                            <tr>
                                <td class="text-3">{{ $agencies->firstItem() + $i }}</td>
                                <td>
                                    <div class="fw-name">{{ $a->agency_name }}</div>
                                    <div class="sub text-mono">{{ $a->agency_tax }}</div>
                                </td>
                                <td><span class="badge-soft badge-soft-info">{{ $a->name_th ?? $a->agency_nationality }}</span></td>
                                <td>
                                    <div>{{ $a->agency_contact ?: '-' }}</div>
                                    <div class="sub">{{ $a->agency_phone }}</div>
                                </td>
                                <td>{{ $a->agency_email ?: '-' }}</td>
                                <td class="text-center"><span class="badge-soft badge-soft-primary">{{ number_format($a->labours_count) }}</span></td>
                                <td class="td-actions">
                                    @if ($canEdit)
                                        <a href="{{ route('agency.edit', $a->agency_id) }}" class="btn btn-soft btn-sm"><i class="mdi mdi-pencil"></i> แก้ไข</a>
                                    @endif
                                    @if (user_is('MasterAdmin'))
                                        <x-delete-form :action="route('agency.delete', $a->agency_id)" confirm="ต้องการลบเอเจนซี่ {{ $a->agency_name }} ใช่หรือไม่?" title="ยืนยันการลบเอเจนซี่" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="table-footer">{{ $agencies->links() }}</div>
        @endif
    </div>
@endsection
