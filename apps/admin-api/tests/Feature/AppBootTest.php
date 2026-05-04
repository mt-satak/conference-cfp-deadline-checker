<?php

/**
 * アプリケーション起動時の基本的な動作確認 (Pest フィーチャーテスト)
 *
 * このテストの目的:
 *   - Laravel の起動・設定読み込みに失敗していないこと
 *   - 既定で公開されている Laravel 標準のルートが期待通り応答すること
 *
 * 個別エンドポイント (/admin/api/health 等) は OpenAPI 仕様で定義しており、
 * 後続 Issue で実装・テストする。本テストはあくまで雛形としての健全性検証。
 */

it('ルートパス "/" で 200 を返す', function () {
    $response = $this->get('/');

    // Laravel 13 のデフォルトでは welcome.blade.php (HTML) を返す。
    // 後続フェーズで /admin プレフィックスにルーティングを移したらこのテストは消える想定。
    $response->assertStatus(200);
});

it('Laravel 標準ヘルスチェックルート "/up" が 200 を返す', function () {
    // bootstrap/app.php の withRouting(health: '/up') で有効化されている
    // Laravel 標準のヘルスチェックルート。フレームワーク自体の起動状況を確認する。
    $response = $this->get('/up');

    $response->assertStatus(200);
});

it('アプリケーション起動とサービスコンテナ解決が成功する', function () {
    // サービスコンテナが正常に組み上がっていることを Application インスタンスの取得で確認。
    // 設定ファイルが読み込めていない / プロバイダが落ちている等があればここで例外になる。
    $app = $this->app;

    expect($app)->not->toBeNull();
    expect($app->version())->toStartWith('13.');
    expect($app->environment())->toBe('testing');
});
