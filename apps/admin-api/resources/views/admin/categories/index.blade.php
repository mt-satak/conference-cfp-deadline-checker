@extends('admin.layouts.app')

@section('title', 'カテゴリ一覧')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">カテゴリ一覧</h1>
        <x-admin.button as="a" href="{{ route('admin.categories.create') }}">+ 新規作成</x-admin.button>
    </div>

    @if (count($categories) === 0)
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-gray-500">
            登録されたカテゴリがありません
        </div>
    @else
        <x-admin.card class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3">表示順</th>
                        <th class="px-4 py-3">名称</th>
                        <th class="px-4 py-3">slug</th>
                        <th class="px-4 py-3">軸</th>
                        <th class="px-4 py-3 text-right">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($categories as $cat)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-500">{{ $cat->displayOrder }}</td>
                            <td class="px-4 py-3 font-medium">{{ $cat->name }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $cat->slug }}</td>
                            <td class="px-4 py-3">
                                @if ($cat->axis !== null)
                                    <span class="inline-flex rounded bg-gray-100 px-2 py-0.5 text-xs">
                                        {{ $cat->axis->value }}
                                    </span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.categories.edit', $cat->categoryId) }}"
                                   class="text-blue-600 hover:text-blue-800">編集</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-admin.card>

        <p class="mt-3 text-sm text-gray-500">{{ count($categories) }} 件</p>
    @endif
@endsection
