<!DOCTYPE html>
<html lang="th" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name', 'Labour App') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/images/logo168.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('dist/css/icons/material-design-iconic-font/css/materialdesignicons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/labour.css') }}">
    <script>
        try { var t = localStorage.getItem('labour-theme'); if (t === 'dark') document.documentElement.setAttribute('data-bs-theme', 'dark'); } catch (e) {}
    </script>
</head>
<body>
<div class="error-wrap">
    <div>
        <div class="code">@yield('code')</div>
        <h1 class="h4 mt-3">@yield('title')</h1>
        <p class="text-2 mb-4">@yield('message')</p>
        <div class="d-flex gap-2 justify-content-center">
            <a href="javascript:history.back()" class="btn btn-soft"><i class="mdi mdi-arrow-left"></i> ย้อนกลับ</a>
            <a href="{{ url('/') }}" class="btn btn-primary"><i class="mdi mdi-home"></i> ไปหน้าแรก</a>
        </div>
    </div>
</div>
</body>
</html>
