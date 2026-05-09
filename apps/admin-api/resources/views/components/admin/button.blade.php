@props([
    'variant' => 'primary',
    'as' => 'button',
    'size' => 'default',
])

@php
    // size=sm はテーブル行内のアクションボタン用 (= 縦幅を抑える)
    $sizeClass = $size === 'sm'
        ? 'rounded px-2 py-1 text-xs font-medium'
        : 'rounded px-4 py-2 text-sm font-medium';

    $variantClass = match ($variant) {
        'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50',
        'danger' => 'bg-red-600 text-white hover:bg-red-700',
        'success' => 'bg-green-600 text-white hover:bg-green-700',
        default => 'bg-blue-600 text-white hover:bg-blue-700',
    };
@endphp

@if ($as === 'a')
    <a {{ $attributes->merge(['class' => "{$sizeClass} {$variantClass}"]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['class' => "{$sizeClass} {$variantClass}"]) }}>{{ $slot }}</button>
@endif
