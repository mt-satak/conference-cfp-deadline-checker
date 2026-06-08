<?php

declare(strict_types=1);

use App\Domain\CfpSources\CfpSource;
use App\Infrastructure\DynamoDb\DynamoDbCfpSourceRepository;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Result;
use Mockery\MockInterface;

/**
 * DynamoDbCfpSourceRepository の単体テスト (Issue #200 PR-1)。
 *
 * Scan / GetItem / PutItem / DeleteItem の各経路を Mockery で検証する。
 * findByUrl は OfficialUrl::normalize を介した表記揺れ吸収を確認する。
 */
const SOURCES_TABLE = 'cfp-sources';

/**
 * @return array{0: MockInterface, 1: DynamoDbCfpSourceRepository}
 */
function makeSourceRepoMock(): array
{
    $client = Mockery::mock(DynamoDbClient::class);
    $repo = new DynamoDbCfpSourceRepository($client, SOURCES_TABLE);

    return [$client, $repo];
}

function sourceItem(string $sourceId, string $name, string $url, bool $enabled): array
{
    return (new Marshaler)->marshalItem([
        'sourceId' => $sourceId,
        'name' => $name,
        'url' => $url,
        'enabled' => $enabled,
        'createdAt' => '2026-05-15T09:00:00+09:00',
        'updatedAt' => '2026-05-15T09:00:00+09:00',
    ]);
}

it('findAll は Scan API を呼び CfpSource[] に変換する', function () {
    // Given
    [$client, $repo] = makeSourceRepoMock();
    $items = [
        sourceItem('s-1', 'fortee', 'https://fortee.jp/events', true),
        sourceItem('s-2', 'connpass', 'https://connpass.com/explore?keyword=tech', false),
    ];
    $client->shouldReceive('scan')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['TableName'] === SOURCES_TABLE))
        ->andReturn(new Result(['Items' => $items]));

    // When
    $result = $repo->findAll();

    // Then
    expect($result)->toHaveCount(2);
    expect($result[0])->toBeInstanceOf(CfpSource::class);
    expect($result[0]->sourceId)->toBe('s-1');
    expect($result[0]->enabled)->toBeTrue();
    expect($result[1]->enabled)->toBeFalse();
});

it('findAll は Items 空でも空配列を返す', function () {
    // Given
    [$client, $repo] = makeSourceRepoMock();
    $client->shouldReceive('scan')->once()->andReturn(new Result(['Items' => []]));

    // When/Then
    expect($repo->findAll())->toBe([]);
});

it('findById は GetItem API を呼んで CfpSource を返す', function () {
    // Given
    [$client, $repo] = makeSourceRepoMock();
    $item = sourceItem('s-1', 'fortee', 'https://fortee.jp/events', true);
    $client->shouldReceive('getItem')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['TableName'] === SOURCES_TABLE))
        ->andReturn(new Result(['Item' => $item]));

    // When
    $result = $repo->findById('s-1');

    // Then
    expect($result)->toBeInstanceOf(CfpSource::class);
    /** @var CfpSource $result */
    expect($result->sourceId)->toBe('s-1');
    expect($result->name)->toBe('fortee');
});

it('findById は対象無しなら null を返す', function () {
    // Given
    [$client, $repo] = makeSourceRepoMock();
    $client->shouldReceive('getItem')->once()->andReturn(new Result([]));

    // When/Then
    expect($repo->findById('missing'))->toBeNull();
});

it('findByUrl は OfficialUrl::normalize で URL 表記揺れを吸収して一致判定する', function () {
    // Given: 登録 URL は https://fortee.jp/events、検索 URL は http://www.fortee.jp/events/ (= 同一視されるべき)
    [$client, $repo] = makeSourceRepoMock();
    $items = [
        sourceItem('s-1', 'fortee', 'https://fortee.jp/events', true),
    ];
    $client->shouldReceive('scan')->once()->andReturn(new Result(['Items' => $items]));

    // When: 表記揺れ URL で検索
    $result = $repo->findByUrl('http://www.fortee.jp/events/');

    // Then: 同一視されて 1 件返る
    expect($result)->toBeInstanceOf(CfpSource::class);
    /** @var CfpSource $result */
    expect($result->sourceId)->toBe('s-1');
});

it('findByUrl は該当無しなら null を返す', function () {
    // Given
    [$client, $repo] = makeSourceRepoMock();
    $items = [
        sourceItem('s-1', 'fortee', 'https://fortee.jp/events', true),
    ];
    $client->shouldReceive('scan')->once()->andReturn(new Result(['Items' => $items]));

    // When/Then
    expect($repo->findByUrl('https://other.example.com/'))->toBeNull();
});

it('save は PutItem API を呼んで全フィールド (enabled 含む) を書く', function () {
    // Given
    [$client, $repo] = makeSourceRepoMock();
    $source = new CfpSource(
        sourceId: 's-1',
        name: 'fortee',
        url: 'https://fortee.jp/events',
        enabled: true,
        createdAt: '2026-05-15T09:00:00+09:00',
        updatedAt: '2026-05-15T09:00:00+09:00',
    );
    $captured = null;
    $client->shouldReceive('putItem')
        ->once()
        ->with(Mockery::on(function ($args) use (&$captured) {
            $captured = $args;

            return $args['TableName'] === SOURCES_TABLE;
        }))
        ->andReturn(new Result([]));

    // When
    $repo->save($source);

    // Then
    /** @var array<string, mixed> $captured */
    expect($captured)->not->toBeNull();
    $item = (new Marshaler)->unmarshalItem($captured['Item']);
    expect($item['sourceId'])->toBe('s-1');
    expect($item['name'])->toBe('fortee');
    expect($item['url'])->toBe('https://fortee.jp/events');
    expect($item['enabled'])->toBeTrue();
});

it('deleteById は DeleteItem API を呼ぶ。削除実行で true、対象無しで false', function () {
    // Given: 削除成功
    [$client, $repo] = makeSourceRepoMock();
    $client->shouldReceive('deleteItem')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['ReturnValues'] === 'ALL_OLD'))
        ->andReturn(new Result(['Attributes' => sourceItem('s-1', 'x', 'https://x.example.com/', true)]));

    // When/Then
    expect($repo->deleteById('s-1'))->toBeTrue();
});

it('deleteById は対象無しなら false (Attributes 不在)', function () {
    // Given
    [$client, $repo] = makeSourceRepoMock();
    $client->shouldReceive('deleteItem')->once()->andReturn(new Result([]));

    // When/Then
    expect($repo->deleteById('missing'))->toBeFalse();
});

it('findById で enabled 属性欠落 (= レガシー / 未設定) なら true 扱いで復元 (= 通常巡回対象)', function () {
    // Given: enabled キーが無い古い item
    [$client, $repo] = makeSourceRepoMock();
    $item = (new Marshaler)->marshalItem([
        'sourceId' => 'legacy',
        'name' => 'Legacy',
        'url' => 'https://legacy.example.com/',
        'createdAt' => '2026-05-15T09:00:00+09:00',
        'updatedAt' => '2026-05-15T09:00:00+09:00',
    ]);
    $client->shouldReceive('getItem')->once()->andReturn(new Result(['Item' => $item]));

    // When
    $result = $repo->findById('legacy');

    // Then
    expect($result)->toBeInstanceOf(CfpSource::class);
    /** @var CfpSource $result */
    expect($result->enabled)->toBeTrue();
});
