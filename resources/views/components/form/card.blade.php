@props(['id', 'title', 'icon' => 'mdi-information', 'subtitle' => null, 'color' => 'primary', 'right' => null])
<section id="{{ $id }}" {{ $attributes->merge(['class' => 'card form-card']) }}>
    <div class="form-card-head">
        <span class="ic ic-{{ $color }}"><i class="mdi {{ $icon }}"></i></span>
        <div>
            <h6>{{ $title }}</h6>
            @if ($subtitle)<div class="sub">{{ $subtitle }}</div>@endif
        </div>
        @if ($right)<div class="right">{{ $right }}</div>@endif
    </div>
    <div class="card-body">
        {{ $slot }}
    </div>
</section>
