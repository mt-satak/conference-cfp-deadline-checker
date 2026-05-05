@extends('admin.layouts.app')

@section('title', 'ダッシュボード')

@section('content')
    <h1 class="mb-4 text-2xl font-bold">ダッシュボード</h1>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a href="{{ route('admin.conferences.index') }}"
           class="block rounded-lg border border-gray-200 bg-white p-5 transition hover:border-blue-400 hover:shadow-sm">
            <div class="text-sm text-gray-500">管理</div>
            <div class="mt-1 text-lg font-semibold">カンファレンス</div>
            <div class="mt-2 text-sm text-gray-600">CfP 期限・URL・カテゴリ等の登録 / 編集</div>
        </a>

        <a href="{{ route('admin.categories.index') }}"
           class="block rounded-lg border border-gray-200 bg-white p-5 transition hover:border-blue-400 hover:shadow-sm">
            <div class="text-sm text-gray-500">管理</div>
            <div class="mt-1 text-lg font-semibold">カテゴリ</div>
            <div class="mt-2 text-sm text-gray-600">タグ・分類軸の登録 / 編集 / 削除</div>
        </a>

        {{-- Build は後続 PR で追加 --}}
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-5 text-gray-400">
            <div class="text-sm">予定</div>
            <div class="mt-1 text-lg font-semibold">ビルド状態</div>
            <div class="mt-2 text-sm">後続 PR で追加</div>
        </div>
    </div>
@endsection
