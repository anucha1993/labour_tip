@props(['date', 'warn' => 30])
@php
    $level = days_left_level($date, (int) $warn);
    $icons = ['expired' => 'mdi-alert-circle', 'critical' => 'mdi-clock-alert', 'warning' => 'mdi-clock-alert', 'ok' => 'mdi-check-circle', 'none' => 'mdi-calendar'];
@endphp
<span {{ $attributes->merge(['class' => 'pill pill-' . $level]) }}><i class="mdi {{ $icons[$level] }}"></i>{{ days_left_text($date) }}</span>
