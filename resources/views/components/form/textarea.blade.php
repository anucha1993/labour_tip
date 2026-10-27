@props([
    'name',
    'label' => null,
    'value' => null,
    'required' => false,
    'rows' => 4,
    'help' => null,
    'id' => null,
    'wrapper' => 'mb-3',
])
@php
    $id = $id ?? $name;
    $hasError = $errors->has($name);
@endphp
<div class="{{ $wrapper }}">
    @if ($label)
        <label for="{{ $id }}" class="form-label {{ $required ? 'required' : '' }}">{{ $label }}</label>
    @endif
    <textarea name="{{ $name }}" id="{{ $id }}" rows="{{ $rows }}" {{ $attributes->merge(['class' => 'form-control' . ($hasError ? ' is-invalid' : '')]) }} @if ($required) required @endif>{{ old($name, $value) }}</textarea>
    @if ($help)<div class="form-text">{{ $help }}</div>@endif
    @if ($hasError)<div class="invalid-feedback">{{ $errors->first($name) }}</div>@endif
</div>
