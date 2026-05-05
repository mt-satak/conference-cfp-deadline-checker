@extends('admin.layouts.app')

@section('title', 'カンファレンス新規作成')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">カンファレンス新規作成</h1>
        <a href="{{ route('admin.conferences.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
            ← 一覧へ戻る
        </a>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-6">
        @include('admin.conferences._form', [
            'conference' => null,
            'action' => route('admin.conferences.store'),
            'method' => 'POST',
            'submitLabel' => '作成する',
        ])
    </div>
@endsection
