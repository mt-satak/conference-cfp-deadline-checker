@extends('admin.layouts.app')

@section('title', 'カテゴリ編集')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">カテゴリ編集</h1>
        <a href="{{ route('admin.categories.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
            ← 一覧へ戻る
        </a>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-6">
        @include('admin.categories._form', [
            'category' => $category,
            'action' => route('admin.categories.update', $category->categoryId),
            'method' => 'PUT',
            'submitLabel' => '更新する',
        ])
    </div>

    {{-- 削除セクション --}}
    <div class="mt-8 rounded-lg border border-red-200 bg-red-50 p-6">
        <h2 class="text-lg font-semibold text-red-800">削除</h2>
        <p class="mt-1 text-sm text-red-700">
            このカテゴリを削除します。参照中のカンファレンスがある場合は削除できません。
        </p>
        <form method="POST" action="{{ route('admin.categories.destroy', $category->categoryId) }}"
              class="mt-3"
              onsubmit="return confirm('「{{ $category->name }}」を削除します。よろしいですか？');">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                削除する
            </button>
        </form>
    </div>
@endsection
