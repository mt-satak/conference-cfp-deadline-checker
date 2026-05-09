@props([
    'name' => null,
    'value' => null,
    'checked' => false,
])

<label class="inline-flex items-center gap-2">
    <input type="radio"
        @if ($name) name="{{ $name }}" @endif
        @if ($value !== null) value="{{ $value }}" @endif
        @checked($checked)
        {{ $attributes->merge(['class' => 'h-4 w-4']) }}>
    <span class="text-sm">{{ $slot }}</span>
</label>
