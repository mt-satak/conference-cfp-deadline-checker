@props([
    'name' => null,
    'value' => null,
    'checked' => false,
    'boxed' => false,
])

@php
    $labelClass = $boxed
        ? 'inline-flex items-center gap-2 rounded border border-gray-200 px-3 py-2 hover:bg-gray-50'
        : 'inline-flex items-center gap-2';
@endphp

<label class="{{ $labelClass }}">
    <input type="checkbox"
        @if ($name) name="{{ $name }}" @endif
        @if ($value !== null) value="{{ $value }}" @endif
        @checked($checked)
        {{ $attributes->merge(['class' => 'h-4 w-4']) }}>
    <span class="text-sm">{{ $slot }}</span>
</label>
