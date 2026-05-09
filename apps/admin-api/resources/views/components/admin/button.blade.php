@props([
    'variant' => 'primary',
    'as' => 'button',
])

@php
    $base = 'rounded px-4 py-2 text-sm font-medium';
    $variantClass = match ($variant) {
        'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50',
        'danger' => 'bg-red-600 text-white hover:bg-red-700',
        default => 'bg-blue-600 text-white hover:bg-blue-700',
    };
@endphp

@if ($as === 'a')
    <a {{ $attributes->merge(['class' => "{$base} {$variantClass}"]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['class' => "{$base} {$variantClass}"]) }}>{{ $slot }}</button>
@endif
