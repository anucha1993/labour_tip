<!DOCTYPE html>
<html lang="th" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'เอกสารที่แชร์') · {{ config('app.name', 'Labour App') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/images/logo168.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('dist/css/icons/material-design-iconic-font/css/materialdesignicons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/labour.css') }}?v={{ filemtime(public_path('css/labour.css')) }}">
    <script>
        try { var t = localStorage.getItem('labour-theme'); if (t === 'dark') document.documentElement.setAttribute('data-bs-theme', 'dark'); } catch (e) {}
    </script>
    @stack('head')
</head>
<body class="pub-body">
<header class="pub-top">
    <div class="pub-wrap d-flex align-items-center gap-3">
        <img src="{{ asset('assets/images/logo168.png') }}" alt="The Importer 168" width="36" height="36">
        <div class="min-w-0">
            <div class="fw-semibold">The Importer 168 Co., Ltd.</div>
            <div class="text-2 small">@yield('subtitle', 'เอกสารที่แชร์จากระบบ Labour App')</div>
        </div>
        <button type="button" class="icon-btn ms-auto" data-theme-toggle title="สลับโหมดสว่าง/มืด"><i class="mdi mdi-weather-night"></i></button>
    </div>
</header>
<main class="pub-wrap py-4">
    @include('partials.flash')
    @yield('content')
</main>
<footer class="pub-wrap text-2 small pb-4">© {{ date('Y') }} The Importer 168 Co., Ltd. · ลิงก์นี้ถูกแชร์ให้คุณเป็นการเฉพาะ กรุณาอย่าส่งต่อ</footer>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/labour.js') }}?v={{ filemtime(public_path('js/labour.js')) }}"></script>
@stack('scripts')
</body>
</html>
