<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * 管理画面のダッシュボード (Blade SSR)。
 *
 * 認証は Lambda@Edge Basic 認証で完結する想定 (architecture.md §6)。
 * Laravel 側ではユーザーを識別しないため、特別なミドルウェアは載せない。
 */
class HomeController extends Controller
{
    public function index(): View
    {
        return view('admin.home');
    }
}
