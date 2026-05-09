@props([
    'for' => null,
    'required' => false,
    'requiredTitle' => null,
])

<label @if ($for) for="{{ $for }}" @endif {{ $attributes->merge(['class' => 'mb-1 block text-sm font-medium']) }}>
    {{ $slot }}
    @if ($required)
        <span class="text-red-600" @if ($requiredTitle) title="{{ $requiredTitle }}" @endif>*</span>
    @endif
</label>
