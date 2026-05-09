{{--
    Category の create / edit で共通利用するフォーム本体。
    呼び出し元で以下の変数を渡す:
      $category     ?Category    edit 時のみ既存値、create 時は null
      $axes         CategoryAxis[]  enum cases
      $action       string       form の action URL
      $method       'POST'|'PUT' form の HTTP method
      $submitLabel  string       送信ボタンのラベル
--}}
@php
    /** @var \App\Domain\Categories\Category|null $category */
    /** @var \App\Domain\Categories\CategoryAxis[] $axes */
    $existing = $category;
    $val = static fn (string $key, mixed $default = '') => old($key, $existing?->{$key} ?? $default);
    $currentAxis = old('axis', $existing?->axis?->value ?? '');
@endphp

{{-- 全体エラー (CategoryConflictException) --}}
@error('conflict')
    <div class="mb-4 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
        {{ $message }}
    </div>
@enderror

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($method === 'PUT')
        @method('PUT')
    @endif

    {{-- name --}}
    <x-admin.form-group>
        <x-admin.label for="name" required>名称</x-admin.label>
        <x-admin.input id="name" name="name" required maxlength="100" :value="$val('name')" />
        <x-admin.error-message field="name" />
    </x-admin.form-group>

    {{-- slug --}}
    <x-admin.form-group>
        <x-admin.label for="slug" required>slug <span class="text-xs text-gray-500">(英小文字・数字・ハイフンのみ)</span></x-admin.label>
        <x-admin.input id="slug" name="slug" required maxlength="64" pattern="^[a-z0-9-]+$" :value="$val('slug')" class="font-mono" />
        <x-admin.error-message field="slug" />
    </x-admin.form-group>

    {{-- displayOrder --}}
    <x-admin.form-group>
        <x-admin.label for="displayOrder" required>表示順 <span class="text-xs text-gray-500">(整数。軸ごとに番号帯を分けて運用)</span></x-admin.label>
        <x-admin.input type="number" id="displayOrder" name="displayOrder" required step="1" :value="$val('displayOrder', 100)" class="!w-32" />
        <x-admin.error-message field="displayOrder" />
    </x-admin.form-group>

    {{-- axis (radio、optional) --}}
    <div>
        <span class="mb-1 block text-sm font-medium">軸ラベル <span class="text-xs text-gray-500">(任意、運用補助)</span></span>
        <div class="flex flex-wrap gap-3">
            <label class="inline-flex items-center gap-2">
                <input type="radio" name="axis" value="" {{ $currentAxis === '' ? 'checked' : '' }} class="h-4 w-4">
                <span class="text-sm text-gray-500">未指定</span>
            </label>
            @foreach ($axes as $a)
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="axis" value="{{ $a->value }}"
                           {{ $currentAxis === $a->value ? 'checked' : '' }}
                           class="h-4 w-4">
                    <span class="text-sm">{{ $a->value }}</span>
                </label>
            @endforeach
        </div>
        @error('axis')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- 送信 --}}
    <div class="flex justify-end gap-2 pt-2">
        <a href="{{ route('admin.categories.index') }}"
           class="rounded border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">
            キャンセル
        </a>
        <button type="submit"
                class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            {{ $submitLabel }}
        </button>
    </div>
</form>
