<?php

use Aws\DynamoDb\DynamoDbClient;

/**
 * AWS SDK が読み込み可能で、DynamoDB クライアントを正常に組み立てられることを検証する。
 *
 * 後続 Issue で Repository 層から DynamoDB クライアントを利用するため、
 * 雛形段階で SDK が依存解決できているかを早期に確認しておく。
 */

it('AWS DynamoDB クライアントクラスが解決できる', function () {
    // When / Then: クラスがオートロードできる
    expect(class_exists(DynamoDbClient::class))->toBeTrue();
});

it('DynamoDB クライアントを認証情報・エンドポイント指定で生成できる', function () {
    // Given: ローカル開発 (DynamoDB Local) を想定したダミー認証情報・エンドポイント

    // When: DynamoDbClient を構築する
    $client = new DynamoDbClient([
        'version' => 'latest',
        'region' => 'ap-northeast-1',
        'endpoint' => 'http://localhost:8000',
        'credentials' => [
            'key' => 'dummy',
            'secret' => 'dummy',
        ],
    ]);

    // Then: 例外を投げず、指定したリージョンで DynamoDbClient が組み上がる
    // (実際の通信検証は Integration テストで担保)
    expect($client)->toBeInstanceOf(DynamoDbClient::class);
    expect($client->getRegion())->toBe('ap-northeast-1');
});
