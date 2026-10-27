@props(['icon' => 'mdi-information', 'title' => 'ไม่พบข้อมูล', 'text' => null])
<div class="empty-state">
    <i class="mdi {{ $icon }}"></i>
    <div class="fw-semibold mt-2">{{ $title }}</div>
    @if ($text)<p>{{ $text }}</p>@endif
    @if (trim($slot))<div class="mt-3">{{ $slot }}</div>@endif
</div>
