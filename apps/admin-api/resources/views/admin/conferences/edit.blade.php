@extends('admin.layouts.app')

@section('title', 'カンファレンス編集')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">
            カンファレンス編集
            @if ($conference->status === \App\Domain\Conferences\ConferenceStatus::Draft)
                <span class="ml-2 rounded bg-gray-200 px-2 py-0.5 text-sm font-medium text-gray-800">下書き</span>
            @elseif ($conference->status === \App\Domain\Conferences\ConferenceStatus::Archived)
                <span class="ml-2 rounded bg-gray-300 px-2 py-0.5 text-sm font-medium text-gray-700">アーカイブ</span>
            @else
                <span class="ml-2 rounded bg-green-100 px-2 py-0.5 text-sm font-medium text-green-800">公開中</span>
            @endif
        </h1>
        <a href="{{ route('admin.conferences.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
            ← 一覧へ戻る
        </a>
    </div>

    {{-- AutoCrawl 保留中変更 (Issue #188 PR-3) --}}
    @if (! empty($conference->pendingChanges))
        @php
            // 保留差分のフィールド名 → 日本語ラベル (= form ラベルと揃える)
            $pendingLabels = [
                'cfpUrl' => 'CfP URL',
                'eventStartDate' => '開催開始日',
                'eventEndDate' => '開催終了日',
                'venue' => '会場',
                'format' => '開催形式',
                'cfpStartDate' => 'CfP 開始日',
                'cfpEndDate' => 'CfP 締切',
            ];
        @endphp
        <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-6">
            <h2 class="text-lg font-semibold text-amber-900">
                保留中の変更 ({{ count($conference->pendingChanges) }} 件)
            </h2>
            <p class="mt-1 text-sm text-amber-800">
                AutoCrawl が公式サイトから検知した差分です。「全て適用」で actual に反映、「破棄」で取り消します。
            </p>

            <table class="mt-4 w-full text-sm">
                <thead>
                    <tr class="border-b border-amber-200 text-left text-amber-900">
                        <th class="py-2 pr-4">フィールド</th>
                        <th class="py-2 pr-4">現在 (actual)</th>
                        <th class="py-2 pr-4">→</th>
                        <th class="py-2">検知された値</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($conference->pendingChanges as $field => $entry)
                        <tr class="border-b border-amber-100">
                            <td class="py-2 pr-4 font-medium text-amber-900">{{ $pendingLabels[$field] ?? $field }}</td>
                            <td class="py-2 pr-4 text-gray-700">{{ $entry['old'] ?? '(なし)' }}</td>
                            <td class="py-2 pr-4 text-amber-700">→</td>
                            <td class="py-2 text-amber-900">{{ $entry['new'] ?? '(なし)' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4 flex gap-3">
                <form method="POST"
                      action="{{ route('admin.conferences.pending.apply', $conference->conferenceId) }}"
                      data-confirm-message="保留中の変更を全て公開ページに反映します。よろしいですか？">
                    @csrf
                    <x-admin.button type="submit" variant="success">全て適用</x-admin.button>
                </form>
                <form method="POST"
                      action="{{ route('admin.conferences.pending.reject', $conference->conferenceId) }}">
                    @csrf
                    <x-admin.button type="submit" variant="secondary">破棄</x-admin.button>
                </form>
            </div>
        </div>
    @endif

    <x-admin.card class="p-6">
        @include('admin.conferences._form', [
            'conference' => $conference,
            'action' => route('admin.conferences.update', $conference->conferenceId),
            'method' => 'PUT',
        ])
    </x-admin.card>

    {{-- 削除セクション --}}
    <div class="mt-8 rounded-lg border border-red-200 bg-red-50 p-6">
        <h2 class="text-lg font-semibold text-red-800">削除</h2>
        <p class="mt-1 text-sm text-red-700">
            このカンファレンスを削除します。この操作は取り消せません。
        </p>
        <form method="POST" action="{{ route('admin.conferences.destroy', $conference->conferenceId) }}"
              class="mt-3"
              data-confirm-message="「{{ $conference->name }}」を削除します。よろしいですか？">
            @csrf
            @method('DELETE')
            <x-admin.button type="submit" variant="danger">削除する</x-admin.button>
        </form>
    </div>
@endsection
