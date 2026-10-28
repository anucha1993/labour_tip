@props(['user' => null, 'size' => 36, 'class' => ''])
@php
    $name = trim($user->name ?? '?');
    $initial = mb_strtoupper(mb_substr($name !== '' ? $name : '?', 0, 1));
    $url = $user && ! empty($user->avatar) ? \App\Services\AvatarService::url($user->avatar) : null;
    $style = "width:{$size}px;height:{$size}px;font-size:" . round($size * 0.42) . 'px';
@endphp
@if ($url)
    <img src="{{ $url }}" alt="{{ $name }}" class="avatar avatar-img {{ $class }}" style="{{ $style }}" loading="lazy">
@else
    <span class="avatar {{ $class }}" style="{{ $style }}" title="{{ $name }}">{{ $initial }}</span>
@endif
