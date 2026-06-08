@extends('admin.layouts.app')

@section('title', 'CfP ソース一覧')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">CfP ソース一覧</h1>
        <x-admin.button as="a" href="{{ route('admin.cfp-sources.create') }}">+ 新規作成</x-admin.button>
    </div>

    <p class="mb-4 text-sm text-gray-600">
        週次自動 CfP 発見 (Issue #200) で巡回する集約ページ URL を管理します。
        無効化中の source は次回巡回でスキップされます。
    </p>

    @if (count($sources) === 0)
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-gray-500">
            登録された CfP ソースがありません
        </div>
    @else
        <x-admin.card class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3">名称</th>
                        <th class="px-4 py-3">URL</th>
                        <th class="px-4 py-3">状態</th>
                        <th class="px-4 py-3 text-right">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($sources as $source)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium">{{ $source->name }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-600 break-all">{{ $source->url }}</td>
                            <td class="px-4 py-3">
                                @if ($source->enabled)
                                    <span class="inline-flex whitespace-nowrap rounded bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">有効</span>
                                @else
                                    <span class="inline-flex whitespace-nowrap rounded bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-700">無効</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <x-admin.button as="a" href="{{ route('admin.cfp-sources.edit', $source->sourceId) }}" size="sm" variant="secondary">編集</x-admin.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-admin.card>

        <p class="mt-3 text-sm text-gray-500">{{ count($sources) }} 件</p>
    @endif
@endsection
