@extends('admin.layouts.app')

@section('title', 'カンファレンス一覧')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">カンファレンス一覧</h1>
        <a href="{{ route('admin.conferences.create') }}"
           class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            + 新規作成
        </a>
    </div>

    @if (count($conferences) === 0)
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-gray-500">
            登録されたカンファレンスがありません
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3">名称</th>
                        <th class="px-4 py-3">CfP 締切</th>
                        <th class="px-4 py-3">開催日</th>
                        <th class="px-4 py-3">形式</th>
                        <th class="px-4 py-3">カテゴリ数</th>
                        <th class="px-4 py-3 text-right">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($conferences as $conf)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $conf->name }}</div>
                                @if ($conf->trackName)
                                    <div class="text-xs text-gray-500">{{ $conf->trackName }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $conf->cfpEndDate }}</td>
                            <td class="px-4 py-3">
                                {{ $conf->eventStartDate }}
                                @if ($conf->eventEndDate !== $conf->eventStartDate)
                                    – {{ $conf->eventEndDate }}
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded bg-gray-100 px-2 py-0.5 text-xs">
                                    {{ $conf->format->value }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ count($conf->categories) }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.conferences.edit', $conf->conferenceId) }}"
                                   class="text-blue-600 hover:text-blue-800">編集</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-sm text-gray-500">{{ count($conferences) }} 件</p>
    @endif
@endsection
