@extends('admin.layouts.app')

@section('title', 'カテゴリ新規作成')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">カテゴリ新規作成</h1>
        <a href="{{ route('admin.categories.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
            ← 一覧へ戻る
        </a>
    </div>

    <x-admin.card class="p-6">
        @include('admin.categories._form', [
            'category' => null,
            'action' => route('admin.categories.store'),
            'method' => 'POST',
            'submitLabel' => '作成する',
        ])
    </x-admin.card>
@endsection
