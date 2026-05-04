<?php

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Infrastructure\DynamoDb\DynamoDbConferenceRepository;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Result;

/**
 * DynamoDbConferenceRepository のユニットテスト (DynamoDB Client モック使用)。
 *
 * 検証範囲:
 * - 各 Repository メソッドが期待する DynamoDB API (Scan / GetItem / PutItem /
 *   DeleteItem) を正しいパラメータで呼び出すこと
 * - 戻り値の DynamoDB AttributeValue 形式から Conference Entity への変換が
 *   正しく行われること
 * - save 時に TTL 属性 (cfpEndDate の翌日 00:00 JST UNIX timestamp) が
 *   付与されること
 *
 * 実 DynamoDB との結合動作確認は別途 Integration テストで行う。
 */

const TABLE_NAME = 'cfp-conferences';

/**
 * モック DynamoDB Client + Repository の組を生成するファクトリ。
 * Pest の $this->* パターンは IDE 静的解析と相性が悪いため利用しない。
 *
 * @return array{0: \Mockery\MockInterface, 1: DynamoDbConferenceRepository}
 */
function makeMockedRepo(): array
{
    $client = Mockery::mock(DynamoDbClient::class);
    $repository = new DynamoDbConferenceRepository($client, TABLE_NAME);

    return [$client, $repository];
}

function repoSampleConference(string $id = '550e8400-e29b-41d4-a716-446655440000'): Conference
{
    return new Conference(
        conferenceId: $id,
        name: 'PHPカンファレンス2026',
        trackName: '一般 CfP',
        officialUrl: 'https://phpcon.example.com/2026',
        cfpUrl: 'https://phpcon.example.com/2026/cfp',
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: '2026-05-01',
        cfpEndDate: '2026-07-15',
        categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: '国内最大規模のPHPカンファレンス。',
        themeColor: '#777BB4',
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
    );
}

function makeMarshalledItem(array $plain): array
{
    return (new Marshaler())->marshalItem($plain);
}

it('findAll は Scan API を呼び、結果を Conference[] に変換する', function () {
    // Given: DynamoDB からマーシャル済みアイテム 1 件が返るモック
    [$client, $repository] = makeMockedRepo();
    $items = [
        makeMarshalledItem([
            'conferenceId' => 'abc',
            'name' => 'A',
            'officialUrl' => 'https://a.example.com',
            'cfpUrl' => 'https://a.example.com/cfp',
            'eventStartDate' => '2026-09-19',
            'eventEndDate' => '2026-09-20',
            'venue' => '東京',
            'format' => 'offline',
            'cfpEndDate' => '2026-07-15',
            'categories' => ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
            'createdAt' => '2026-04-15T10:30:00+09:00',
            'updatedAt' => '2026-04-15T10:30:00+09:00',
        ]),
    ];
    $client->shouldReceive('scan')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['TableName'] === TABLE_NAME))
        ->andReturn(new Result(['Items' => $items]));

    // When: findAll を呼ぶ
    $result = $repository->findAll();

    // Then: Conference Entity 1 件が返り、必須フィールドと optional 欠落 (null) が反映される
    expect($result)->toHaveCount(1);
    expect($result[0])->toBeInstanceOf(Conference::class);
    expect($result[0]->conferenceId)->toBe('abc');
    expect($result[0]->name)->toBe('A');
    expect($result[0]->format)->toBe(ConferenceFormat::Offline);
    expect($result[0]->trackName)->toBeNull();
});

it('findAll は Items が空でも空配列を返す', function () {
    // Given: Items が空の Scan 結果を返すモック
    [$client, $repository] = makeMockedRepo();
    $client->shouldReceive('scan')->once()->andReturn(new Result(['Items' => []]));

    // When: findAll を呼ぶ
    $result = $repository->findAll();

    // Then: 空配列が返る
    expect($result)->toBe([]);
});

it('findById は GetItem API を呼んで Conference を返す', function () {
    // Given: 指定 ID で GetItem が呼ばれ、対応アイテムが返るモック
    [$client, $repository] = makeMockedRepo();
    $item = makeMarshalledItem([
        'conferenceId' => '550e8400-e29b-41d4-a716-446655440000',
        'name' => '取得テスト',
        'officialUrl' => 'https://test.example.com',
        'cfpUrl' => 'https://test.example.com/cfp',
        'eventStartDate' => '2026-09-19',
        'eventEndDate' => '2026-09-20',
        'venue' => '東京',
        'format' => 'online',
        'cfpEndDate' => '2026-07-15',
        'categories' => ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        'createdAt' => '2026-04-15T10:30:00+09:00',
        'updatedAt' => '2026-04-15T10:30:00+09:00',
    ]);
    $client->shouldReceive('getItem')
        ->once()
        ->with(Mockery::on(function ($args) {
            $key = (new Marshaler())->unmarshalItem($args['Key']);
            return $args['TableName'] === TABLE_NAME
                && $key === ['conferenceId' => '550e8400-e29b-41d4-a716-446655440000'];
        }))
        ->andReturn(new Result(['Item' => $item]));

    // When: findById で取得する
    $result = $repository->findById('550e8400-e29b-41d4-a716-446655440000');

    // Then: Conference に変換されたインスタンスが返る
    expect($result)->toBeInstanceOf(Conference::class);
    expect($result->conferenceId)->toBe('550e8400-e29b-41d4-a716-446655440000');
    expect($result->name)->toBe('取得テスト');
    expect($result->format)->toBe(ConferenceFormat::Online);
});

it('findById は Item が無ければ null を返す', function () {
    // Given: GetItem 結果に Item キーが無い (該当無し) モック
    [$client, $repository] = makeMockedRepo();
    $client->shouldReceive('getItem')->once()->andReturn(new Result([]));

    // When: findById で取得する
    $result = $repository->findById('missing');

    // Then: null が返る
    expect($result)->toBeNull();
});

it('save は PutItem API を呼ぶ', function () {
    // Given: PutItem 呼出時の引数を補足するモック
    [$client, $repository] = makeMockedRepo();
    $conference = repoSampleConference();
    $captured = null;
    $client->shouldReceive('putItem')
        ->once()
        ->with(Mockery::on(function ($args) use (&$captured) {
            $captured = $args;
            return $args['TableName'] === TABLE_NAME;
        }))
        ->andReturn(new Result([]));

    // When: save を呼ぶ
    $repository->save($conference);

    // Then: PutItem に渡された Item が Conference の値を含む
    /** @var array<string, mixed> $captured 静的解析向けの型 narrow (closure 内で代入される) */
    expect($captured)->not->toBeNull();
    $item = (new Marshaler())->unmarshalItem($captured['Item']);
    expect($item['conferenceId'])->toBe($conference->conferenceId);
    expect($item['name'])->toBe($conference->name);
    expect($item['format'])->toBe('offline');
});

it('save は cfpEndDate の翌日 00:00 JST を UNIX timestamp で ttl 属性として付与する', function () {
    // Given: cfpEndDate が 2026-07-15 の Conference + PutItem 引数を補足するモック
    [$client, $repository] = makeMockedRepo();
    $conference = repoSampleConference();
    $captured = null;
    $client->shouldReceive('putItem')
        ->once()
        ->with(Mockery::on(function ($args) use (&$captured) {
            $captured = $args;
            return true;
        }))
        ->andReturn(new Result([]));

    // When: save を呼ぶ
    $repository->save($conference);

    // Then: ttl が cfpEndDate 翌日 00:00 JST の UNIX timestamp と一致する
    /** @var array<string, mixed> $captured 静的解析向けの型 narrow */
    expect($captured)->not->toBeNull();
    $item = (new Marshaler())->unmarshalItem($captured['Item']);
    $expectedTtl = (new \DateTimeImmutable('2026-07-16 00:00:00', new \DateTimeZone('Asia/Tokyo')))
        ->getTimestamp();
    expect((int) $item['ttl'])->toBe($expectedTtl);
});

it('save は null フィールド (trackName 等) を DynamoDB アイテムに含めない', function () {
    // Given: optional フィールドが全て null の Conference + 引数を補足するモック
    [$client, $repository] = makeMockedRepo();
    $conference = new Conference(
        conferenceId: 'no-optional',
        name: '小規模',
        trackName: null,
        officialUrl: 'https://x.example.com',
        cfpUrl: 'https://x.example.com/cfp',
        eventStartDate: '2026-10-01',
        eventEndDate: '2026-10-01',
        venue: 'オンライン',
        format: ConferenceFormat::Online,
        cfpStartDate: null,
        cfpEndDate: '2026-08-01',
        categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: null,
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
    );
    $captured = null;
    $client->shouldReceive('putItem')
        ->once()
        ->with(Mockery::on(function ($args) use (&$captured) {
            $captured = $args;
            return true;
        }))
        ->andReturn(new Result([]));

    // When: save を呼ぶ
    $repository->save($conference);

    // Then: 保存アイテムに optional null フィールドのキー自体が存在しない
    /** @var array<string, mixed> $captured 静的解析向けの型 narrow */
    expect($captured)->not->toBeNull();
    $item = (new Marshaler())->unmarshalItem($captured['Item']);
    expect($item)->not->toHaveKey('trackName');
    expect($item)->not->toHaveKey('cfpStartDate');
    expect($item)->not->toHaveKey('description');
    expect($item)->not->toHaveKey('themeColor');
});

it('deleteById は DeleteItem を呼び、削除前 Attributes があれば true', function () {
    // Given: DeleteItem が削除前のアイテム (Attributes) を含む結果を返すモック
    [$client, $repository] = makeMockedRepo();
    $client->shouldReceive('deleteItem')
        ->once()
        ->with(Mockery::on(function ($args) {
            $key = (new Marshaler())->unmarshalItem($args['Key']);
            return $args['TableName'] === TABLE_NAME
                && $key === ['conferenceId' => 'target-id']
                && $args['ReturnValues'] === 'ALL_OLD';
        }))
        ->andReturn(new Result([
            'Attributes' => makeMarshalledItem(['conferenceId' => 'target-id', 'name' => '削除済']),
        ]));

    // When: deleteById を呼ぶ
    $result = $repository->deleteById('target-id');

    // Then: 削除成功で true が返る
    expect($result)->toBeTrue();
});

it('deleteById は対象が存在しない (Attributes 無し) なら false を返す', function () {
    // Given: DeleteItem が空 Result を返すモック (削除対象が存在しなかった)
    [$client, $repository] = makeMockedRepo();
    $client->shouldReceive('deleteItem')
        ->once()
        ->andReturn(new Result([]));

    // When: deleteById を呼ぶ
    $result = $repository->deleteById('missing-id');

    // Then: false が返る
    expect($result)->toBeFalse();
});

it('countByCategoryId は contains FilterExpression + Select=COUNT で件数を返す', function () {
    // Given: Scan が Count=3 を返すモック (3 件のカンファレンスが該当 categoryId を参照)
    [$client, $repository] = makeMockedRepo();
    $client->shouldReceive('scan')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            return $args['TableName'] === TABLE_NAME
                && $args['FilterExpression'] === 'contains(categories, :categoryId)'
                && $args['Select'] === 'COUNT';
        }))
        ->andReturn(new Result(['Count' => 3]));

    // When
    $count = $repository->countByCategoryId('cat-1');

    // Then
    expect($count)->toBe(3);
});

it('countByCategoryId は Count 属性が無ければ 0 を返す', function () {
    // Given
    [$client, $repository] = makeMockedRepo();
    $client->shouldReceive('scan')->once()->andReturn(new Result([]));

    // When/Then
    expect($repository->countByCategoryId('cat-x'))->toBe(0);
});
