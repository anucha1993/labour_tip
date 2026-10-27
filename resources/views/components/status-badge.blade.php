@props(['value', 'type' => 'job'])
@php
    if ($type === 'job') {
        $map = ['job' => ['success', 'ทำงาน'], 'resign' => ['secondary', 'ลาออก'], 'escape' => ['danger', 'หลบหนี']];
    } elseif ($type === 'role') {
        $map = ['MasterAdmin' => ['danger', 'MasterAdmin'], 'Admin' => ['primary', 'Admin'], 'Member' => ['info', 'Member']];
    } else {
        $map = ['enable' => ['success', 'เปิดใช้งาน'], 'disable' => ['secondary', 'ปิดใช้งาน']];
    }
    [$color, $label] = $map[$value] ?? ['secondary', $value ?: '-'];
@endphp
<span {{ $attributes->merge(['class' => 'badge-soft badge-soft-' . $color]) }}>{{ $label }}</span>
