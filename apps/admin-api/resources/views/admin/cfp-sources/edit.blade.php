@extends('admin.layouts.app')

@section('title', 'CfP ソース編集')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">CfP ソース編集</h1>
        <a href="{{ route('admin.cfp-sources.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
            ← 一覧へ戻る
        </a>
    </div>

    <x-admin.card class="p-6">
        @include('admin.cfp-sources._form', [
            'source' => $source,
            'action' => route('admin.cfp-sources.update', $source->sourceId),
            'method' => 'PUT',
            'submitLabel' => '更新する',
        ])
    </x-admin.card>

    {{-- 削除セクション --}}
    <div class="mt-8 rounded-lg border border-red-200 bg-red-50 p-6">
        <h2 class="text-lg font-semibold text-red-800">削除</h2>
        <p class="mt-1 text-sm text-red-700">
            この CfP ソースを削除します。一時的に巡回から外したいだけなら「無効」状態に切り替える方が安全です。
        </p>
        <form method="POST" action="{{ route('admin.cfp-sources.destroy', $source->sourceId) }}"
              class="mt-3"
              data-confirm-message="「{{ $source->name }}」を削除します。よろしいですか？">
            @csrf
            @method('DELETE')
            <x-admin.button type="submit" variant="danger">削除する</x-admin.button>
        </form>
    </div>
@endsection
