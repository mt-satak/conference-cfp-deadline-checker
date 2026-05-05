@extends('admin.layouts.app')

@section('title', 'カンファレンス新規作成')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">カンファレンス新規作成</h1>
        <a href="{{ route('admin.conferences.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
            ← 一覧へ戻る
        </a>
    </div>

    {{-- URL 取り込み (Issue #40 Phase 3 PR-3): 公式 URL を入れると LLM が抽出して下のフォームを prefill --}}
    <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4">
        <h2 class="mb-2 text-sm font-semibold text-blue-900">URL から取り込む (β)</h2>
        <p class="mb-3 text-xs text-blue-800">
            公式サイト URL を入力すると、AI が name / 開催日 / 会場 / カテゴリ等を自動で抽出してフォームに反映します。
            抽出結果は <strong>必ず内容を確認・修正してから保存</strong>してください (ハルシネーションの可能性あり)。
            HTTPS の公開サイトのみ対応、1 時間に 50 件まで実行可能です。
        </p>
        <form method="POST" action="{{ route('admin.conferences.extract-from-url') }}" class="flex flex-wrap gap-2">
            @csrf
            <input type="url" name="url" placeholder="https://phpcon.example.com/2026" required
                   value="{{ old('url') }}"
                   class="flex-1 min-w-[300px] rounded border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
            <button type="submit"
                    class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                取り込む
            </button>
        </form>
        @error('url')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-6">
        @include('admin.conferences._form', [
            'conference' => null,
            'action' => route('admin.conferences.store'),
            'method' => 'POST',
        ])
    </div>
@endsection
