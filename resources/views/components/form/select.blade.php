@props([
    'name',
    'label' => null,
    'options' => [],
    'value' => null,
    'required' => false,
    'placeholder' => null,
    'help' => null,
    'id' => null,
    'wrapper' => 'mb-3',
    'search' => false,
])
@php
    $id = $id ?? $name;
    $hasError = $errors->has($name);
    $val = (string) old($name, $value);
    $classes = 'form-select' . ($search ? ' js-select2' : '') . ($hasError ? ' is-invalid' : '');
@endphp
<div class="{{ $wrapper }}">
    @if ($label)
        <label for="{{ $id }}" class="form-label {{ $required ? 'required' : '' }}">{{ $label }}</label>
    @endif
    <select name="{{ $name }}" id="{{ $id }}" {{ $attributes->merge(['class' => $classes]) }}
        @if ($search) data-placeholder="{{ $placeholder ?? 'เลือก...' }}" @endif @if ($required) required @endif>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optValue => $optLabel)
            <option value="{{ $optValue }}" {{ (string) $optValue === $val && $val !== '' ? 'selected' : '' }}>{{ $optLabel }}</option>
        @endforeach
        {{ $slot }}
    </select>
    @if ($help)<div class="form-text">{{ $help }}</div>@endif
    @if ($hasError)<div class="invalid-feedback">{{ $errors->first($name) }}</div>@endif
</div>
