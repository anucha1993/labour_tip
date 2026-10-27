@props(['items'])
{{-- $items: ['anchor-id' => ['icon' => 'mdi-...', 'label' => '...'], ...] --}}
<nav class="form-nav d-none d-md-flex" aria-label="หมวดของฟอร์ม">
    @foreach ($items as $id => $item)
        <a href="#{{ $id }}" class="{{ $loop->first ? 'active' : '' }}"><i class="mdi {{ $item['icon'] }}"></i>{{ $item['label'] }}</a>
    @endforeach
</nav>
