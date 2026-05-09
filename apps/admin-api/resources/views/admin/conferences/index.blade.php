@extends('admin.layouts.app')

@section('title', 'カンファレンス一覧')

@section('content')
    @php
        /** @var \App\Domain\Conferences\Conference[] $conferences */
        /** @var ?string $statusFilter */
        /** @var string $sortKey */
        /** @var string $sortOrder */
        // Active タブは Draft + Published を意味する仮想 status (Issue #165)。
        // 「すべて」を撤廃して active を default にすることで、Archived がノイズとして
        // 表示されない状態を実現する。
        $tabs = [
            ['label' => 'アクティブ', 'value' => 'active'],
            ['label' => '公開中', 'value' => 'published'],
            ['label' => '下書き', 'value' => 'draft'],
            ['label' => 'アーカイブ', 'value' => 'archived'],
        ];

        // 列ヘッダのソートリンク URL を生成。
        // - 同じキーをクリック → order を反転 (asc ⇄ desc)
        // - 別キーをクリック → asc (= 新しいキーでまず昇順)
        // status フィルタも同時に保持する (active なフィルタを維持したまま並び替え)。
        $sortUrl = static function (string $key) use ($sortKey, $sortOrder, $statusFilter): string {
            $nextOrder = ($key === $sortKey && $sortOrder === 'asc') ? 'desc' : 'asc';
            $params = ['sort' => $key, 'order' => $nextOrder];
            if ($statusFilter !== null) {
                $params['status'] = $statusFilter;
            }

            return route('admin.conferences.index').'?'.http_build_query($params);
        };

        // 列ヘッダで現在のソートキーかつ方向を視覚化する記号 (▲ asc / ▼ desc)
        $sortIndicator = static function (string $key) use ($sortKey, $sortOrder): string {
            if ($key !== $sortKey) {
                return '';
            }

            return $sortOrder === 'desc' ? ' ▼' : ' ▲';
        };
    @endphp

    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">カンファレンス一覧</h1>
        <x-admin.button as="a" href="{{ route('admin.conferences.create') }}">
            + 新規作成
        </x-admin.button>
    </div>

    {{-- status フィルタタブ (Phase 0.5 / Issue #41 / Issue #165 で archived/active 追加) --}}
    <div class="mb-4 flex gap-2 border-b border-gray-200">
        @foreach ($tabs as $tab)
            @php
                $url = route('admin.conferences.index').'?status='.$tab['value'];
                $active = $statusFilter === $tab['value'];
            @endphp
            <a href="{{ $url }}"
               class="border-b-2 px-3 py-2 text-sm {{ $active ? 'border-blue-600 font-semibold text-blue-700' : 'border-transparent text-gray-600 hover:text-gray-900' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>

    @if (count($conferences) === 0)
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-gray-500">
            該当するカンファレンスがありません
        </div>
    @else
        <x-admin.card class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3">
                            <a href="{{ $sortUrl('name') }}" class="hover:text-gray-900">名称{{ $sortIndicator('name') }}</a>
                        </th>
                        <th class="px-4 py-3">状態</th>
                        <th class="px-4 py-3">
                            <a href="{{ $sortUrl('cfpEndDate') }}" class="hover:text-gray-900">CfP 締切{{ $sortIndicator('cfpEndDate') }}</a>
                        </th>
                        <th class="px-4 py-3">
                            <a href="{{ $sortUrl('eventStartDate') }}" class="hover:text-gray-900">開催日{{ $sortIndicator('eventStartDate') }}</a>
                        </th>
                        <th class="px-4 py-3">形式</th>
                        <th class="px-4 py-3">カテゴリ数</th>
                        <th class="px-4 py-3 text-right">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($conferences as $conf)
                        @php
                            $isDraft = $conf->status === \App\Domain\Conferences\ConferenceStatus::Draft;
                            $isArchived = $conf->status === \App\Domain\Conferences\ConferenceStatus::Archived;
                        @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $conf->name }}</div>
                                @if ($conf->trackName)
                                    <div class="text-xs text-gray-500">{{ $conf->trackName }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($isDraft)
                                    <span class="inline-flex rounded bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-800">下書き</span>
                                @elseif ($isArchived)
                                    <span class="inline-flex rounded bg-gray-300 px-2 py-0.5 text-xs font-medium text-gray-700">アーカイブ</span>
                                @else
                                    <span class="inline-flex rounded bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">公開中</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $conf->cfpEndDate ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($conf->eventStartDate)
                                    {{ $conf->eventStartDate }}
                                    @if ($conf->eventEndDate && $conf->eventEndDate !== $conf->eventStartDate)
                                        – {{ $conf->eventEndDate }}
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($conf->format)
                                    <span class="inline-flex rounded bg-gray-100 px-2 py-0.5 text-xs">
                                        {{ $conf->format->value }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ count($conf->categories) }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    @if ($isDraft)
                                        {{-- Draft 行に「公開する」ショートカット (一覧から 1 クリック)
                                             POST 用なので form 包む。必須項目欠落時はサーバ側でエラー返す。 --}}
                                        <form method="POST"
                                              action="{{ route('admin.conferences.publish', $conf->conferenceId) }}"
                                              onsubmit="return confirm('「{{ $conf->name }}」を公開します。よろしいですか？');">
                                            @csrf
                                            <x-admin.button type="submit" size="sm" variant="success">公開する</x-admin.button>
                                        </form>
                                    @endif
                                    <x-admin.button as="a"
                                        href="{{ route('admin.conferences.edit', $conf->conferenceId) }}"
                                        size="sm"
                                        variant="secondary">
                                        編集
                                    </x-admin.button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-admin.card>

        <p class="mt-3 text-sm text-gray-500">{{ count($conferences) }} 件</p>
    @endif
@endsection
