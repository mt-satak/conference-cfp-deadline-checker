<?php

use Aws\DynamoDb\DynamoDbClient;

/**
 * AWS SDK が読み込み可能で、DynamoDB クライアントを正常に組み立てられることを検証する。
 *
 * 後続 Issue で Repository 層から DynamoDB クライアントを利用するため、
 * 雛形段階で SDK が依存解決できているかを早期に確認しておく。
 */

it('AWS DynamoDB クライアントクラスが解決できる', function () {
    expect(class_exists(DynamoDbClient::class))->toBeTrue();
});

it('DynamoDB クライアントを認証情報・エンドポイント指定で生成できる', function () {
    // ローカル開発で利用する DynamoDB Local 向けの接続例。
    // 本コミットでは「コンストラクタが例外を投げないこと」だけを確認する。
    // 実際の通信検証は Repository 実装時の結合テストで行う。
    $client = new DynamoDbClient([
        'version' => 'latest',
        'region' => 'ap-northeast-1',
        'endpoint' => 'http://localhost:8000',
        'credentials' => [
            'key' => 'dummy',
            'secret' => 'dummy',
        ],
    ]);

    expect($client)->toBeInstanceOf(DynamoDbClient::class);
    expect($client->getRegion())->toBe('ap-northeast-1');
});
