@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'required' => false,
    'help' => null,
    'id' => null,
    'wrapper' => 'mb-3',
])
@php
    $id = $id ?? $name;
    $hasError = $errors->has($name);
    $val = $type === 'file' ? null : old($name, $value);
@endphp
<div class="{{ $wrapper }}">
    @if ($label)
        <label for="{{ $id }}" class="form-label {{ $required ? 'required' : '' }}">{{ $label }}</label>
    @endif
    <input type="{{ $type }}" name="{{ $name }}" id="{{ $id }}" @if ($type !== 'file') value="{{ $val }}" @endif
        {{ $attributes->merge(['class' => 'form-control' . ($hasError ? ' is-invalid' : '')]) }} @if ($required) required @endif>
    @if ($help)<div class="form-text">{{ $help }}</div>@endif
    @if ($hasError)<div class="invalid-feedback">{{ $errors->first($name) }}</div>@endif
</div>
