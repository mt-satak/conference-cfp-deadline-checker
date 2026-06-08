{{--
    CfP Source の create / edit で共通利用するフォーム本体 (Issue #200 PR-1)。
    呼び出し元で以下の変数を渡す:
      $source       ?CfpSource    edit 時のみ既存値、create 時は null
      $action       string        form の action URL
      $method       'POST'|'PUT'  form の HTTP method
      $submitLabel  string        送信ボタンのラベル
--}}
@php
    /** @var \App\Domain\CfpSources\CfpSource|null $source */
    $existing = $source;
    $val = static fn (string $key, mixed $default = '') => old($key, $existing?->{$key} ?? $default);
    // checkbox は old → 既存 enabled → 新規時は true をデフォルトに
    $enabledChecked = old('enabled', $existing?->enabled ?? true);
@endphp

{{-- 全体エラー (CfpSourceConflictException 等) --}}
@error('conflict')
    <x-admin.alert variant="error">{{ $message }}</x-admin.alert>
@enderror

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($method === 'PUT')
        @method('PUT')
    @endif

    {{-- name --}}
    <x-admin.form-group>
        <x-admin.label for="name" required>名称 <span class="text-xs text-gray-500">(例: fortee イベント一覧)</span></x-admin.label>
        <x-admin.input id="name" name="name" required maxlength="100" :value="$val('name')" />
        <x-admin.error-message field="name" />
    </x-admin.form-group>

    {{-- url --}}
    <x-admin.form-group>
        <x-admin.label for="url" required>巡回対象 URL <span class="text-xs text-gray-500">(https:// のみ)</span></x-admin.label>
        <x-admin.input type="url" id="url" name="url" required maxlength="2000" placeholder="https://fortee.jp/events" :value="$val('url')" class="font-mono" />
        <x-admin.error-message field="url" />
    </x-admin.form-group>

    {{-- enabled --}}
    <x-admin.form-group>
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="enabled" value="1" {{ $enabledChecked ? 'checked' : '' }}
                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
            <span class="text-sm">有効 (= 週次巡回の対象にする)</span>
        </label>
        <x-admin.error-message field="enabled" />
    </x-admin.form-group>

    {{-- 送信 --}}
    <div class="flex justify-end gap-2 pt-2">
        <x-admin.button as="a" href="{{ route('admin.cfp-sources.index') }}" variant="secondary">
            キャンセル
        </x-admin.button>
        <x-admin.button type="submit">{{ $submitLabel }}</x-admin.button>
    </div>
</form>
