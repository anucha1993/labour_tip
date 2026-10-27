@extends('layouts.main_layout')

@section('title', 'บริษัท / นายจ้าง')
@section('crumb', 'ข้อมูลบริษัทและนายจ้าง')

@section('content')
    @php $canEdit = user_is('Admin', 'MasterAdmin'); @endphp

    <x-page-header title="บริษัท / นายจ้าง" subtitle="ทั้งหมด {{ number_format($companies->total()) }} บริษัท">
        @if ($canEdit)
            <a href="{{ route('company.create') }}" class="btn btn-primary"><i class="mdi mdi-plus"></i> เพิ่มบริษัท</a>
        @endif
    </x-page-header>

    <div class="card">
        <form method="GET" action="{{ route('company.index') }}" class="card-body pb-0">
            <div class="row g-2">
                <div class="col-md-6 col-lg-5">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0"><i class="mdi mdi-magnify"></i></span>
                        <input type="search" name="keyword" class="form-control border-start-0" placeholder="ชื่อบริษัท, เลขทะเบียน, ผู้ติดต่อ, ผู้ประสานงาน" value="{{ $keyword }}">
                    </div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <select name="status" class="form-select" data-autosubmit>
                        <option value="">ทุกสถานะ</option>
                        <option value="enable" {{ $status === 'enable' ? 'selected' : '' }}>เปิดใช้งาน</option>
                        <option value="disable" {{ $status === 'disable' ? 'selected' : '' }}>ปิดใช้งาน</option>
                    </select>
                </div>
                <div class="col-6 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="mdi mdi-magnify"></i> ค้นหา</button>
                    @if ($keyword || $status)<a href="{{ route('company.index') }}" class="btn btn-soft"><i class="mdi mdi-refresh"></i></a>@endif
                </div>
            </div>
        </form>

        @if ($companies->count() === 0)
            <x-empty-state icon="mdi-domain" title="ไม่พบบริษัท" text="ลองค้นหาด้วยคำอื่น หรือเพิ่มบริษัทใหม่" />
        @else
            <div class="table-wrap mt-3">
                <table class="table table-modern">
                    <thead>
                        <tr>
                            <th style="width:48px">#</th>
                            <th>ชื่อบริษัท / นายจ้าง</th>
                            <th>เลขทะเบียน</th>
                            <th>ผู้ติดต่อ</th>
                            <th>ผู้ประสานงานการตลาด</th>
                            <th class="text-center">แรงงาน</th>
                            <th>สถานะ</th>
                            <th class="td-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($companies as $i => $c)
                            <tr>
                                <td class="text-3">{{ $companies->firstItem() + $i }}</td>
                                <td>
                                    <div class="fw-name">{{ $c->company_name }}</div>
                                    <div class="sub">{{ $c->company_type ?: '' }}</div>
                                </td>
                                <td class="text-mono">{{ $c->company_tax ?: '-' }}</td>
                                <td>
                                    <div>{{ $c->company_contact ?: '-' }}</div>
                                    <div class="sub">{{ $c->company_phone }}</div>
                                </td>
                                <td>{{ $c->company_contact_sale ?: '-' }}</td>
                                <td class="text-center">
                                    <a href="{{ route('labour.index', ['company_id' => $c->company_id]) }}" class="badge-soft badge-soft-primary">{{ number_format($c->labours_count) }}</a>
                                </td>
                                <td><x-status-badge :value="$c->company_status" type="enable" /></td>
                                <td class="td-actions">
                                    @if ($canEdit)
                                        <a href="{{ route('company.edit', $c->company_id) }}" class="btn btn-soft btn-sm" title="แก้ไข"><i class="mdi mdi-pencil"></i> แก้ไข</a>
                                    @endif
                                    @if (user_is('MasterAdmin'))
                                        <x-delete-form :action="route('company.delete', $c->company_id)" confirm="ต้องการลบบริษัท {{ $c->company_name }} ใช่หรือไม่?" title="ยืนยันการลบบริษัท" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="table-footer">{{ $companies->links() }}</div>
        @endif
    </div>
@endsection
