@props(['hoverable' => false])

@php
    $base = 'rounded-lg border border-gray-200 bg-white';
    $hoverClass = $hoverable ? ' transition hover:border-blue-400 hover:shadow-sm' : '';
@endphp

<div {{ $attributes->merge(['class' => $base . $hoverClass]) }}>
    {{ $slot }}
</div>
