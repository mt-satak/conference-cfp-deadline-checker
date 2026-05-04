<?php

use App\Domain\Conferences\ConferenceRepository;
use App\Infrastructure\DynamoDb\DynamoDbConferenceRepository;
use Aws\DynamoDb\DynamoDbClient;

/**
 * AppServiceProvider の DI 登録に関する Feature テスト。
 *
 * Domain 層 (interface) と Infrastructure 層 (実装) の紐付けが、
 * Laravel コンテナ経由で正しく解決されることを保証する。
 *
 * Controller / UseCase は ConferenceRepository に依存して書くため、
 * この紐付けが切れるとアプリ全体が動かなくなる。
 */
it('Container から DynamoDbClient を解決できる (シングルトン)', function () {
    // When: コンテナから DynamoDbClient を 2 回解決する
    $client1 = app(DynamoDbClient::class);
    $client2 = app(DynamoDbClient::class);

    // Then: 同一インスタンス (= シングルトン登録されている) が返る
    expect($client1)->toBeInstanceOf(DynamoDbClient::class);
    expect($client1)->toBe($client2);
});

it('Container から ConferenceRepository を解決すると DynamoDbConferenceRepository が返る', function () {
    // When: コンテナから interface 経由で Repository を解決する
    $repository = app(ConferenceRepository::class);

    // Then: DynamoDB 実装にバインドされている
    expect($repository)->toBeInstanceOf(DynamoDbConferenceRepository::class);
});
