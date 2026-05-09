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

    // 日本語短文 (例: 「公開する」「編集」) が table 行内で改行される問題を防止 (= UX 修正)。
    // size 問わず常に nowrap (ボタン内に長文を入れる UX は採用していないため副作用無し)。
    $textClass = 'whitespace-nowrap';
@endphp

@if ($as === 'a')
    <a {{ $attributes->merge(['class' => "{$sizeClass} {$variantClass} {$textClass}"]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['class' => "{$sizeClass} {$variantClass} {$textClass}"]) }}>{{ $slot }}</button>
@endif
