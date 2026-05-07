{{--
    Conference の create / edit で共通利用するフォーム本体。
    呼び出し元で以下の変数を渡す:
      $conference   ?Conference  edit 時のみ既存値、create 時は null
      $categories   Category[]   選択肢用 (displayOrder 昇順想定)
      $formats      ConferenceFormat[]  enum cases
      $action       string       form の action URL
      $method       'POST'|'PUT' form の HTTP method (PUT は @method で偽装)

    送信は 2 つの submit ボタンで status を切り替える:
      - 「下書き保存」: name="status" value="draft" → StoreRequest が Draft 用に
        分岐し、cfpUrl 等は任意扱いになる (Phase 0.5 / Issue #41)
      - 「公開する」: name="status" value="published" → 従来通り全項目必須

    エラー / 旧入力は FormRequest 違反時に session に自動 flash されるので
    `old()` / `@error` で復元する。
--}}
@php
    /** @var \App\Domain\Conferences\Conference|null $conference */
    /** @var \App\Domain\Categories\Category[] $categories */
    /** @var \App\Domain\Conferences\ConferenceFormat[] $formats */

    // edit 時の既存値を old() の fallback にする
    $existing = $conference;
    $val = static fn (string $key, mixed $default = '') => old($key, $existing?->{$key} ?? $default);

    // categories は配列。old は array
    $selectedCategoryIds = old('categories', $existing?->categories ?? []);
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($method === 'PUT')
        @method('PUT')
    @endif

    {{-- name --}}
    <div>
        <label for="name" class="mb-1 block text-sm font-medium">名称 <span class="text-red-600" title="公開時は必須 (下書き保存は任意)">*</span></label>
        <input type="text" id="name" name="name" required maxlength="200"
               value="{{ $val('name') }}"
               class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none">
        @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- trackName --}}
    <div>
        <label for="trackName" class="mb-1 block text-sm font-medium">トラック名</label>
        <input type="text" id="trackName" name="trackName" maxlength="100"
               value="{{ $val('trackName') }}"
               class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none">
        @error('trackName')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- URLs --}}
    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="officialUrl" class="mb-1 block text-sm font-medium">公式 URL <span class="text-red-600" title="公開時は必須 (下書き保存は任意)">*</span></label>
            <input type="url" id="officialUrl" name="officialUrl" required
                   value="{{ $val('officialUrl') }}"
                   placeholder="https://..."
                   class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none">
            @error('officialUrl')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="cfpUrl" class="mb-1 block text-sm font-medium">CfP URL <span class="text-red-600" title="公開時は必須 (下書き保存は任意)">*</span></label>
            <input type="url" id="cfpUrl" name="cfpUrl"
                   value="{{ $val('cfpUrl') }}"
                   placeholder="https://..."
                   class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none">
            @error('cfpUrl')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>

    {{-- 日付 --}}
    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="eventStartDate" class="mb-1 block text-sm font-medium">開催開始日 <span class="text-red-600" title="公開時は必須 (下書き保存は任意)">*</span></label>
            <input type="date" id="eventStartDate" name="eventStartDate"
                   value="{{ $val('eventStartDate') }}"
                   class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none">
            @error('eventStartDate')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="eventEndDate" class="mb-1 block text-sm font-medium">開催終了日 <span class="text-red-600" title="公開時は必須 (下書き保存は任意)">*</span></label>
            <input type="date" id="eventEndDate" name="eventEndDate"
                   value="{{ $val('eventEndDate') }}"
                   class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none">
            @error('eventEndDate')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="cfpStartDate" class="mb-1 block text-sm font-medium">CfP 開始日</label>
            <input type="date" id="cfpStartDate" name="cfpStartDate"
                   value="{{ $val('cfpStartDate') }}"
                   class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none">
            @error('cfpStartDate')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="cfpEndDate" class="mb-1 block text-sm font-medium">CfP 締切 <span class="text-red-600" title="公開時は必須 (下書き保存は任意)">*</span></label>
            <input type="date" id="cfpEndDate" name="cfpEndDate"
                   value="{{ $val('cfpEndDate') }}"
                   class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none">
            @error('cfpEndDate')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>

    {{-- venue --}}
    <div>
        <label for="venue" class="mb-1 block text-sm font-medium">会場 <span class="text-red-600" title="公開時は必須 (下書き保存は任意)">*</span></label>
        <input type="text" id="venue" name="venue" maxlength="100"
               value="{{ $val('venue') }}"
               class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none">
        @error('venue')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- format (radio) --}}
    <div>
        <span class="mb-1 block text-sm font-medium">形式 <span class="text-red-600" title="公開時は必須 (下書き保存は任意)">*</span></span>
        <div class="flex gap-4">
            @php
                $currentFormat = old('format', $existing?->format?->value ?? '');
            @endphp
            @foreach ($formats as $fmt)
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="format" value="{{ $fmt->value }}"
                           {{ $currentFormat === $fmt->value ? 'checked' : '' }}
                           class="h-4 w-4">
                    <span class="text-sm">{{ $fmt->value }}</span>
                </label>
            @endforeach
        </div>
        @error('format')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- categories (multi-select via checkbox) --}}
    <div>
        <span class="mb-1 block text-sm font-medium">カテゴリ <span class="text-xs text-gray-500">(任意)</span></span>
        @if (count($categories) === 0)
            <p class="text-sm text-gray-500">登録されたカテゴリがありません</p>
        @else
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                @foreach ($categories as $cat)
                    <label class="inline-flex items-center gap-2 rounded border border-gray-200 px-3 py-2 hover:bg-gray-50">
                        <input type="checkbox" name="categories[]" value="{{ $cat->categoryId }}"
                               {{ in_array($cat->categoryId, $selectedCategoryIds, true) ? 'checked' : '' }}
                               class="h-4 w-4">
                        <span class="text-sm">{{ $cat->name }}</span>
                    </label>
                @endforeach
            </div>
        @endif
        @error('categories')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- description --}}
    <div>
        <label for="description" class="mb-1 block text-sm font-medium">説明</label>
        <textarea id="description" name="description" rows="3" maxlength="2000"
                  class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none">{{ $val('description') }}</textarea>
        @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- themeColor --}}
    <div>
        <label for="themeColor" class="mb-1 block text-sm font-medium">テーマカラー</label>
        <div class="flex items-center gap-2">
            <input type="color" id="themeColor" name="themeColor"
                   value="{{ $val('themeColor', '#777BB4') }}"
                   class="h-10 w-16 cursor-pointer rounded border border-gray-300">
            <span class="text-xs text-gray-500">未指定なら空欄を維持してください</span>
        </div>
        @error('themeColor')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- 送信: 2 つの submit ボタンで status を分岐させる (Phase 0.5 / Issue #41)
         クリックされたボタンの name=value だけが request に乗る仕様を活用。
         サーバ側 StoreConferenceRequest が status='draft' なら必須項目を緩和、
         status='published' なら従来通り全項目検証する。 --}}
    <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
        <a href="{{ route('admin.conferences.index') }}"
           class="rounded border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">
            キャンセル
        </a>
        <button type="submit" name="status" value="draft"
                class="rounded border border-gray-400 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            下書き保存
        </button>
        <button type="submit" name="status" value="published"
                class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            公開する
        </button>
    </div>
</form>
