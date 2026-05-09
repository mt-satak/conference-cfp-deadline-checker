@extends('admin.layouts.app')

@section('title', 'カンファレンス編集')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">
            カンファレンス編集
            @if ($conference->status === \App\Domain\Conferences\ConferenceStatus::Draft)
                <span class="ml-2 rounded bg-gray-200 px-2 py-0.5 text-sm font-medium text-gray-800">下書き</span>
            @else
                <span class="ml-2 rounded bg-green-100 px-2 py-0.5 text-sm font-medium text-green-800">公開中</span>
            @endif
        </h1>
        <a href="{{ route('admin.conferences.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
            ← 一覧へ戻る
        </a>
    </div>

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
              onsubmit="return confirm('「{{ $conference->name }}」を削除します。よろしいですか？');">
            @csrf
            @method('DELETE')
            <x-admin.button type="submit" variant="danger">削除する</x-admin.button>
        </form>
    </div>
@endsection
