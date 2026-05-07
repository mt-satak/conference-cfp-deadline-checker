@extends('admin.layouts.app')

@section('title', 'ビルド状態')

@php
    use App\Domain\Build\BuildJobStatus;

    $statusBadge = static function (BuildJobStatus $status): string {
        return match ($status) {
            BuildJobStatus::Succeed => 'bg-green-100 text-green-800',
            BuildJobStatus::Running, BuildJobStatus::Provisioning, BuildJobStatus::Pending
                => 'bg-blue-100 text-blue-800',
            BuildJobStatus::Failed, BuildJobStatus::Cancelled, BuildJobStatus::Cancelling
                => 'bg-red-100 text-red-800',
        };
    };
@endphp

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">ビルド状態</h1>

        @if ($configured)
            <form method="POST" action="{{ route('admin.build.trigger') }}"
                  onsubmit="return confirm('再ビルドをトリガーします。よろしいですか？');">
                @csrf
                <button type="submit"
                        class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                    再ビルドをトリガー
                </button>
            </form>
        @endif
    </div>

    @unless ($configured)
        <div class="mb-4 rounded border border-yellow-300 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
            <p class="font-semibold">ビルドサービスが未構成です</p>
            <p class="mt-1">
                GitHub App の認証情報が設定されていません。
                env (<code class="rounded bg-yellow-100 px-1">GITHUB_APP_ID</code> /
                <code class="rounded bg-yellow-100 px-1">GITHUB_APP_INSTALLATION_ID</code> /
                <code class="rounded bg-yellow-100 px-1">GITHUB_APP_PRIVATE_KEY</code>) を設定するまで再ビルド機能は利用できません。
            </p>
        </div>
    @endunless

    @if (count($statuses) === 0)
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-gray-500">
            @if ($configured)
                ビルド履歴がありません
            @else
                履歴の取得には GitHub App 設定が必要です
            @endif
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3">ステータス</th>
                        <th class="px-4 py-3">ジョブ ID</th>
                        <th class="px-4 py-3">開始</th>
                        <th class="px-4 py-3">終了</th>
                        <th class="px-4 py-3">コミット</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($statuses as $st)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium {{ $statusBadge($st->status) }}">
                                    {{ $st->status->value }}
                                </span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $st->jobId }}</td>
                            <td class="px-4 py-3 text-xs text-gray-600">{{ $st->startedAt }}</td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                {{ $st->endedAt ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                @if ($st->commitId)
                                    <div class="font-mono text-xs">{{ \Illuminate\Support\Str::limit($st->commitId, 7, '') }}</div>
                                    @if ($st->commitMessage)
                                        <div class="text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($st->commitMessage, 60) }}</div>
                                    @endif
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-sm text-gray-500">{{ count($statuses) }} 件 (最大 10 件まで取得)</p>
    @endif
@endsection
