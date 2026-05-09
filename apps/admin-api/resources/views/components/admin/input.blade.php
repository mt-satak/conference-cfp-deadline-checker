@props([
    'type' => 'text',
    'name' => null,
])

<input type="{{ $type }}" @if ($name) name="{{ $name }}" @endif
    {{ $attributes->merge(['class' => 'w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none']) }}>
