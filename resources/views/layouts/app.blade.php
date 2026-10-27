<!DOCTYPE html>
<html lang="th" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'เข้าสู่ระบบ') · {{ config('app.name', 'Labour App') }}</title>
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
</head>
<body>
<div class="page-progress" id="pageProgress"></div>
<div class="auth-wrap">
    <section class="auth-side">
        <div class="brand">
            <img src="{{ asset('assets/images/logo168.png') }}" alt="The Importer 168">
            <div>
                <b>The Importer 168 Co., Ltd.</b>
                <span>Foreign Worker Employment Agency</span>
            </div>
        </div>
        <div>
            <h2>ระบบบริหารข้อมูลแรงงานต่างด้าว</h2>
            <ul>
                <li><i class="mdi mdi-bell-ring"></i> แจ้งเตือนวีซ่า หนังสือเดินทาง ใบอนุญาตทำงาน และรายงานตัว 90 วัน</li>
                <li><i class="mdi mdi-account-multiple"></i> จัดการข้อมูลแรงงาน นายจ้าง และเอเจนซี่ในที่เดียว</li>
                <li><i class="mdi mdi-file-chart"></i> ออกรายงาน Excel / PDF ได้ทันที</li>
            </ul>
        </div>
        <div class="foot">62/231-232 ซอยประเสริฐมนูกิจ 27 ถนนประเสริฐมนูกิจ แขวงจรเข้บัว เขตลาดพร้าว กรุงเทพฯ 10230 · <a class="text-white" href="https://importer168.com/" target="_blank" rel="noopener">importer168.com</a></div>
    </section>

    <section class="auth-form">
        <div class="auth-card">
            @yield('content')
        </div>
    </section>
</div>

<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/labour.js') }}?v={{ filemtime(public_path('js/labour.js')) }}"></script>
</body>
</html>
