<?php

/**
 * アプリケーション起動時の基本的な動作確認 (Pest フィーチャーテスト)
 *
 * このテストの目的:
 *   - Laravel の起動・設定読み込みに失敗していないこと
 *   - 既定で公開されている Laravel 標準のルートが期待通り応答すること
 *
 * 個別エンドポイントの仕様は OpenAPI で定義済みで、別 Issue で実装・テストする。
 * 本テストはあくまで雛形としての健全性検証。
 */

it('ルートパス "/" で 200 を返す', function () {
    // When: ルートパスに GET する
    $response = $this->get('/');

    // Then: Laravel 13 のデフォルト welcome.blade.php (HTML) が返り 200
    // (後続フェーズで /admin プレフィックスにルーティングを移したら本テストは消える想定)
    $response->assertStatus(200);
});

it('Laravel 標準ヘルスチェックルート "/up" が 200 を返す', function () {
    // When: 標準ヘルスチェックパスに GET する
    // (bootstrap/app.php の withRouting(health: '/up') で有効化済み)
    $response = $this->get('/up');

    // Then: 200 が返り、フレームワークが正常起動していることが確認できる
    $response->assertStatus(200);
});

it('アプリケーション起動とサービスコンテナ解決が成功する', function () {
    // When: テストケースから Application インスタンスを取得する
    $app = $this->app;

    // Then: サービスコンテナが組み上がり、想定のバージョン・環境で動作している
    expect($app)->not->toBeNull();
    expect($app->version())->toStartWith('13.');
    expect($app->environment())->toBe('testing');
});
