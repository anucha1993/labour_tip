{{-- One file tile in the explorer grid. $doc = LabourDocument, $attrs = data-* for the preview panel, $showLabour = print owner under the name --}}
@php $labourRow = $doc->labour ?? null; @endphp
<div class="dx-file" {!! collect($attrs)->map(fn ($v, $k) => $k . '="' . e($v) . '"')->implode(' ') !!} role="button" tabindex="0" title="{{ $attrs['data-file'] }}">
    <div class="thumb">
        @if ($doc->isImage())
            <img src="{{ $attrs['data-url'] }}" alt="" loading="lazy">
        @else
            <i class="mdi {{ $doc->icon() }}"></i>
        @endif
        <span class="ext">{{ $doc->extension() ?: 'file' }}</span>
    </div>
    <div class="body">
        <div class="t">{{ $doc->name }}</div>
        @if ($showLabour && $labourRow)
            <div class="m" title="{{ $labourRow->labour_fullname }}">{{ $labourRow->labour_fullname }}@if ($labourRow->company) · {{ $labourRow->company->company_name }}@endif</div>
        @else
            <div class="m">{{ $doc->humanSize() }} · {{ $attrs['data-date'] }}</div>
        @endif
    </div>
</div>
