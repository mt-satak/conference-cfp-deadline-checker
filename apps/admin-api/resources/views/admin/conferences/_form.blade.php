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

    @php
        $reqTitle = '公開時は必須 (下書き保存は任意)';
    @endphp

    {{-- name --}}
    <x-admin.form-group>
        <x-admin.label for="name" required :requiredTitle="$reqTitle">名称</x-admin.label>
        <x-admin.input id="name" name="name" required maxlength="200" :value="$val('name')" />
        <x-admin.error-message field="name" />
    </x-admin.form-group>

    {{-- trackName --}}
    <x-admin.form-group>
        <x-admin.label for="trackName">トラック名</x-admin.label>
        <x-admin.input id="trackName" name="trackName" maxlength="100" :value="$val('trackName')" />
        <x-admin.error-message field="trackName" />
    </x-admin.form-group>

    {{-- URLs --}}
    <div class="grid gap-5 sm:grid-cols-2">
        <x-admin.form-group>
            <x-admin.label for="officialUrl" required :requiredTitle="$reqTitle">公式 URL</x-admin.label>
            <x-admin.input type="url" id="officialUrl" name="officialUrl" required placeholder="https://..." :value="$val('officialUrl')" />
            <x-admin.error-message field="officialUrl" />
        </x-admin.form-group>
        <x-admin.form-group>
            <x-admin.label for="cfpUrl" required :requiredTitle="$reqTitle">CfP URL</x-admin.label>
            <x-admin.input type="url" id="cfpUrl" name="cfpUrl" placeholder="https://..." :value="$val('cfpUrl')" />
            <x-admin.error-message field="cfpUrl" />
        </x-admin.form-group>
    </div>

    {{-- 日付 --}}
    <div class="grid gap-5 sm:grid-cols-2">
        <x-admin.form-group>
            <x-admin.label for="eventStartDate" required :requiredTitle="$reqTitle">開催開始日</x-admin.label>
            <x-admin.input type="date" id="eventStartDate" name="eventStartDate" :value="$val('eventStartDate')" />
            <x-admin.error-message field="eventStartDate" />
        </x-admin.form-group>
        <x-admin.form-group>
            <x-admin.label for="eventEndDate" required :requiredTitle="$reqTitle">開催終了日</x-admin.label>
            <x-admin.input type="date" id="eventEndDate" name="eventEndDate" :value="$val('eventEndDate')" />
            <x-admin.error-message field="eventEndDate" />
        </x-admin.form-group>
        <x-admin.form-group>
            <x-admin.label for="cfpStartDate">CfP 開始日</x-admin.label>
            <x-admin.input type="date" id="cfpStartDate" name="cfpStartDate" :value="$val('cfpStartDate')" />
            <x-admin.error-message field="cfpStartDate" />
        </x-admin.form-group>
        <x-admin.form-group>
            <x-admin.label for="cfpEndDate" required :requiredTitle="$reqTitle">CfP 締切</x-admin.label>
            <x-admin.input type="date" id="cfpEndDate" name="cfpEndDate" :value="$val('cfpEndDate')" />
            <x-admin.error-message field="cfpEndDate" />
        </x-admin.form-group>
    </div>

    {{-- venue --}}
    <x-admin.form-group>
        <x-admin.label for="venue" required :requiredTitle="$reqTitle">会場</x-admin.label>
        <x-admin.input id="venue" name="venue" maxlength="100" :value="$val('venue')" />
        <x-admin.error-message field="venue" />
    </x-admin.form-group>

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
    <x-admin.form-group>
        <x-admin.label for="description">説明</x-admin.label>
        <x-admin.textarea id="description" name="description" rows="3" maxlength="2000">{{ $val('description') }}</x-admin.textarea>
        <x-admin.error-message field="description" />
    </x-admin.form-group>

    {{-- themeColor --}}
    <x-admin.form-group>
        <x-admin.label for="themeColor">テーマカラー</x-admin.label>
        <div class="flex items-center gap-2">
            <input type="color" id="themeColor" name="themeColor"
                   value="{{ $val('themeColor', '#777BB4') }}"
                   class="h-10 w-16 cursor-pointer rounded border border-gray-300">
            <span class="text-xs text-gray-500">未指定なら空欄を維持してください</span>
        </div>
        <x-admin.error-message field="themeColor" />
    </x-admin.form-group>

    {{-- 送信: 2 つの submit ボタンで status を分岐させる (Phase 0.5 / Issue #41)
         クリックされたボタンの name=value だけが request に乗る仕様を活用。
         サーバ側 StoreConferenceRequest が status='draft' なら必須項目を緩和、
         status='published' なら従来通り全項目検証する。 --}}
    <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
        <x-admin.button as="a" href="{{ route('admin.conferences.index') }}" variant="secondary">
            キャンセル
        </x-admin.button>
        <x-admin.button type="submit" name="status" value="draft" variant="secondary">
            下書き保存
        </x-admin.button>
        <x-admin.button type="submit" name="status" value="published">
            公開する
        </x-admin.button>
    </div>
</form>
