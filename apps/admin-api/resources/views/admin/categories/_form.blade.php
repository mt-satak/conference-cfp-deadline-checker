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
    <div>
        <label for="name" class="mb-1 block text-sm font-medium">名称 <span class="text-red-600">*</span></label>
        <input type="text" id="name" name="name" required maxlength="100"
               value="{{ $val('name') }}"
               class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none">
        @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- slug --}}
    <div>
        <label for="slug" class="mb-1 block text-sm font-medium">slug <span class="text-red-600">*</span> <span class="text-xs text-gray-500">(英小文字・数字・ハイフンのみ)</span></label>
        <input type="text" id="slug" name="slug" required maxlength="64" pattern="^[a-z0-9-]+$"
               value="{{ $val('slug') }}"
               class="w-full rounded border border-gray-300 px-3 py-2 font-mono focus:border-blue-500 focus:outline-none">
        @error('slug')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- displayOrder --}}
    <div>
        <label for="displayOrder" class="mb-1 block text-sm font-medium">表示順 <span class="text-red-600">*</span> <span class="text-xs text-gray-500">(整数。軸ごとに番号帯を分けて運用)</span></label>
        <input type="number" id="displayOrder" name="displayOrder" required step="1"
               value="{{ $val('displayOrder', 100) }}"
               class="w-32 rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none">
        @error('displayOrder')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

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
