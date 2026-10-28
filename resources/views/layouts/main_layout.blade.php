<!DOCTYPE html>
<html lang="th" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') · @endif{{ config('app.name', 'Labour App') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/images/logo168.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('dist/css/icons/material-design-iconic-font/css/materialdesignicons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/libs/select2/dist/css/select2.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/labour.css') }}?v={{ filemtime(public_path('css/labour.css')) }}">
    <script>
        // Apply the saved theme before first paint to avoid a flash of the wrong colours.
        try {
            var t = localStorage.getItem('labour-theme'); if (t === 'dark') document.documentElement.setAttribute('data-bs-theme', 'dark');
            if (localStorage.getItem('labour-sidebar') === 'collapsed') document.documentElement.classList.add('sidebar-collapsed');
        } catch (e) {}
    </script>
    @stack('head')
</head>
<body>
<div class="page-progress" id="pageProgress"></div>
@php
    $user = auth()->user();
    $initials = mb_strtoupper(mb_substr(trim($user->name ?? '?'), 0, 1));
    $expiryTotal = (int) ($expiryCounts['total'] ?? 0);
    $isMaster = $user && $user->type === 'MasterAdmin';
    $isAdmin = $user && in_array($user->type, ['Admin', 'MasterAdmin'], true);
@endphp
<div class="app-shell">

    {{-- ============================================================ Sidebar --}}
    <aside class="app-sidebar" id="appSidebar" aria-label="เมนูหลัก">
        <a href="{{ route('dashboard') }}" class="sb-brand">
            <img src="{{ asset('assets/images/logo168.png') }}" alt="The Importer 168">
            <span class="text">
                <span class="name d-block">Labour App</span>
                <span class="sub">The Importer 168 Co., Ltd.</span>
            </span>
        </a>

        <nav class="sb-nav">
            <a href="{{ route('dashboard') }}" class="sb-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="mdi mdi-view-dashboard"></i>
                <span class="label">หน้าแรก / แจ้งเตือน</span>
                @if ($expiryTotal > 0)
                    <span class="sb-badge" title="เอกสารใกล้หมดอายุ">{{ $expiryTotal > 999 ? '999+' : $expiryTotal }}</span>
                @endif
            </a>
            <a href="{{ route('labour.index') }}" class="sb-link {{ request()->routeIs('labour.*') || request()->routeIs('import-excel.*') ? 'active' : '' }}">
                <i class="mdi mdi-account-multiple"></i><span class="label">แรงงานต่างด้าว</span>
            </a>
            <a href="{{ route('company.index') }}" class="sb-link {{ request()->routeIs('company.*') ? 'active' : '' }}">
                <i class="mdi mdi-domain"></i><span class="label">บริษัท / นายจ้าง</span>
            </a>
            <a href="{{ route('agency.index') }}" class="sb-link {{ request()->routeIs('agency.*') ? 'active' : '' }}">
                <i class="mdi mdi-earth"></i><span class="label">เอเจนซี่</span>
            </a>
            <a href="{{ route('report.index') }}" class="sb-link {{ request()->routeIs('report.*') ? 'active' : '' }}">
                <i class="mdi mdi-file-chart"></i><span class="label">รายงาน</span>
            </a>
            <a href="{{ route('ai.chat') }}" class="sb-link {{ request()->routeIs('ai.chat') ? 'active' : '' }}">
                <i class="mdi mdi-robot"></i><span class="label">ผู้ช่วย AI</span>
                <span class="badge-soft badge-soft-primary" style="font-size:.62rem">ใหม่</span>
            </a>

            @if ($isAdmin)
                <div class="sb-section">การจัดการ</div>
                <a href="{{ route('setting.index') }}" class="sb-link {{ request()->routeIs('setting.*') ? 'active' : '' }}">
                    <i class="mdi mdi-bell-ring"></i><span class="label">ตั้งค่าวันแจ้งเตือน</span>
                </a>
            @endif
            @if ($isMaster)
                <a href="{{ route('member.index') }}" class="sb-link {{ request()->routeIs('member.*') || request()->routeIs('register') ? 'active' : '' }}">
                    <i class="mdi mdi-account-key"></i><span class="label">สมาชิก / ผู้ใช้งาน</span>
                </a>
                <a href="{{ route('action-logs.index') }}" class="sb-link {{ request()->routeIs('action-logs.*') ? 'active' : '' }}">
                    <i class="mdi mdi-history"></i><span class="label">ประวัติการแก้ไข</span>
                </a>
                <a href="{{ route('ai.logs') }}" class="sb-link {{ request()->routeIs('ai.logs') ? 'active' : '' }}">
                    <i class="mdi mdi-comment-text-outline"></i><span class="label">ประวัติการใช้ AI</span>
                </a>
            @endif
        </nav>

        <div class="sb-user">
            <a href="{{ route('profile.edit') }}" class="text-reset" title="โปรไฟล์ของฉัน"><x-avatar :user="$user" :size="36" /></a>
            <div class="meta">
                <div class="n" title="{{ $user->name ?? '' }}">{{ $user->name ?? '' }}</div>
                <div class="r">{{ role_label($user->type ?? '') }}</div>
            </div>
        </div>
    </aside>
    <div class="app-backdrop" id="sidebarBackdrop"></div>

    {{-- ============================================================ Main --}}
    <div class="app-main">
        <header class="app-topbar">
            <button type="button" class="icon-btn" data-sidebar-toggle aria-label="เปิด/ปิดเมนู"><i class="mdi mdi-menu"></i></button>
            <div class="flex-grow-1 min-w-0">
                <div class="topbar-crumb d-none d-sm-block">@yield('crumb', 'Labour App')</div>
                <h2 class="topbar-title">@yield('title', 'Labour App')</h2>
            </div>

            <a href="{{ route('dashboard') }}#visa" class="icon-btn" title="เอกสารใกล้หมดอายุ {{ $expiryTotal }} รายการ">
                <i class="mdi mdi-bell"></i>
                @if ($expiryTotal > 0)<span class="dot">{{ $expiryTotal > 99 ? '99+' : $expiryTotal }}</span>@endif
            </a>
            <button type="button" class="icon-btn" data-theme-toggle title="สลับโหมดสว่าง/มืด"><i class="mdi mdi-weather-night"></i></button>

            <div class="dropdown">
                <button class="btn btn-soft d-flex align-items-center gap-2 py-1 px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <x-avatar :user="$user" :size="30" />
                    <span class="d-none d-md-inline" style="max-width:160px;overflow:hidden;text-overflow:ellipsis">{{ $user->name ?? '' }}</span>
                    <i class="mdi mdi-chevron-down"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="px-3 py-2">
                        <div class="fw-semibold">{{ $user->name ?? '' }}</div>
                        <div class="small text-3">{{ $user->email ?? '' }}</div>
                        <span class="badge-soft badge-soft-primary mt-1 d-inline-block">{{ role_label($user->type ?? '') }}</span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="mdi mdi-account-circle"></i> โปรไฟล์ของฉัน</a></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger"><i class="mdi mdi-logout text-danger"></i> ออกจากระบบ</button>
                        </form>
                    </li>
                </ul>
            </div>
        </header>

        <main class="app-content">
            @include('partials.flash')
            @yield('content')
            @include('partials.skeleton')
        </main>

        <footer class="app-footer d-flex flex-wrap justify-content-between gap-2">
            <span>© {{ date('Y') }} The Importer 168 Co., Ltd. — ระบบบริหารข้อมูลแรงงานต่างด้าว</span>
            <span>เข้าสู่ระบบในชื่อ {{ $user->name ?? '' }}</span>
        </footer>
    </div>
</div>

@include('partials.confirm-modal')

<script src="{{ asset('assets/libs/jquery/dist/jquery.min.js') }}"></script>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/libs/select2/dist/js/select2.full.min.js') }}"></script>
<script src="{{ asset('js/labour.js') }}?v={{ filemtime(public_path('js/labour.js')) }}"></script>
@stack('scripts')
</body>
</html>
