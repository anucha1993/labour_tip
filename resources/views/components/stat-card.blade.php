@props(['label', 'value', 'icon' => 'mdi-account', 'color' => 'primary', 'href' => null])
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'card stat-card text-reset h-100']) }}>
    <span class="ic ic-{{ $color }}"><i class="mdi {{ $icon }}"></i></span>
    <div>
        <div class="v">{{ is_numeric($value) ? number_format($value) : $value }}</div>
        <div class="l">{{ $label }}</div>
    </div>
</{{ $tag }}>
