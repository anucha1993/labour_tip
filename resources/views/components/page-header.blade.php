@props(['title', 'subtitle' => null])
<div class="page-header">
    <div>
        <h1>{{ $title }}</h1>
        @if ($subtitle)<p class="lead-sm">{{ $subtitle }}</p>@endif
    </div>
    @if (trim($slot))
        <div class="actions">{{ $slot }}</div>
    @endif
</div>
