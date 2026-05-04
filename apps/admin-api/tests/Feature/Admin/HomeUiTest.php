<?php

/**
 * /admin (ダッシュボード) の Blade SSR Feature テスト。
 */
beforeEach(function () {
    // Vite は test 時には manifest 不在で例外を投げるためダミー化する。
    // (本番ビルドで vite build を回せば manifest 解決される)
    test()->withoutVite();
});

it('GET /admin はダッシュボードを 200 で返す', function () {
    // When
    $response = $this->get('/admin');

    // Then
    $response->assertStatus(200);
    $response->assertSee('ダッシュボード', false);
    $response->assertSee('カンファレンス', false);
});

it('GET /admin はナビゲーションでダッシュボード項目をアクティブ表示する', function () {
    // Given/When
    $response = $this->get('/admin');

    // Then: ナビ内のダッシュボード項目に強調クラス (font-semibold text-blue-700) が
    // 当たっていること (request()->routeIs('admin.home') == true 経路の検証)
    $response->assertStatus(200);
    expect($response->getContent())->toContain('font-semibold text-blue-700');
});

it('GET /admin は CSRF トークン meta タグを含む', function () {
    // Given/When
    $response = $this->get('/admin');

    // Then
    $response->assertStatus(200);
    $response->assertSee('name="csrf-token"', false);
});
