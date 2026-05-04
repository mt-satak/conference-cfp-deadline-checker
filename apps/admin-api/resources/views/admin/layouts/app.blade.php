<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- CSRF token: form 送信 / fetch 時に X-XSRF-TOKEN ヘッダ で送る --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CFP 管理画面')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
    <header class="border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
            <a href="{{ route('admin.home') }}" class="text-lg font-semibold text-gray-900">
                CFP 管理画面
            </a>
            <nav class="flex gap-4 text-sm">
                <a href="{{ route('admin.home') }}"
                   class="{{ request()->routeIs('admin.home') ? 'font-semibold text-blue-700' : 'text-gray-600 hover:text-gray-900' }}">
                    ダッシュボード
                </a>
                <a href="{{ route('admin.conferences.index') }}"
                   class="{{ request()->routeIs('admin.conferences.*') ? 'font-semibold text-blue-700' : 'text-gray-600 hover:text-gray-900' }}">
                    カンファレンス
                </a>
                {{-- Categories / Build は後続 PR で追加 --}}
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
        {{-- フラッシュメッセージ枠 (CRUD 成功時に使用予定) --}}
        @if (session('status'))
            <div class="mb-4 rounded border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="mx-auto max-w-6xl px-4 py-6 text-xs text-gray-500">
        Conference CfP Deadline Checker — admin
    </footer>
</body>
</html>
