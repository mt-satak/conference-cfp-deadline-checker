@props([
    'name' => null,
    'rows' => 3,
])

<textarea @if ($name) name="{{ $name }}" @endif rows="{{ $rows }}"
    {{ $attributes->merge(['class' => 'w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none']) }}>{{ $slot }}</textarea>
