@extends('admin.layouts.app')

@section('title', 'ダッシュボード')

@section('content')
    <h1 class="mb-4 text-2xl font-bold">ダッシュボード</h1>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a href="{{ route('admin.conferences.index') }}" class="block">
            <x-admin.card hoverable class="p-5">
                <div class="text-sm text-gray-500">管理</div>
                <div class="mt-1 text-lg font-semibold">カンファレンス</div>
                <div class="mt-2 text-sm text-gray-600">CfP 期限・URL・カテゴリ等の登録 / 編集</div>
            </x-admin.card>
        </a>

        <a href="{{ route('admin.categories.index') }}" class="block">
            <x-admin.card hoverable class="p-5">
                <div class="text-sm text-gray-500">管理</div>
                <div class="mt-1 text-lg font-semibold">カテゴリ</div>
                <div class="mt-2 text-sm text-gray-600">タグ・分類軸の登録 / 編集 / 削除</div>
            </x-admin.card>
        </a>

        <a href="{{ route('admin.build.index') }}" class="block">
            <x-admin.card hoverable class="p-5">
                <div class="text-sm text-gray-500">運用</div>
                <div class="mt-1 text-lg font-semibold">ビルド</div>
                <div class="mt-2 text-sm text-gray-600">静的サイトの再ビルド / 履歴確認</div>
            </x-admin.card>
        </a>
    </div>
@endsection
