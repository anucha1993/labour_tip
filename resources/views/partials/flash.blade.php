@php
    $flashes = [
        'success' => ['mdi-check-circle', 'alert-success', true],
        'error' => ['mdi-alert-circle', 'alert-danger', false],
        'warning' => ['mdi-alert', 'alert-warning', false],
        'info' => ['mdi-information', 'alert-info', true],
        'status' => ['mdi-information', 'alert-info', true],
    ];
@endphp
@foreach ($flashes as $key => [$icon, $class, $auto])
    @if (session()->has($key))
        <div class="alert {{ $class }} alert-dismissible fade show" role="alert" @if ($auto) data-autodismiss="7000" @endif>
            <i class="mdi {{ $icon }}"></i>
            <div class="flex-grow-1">{{ session($key) }}</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button>
        </div>
    @endif
@endforeach

@if ($errors->any())
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="mdi mdi-alert-circle"></i>
        <div class="flex-grow-1">
            <strong>กรุณาตรวจสอบข้อมูล</strong>
            <ul class="mb-0 mt-1 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button>
    </div>
@endif
