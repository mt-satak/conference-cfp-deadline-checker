<?php

use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Infrastructure\DynamoDb\DynamoDbCategoryRepository;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Result;

/**
 * DynamoDbCategoryRepository のユニットテスト (DynamoDB Client モック使用)。
 *
 * 検証範囲:
 * - 各メソッドが期待する DynamoDB API (Scan / GetItem / PutItem / DeleteItem) を
 *   正しいパラメータで呼び出す
 * - AttributeValue 形式 ↔ Category Entity の相互変換
 *
 * 実 DynamoDB との結合は Integration テスト側で検証。
 */

const CATEGORIES_TABLE_NAME = 'cfp-categories';

/**
 * @return array{0: \Mockery\MockInterface, 1: DynamoDbCategoryRepository}
 */
function makeMockedCategoryRepo(): array
{
    $client = Mockery::mock(DynamoDbClient::class);
    $repository = new DynamoDbCategoryRepository($client, CATEGORIES_TABLE_NAME);

    return [$client, $repository];
}

function categoryMarshalled(array $plain): array
{
    return (new Marshaler())->marshalItem($plain);
}

function sampleCategoryItem(string $id = 'id-1'): array
{
    return [
        'categoryId' => $id,
        'name' => 'PHP',
        'slug' => 'php',
        'displayOrder' => 100,
        'axis' => 'A',
        'createdAt' => '2026-01-01T00:00:00+09:00',
        'updatedAt' => '2026-01-01T00:00:00+09:00',
    ];
}

it('findAll は Scan API を呼び、結果を Category[] に変換する', function () {
    // Given: 2 件の Item が返る Scan モック
    [$client, $repository] = makeMockedCategoryRepo();
    $items = [
        categoryMarshalled(sampleCategoryItem('id-1')),
        categoryMarshalled([
            'categoryId' => 'id-2',
            'name' => 'Python',
            'slug' => 'python',
            'displayOrder' => 200,
            'createdAt' => '2026-01-01T00:00:00+09:00',
            'updatedAt' => '2026-01-01T00:00:00+09:00',
            // axis は欠落 (optional)
        ]),
    ];
    $client->shouldReceive('scan')
        ->once()
        ->with(['TableName' => CATEGORIES_TABLE_NAME])
        ->andReturn(new Result(['Items' => $items]));

    // When
    $result = $repository->findAll();

    // Then
    expect($result)->toHaveCount(2);
    expect($result[0])->toBeInstanceOf(Category::class);
    expect($result[0]->categoryId)->toBe('id-1');
    expect($result[0]->axis)->toBe(CategoryAxis::A);
    expect($result[1]->axis)->toBeNull();
});

it('findAll で Items が空の場合は空配列を返す', function () {
    // Given
    [$client, $repository] = makeMockedCategoryRepo();
    $client->shouldReceive('scan')->once()->andReturn(new Result([]));

    // When
    $result = $repository->findAll();

    // Then
    expect($result)->toBe([]);
});

it('findById は GetItem API を呼び、見つかれば Category を返す', function () {
    // Given
    [$client, $repository] = makeMockedCategoryRepo();
    $client->shouldReceive('getItem')
        ->once()
        ->andReturn(new Result(['Item' => categoryMarshalled(sampleCategoryItem('id-1'))]));

    // When
    $result = $repository->findById('id-1');

    // Then
    expect($result)->toBeInstanceOf(Category::class);
    expect($result->categoryId)->toBe('id-1');
});

it('findById で Item が無ければ null を返す', function () {
    // Given
    [$client, $repository] = makeMockedCategoryRepo();
    $client->shouldReceive('getItem')->once()->andReturn(new Result([]));

    // When/Then
    expect($repository->findById('missing'))->toBeNull();
});

it('findByName は name 一致の Scan FilterExpression を発行する', function () {
    // Given
    [$client, $repository] = makeMockedCategoryRepo();
    $client->shouldReceive('scan')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            return $args['TableName'] === CATEGORIES_TABLE_NAME
                && $args['FilterExpression'] === '#attr = :val'
                && $args['ExpressionAttributeNames'] === ['#attr' => 'name']
                && $args['Limit'] === 1;
        }))
        ->andReturn(new Result(['Items' => [categoryMarshalled(sampleCategoryItem())]]));

    // When
    $result = $repository->findByName('PHP');

    // Then
    expect($result)->toBeInstanceOf(Category::class);
    expect($result->name)->toBe('PHP');
});

it('findByName で見つからなければ null', function () {
    // Given
    [$client, $repository] = makeMockedCategoryRepo();
    $client->shouldReceive('scan')->once()->andReturn(new Result(['Items' => []]));

    // When/Then
    expect($repository->findByName('未登録'))->toBeNull();
});

it('findBySlug は slug 一致の Scan FilterExpression を発行する', function () {
    // Given
    [$client, $repository] = makeMockedCategoryRepo();
    $client->shouldReceive('scan')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            return $args['ExpressionAttributeNames'] === ['#attr' => 'slug'];
        }))
        ->andReturn(new Result(['Items' => [categoryMarshalled(sampleCategoryItem())]]));

    // When
    $result = $repository->findBySlug('php');

    // Then
    expect($result)->toBeInstanceOf(Category::class);
});

it('save は PutItem API を呼び、axis を含めて永続化する', function () {
    // Given
    [$client, $repository] = makeMockedCategoryRepo();
    $client->shouldReceive('putItem')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            $unmarshaled = (new Marshaler())->unmarshalItem($args['Item']);

            return $args['TableName'] === CATEGORIES_TABLE_NAME
                && $unmarshaled['categoryId'] === 'id-1'
                && $unmarshaled['name'] === 'PHP'
                && $unmarshaled['axis'] === 'A';
        }));

    // When
    $repository->save(new Category(
        categoryId: 'id-1',
        name: 'PHP',
        slug: 'php',
        displayOrder: 100,
        axis: CategoryAxis::A,
        createdAt: '2026-01-01T00:00:00+09:00',
        updatedAt: '2026-01-01T00:00:00+09:00',
    ));

    // Then: putItem が期待通り呼ばれた (Mockery が検証)
    expect(true)->toBeTrue();
});

it('save で axis が null の場合は axis 属性を付けない', function () {
    // Given
    [$client, $repository] = makeMockedCategoryRepo();
    $client->shouldReceive('putItem')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            $unmarshaled = (new Marshaler())->unmarshalItem($args['Item']);

            return ! array_key_exists('axis', $unmarshaled);
        }));

    // When
    $repository->save(new Category('id-x', 'X', 'x', 1, null, '2026-01-01T00:00:00+09:00', '2026-01-01T00:00:00+09:00'));

    // Then
    expect(true)->toBeTrue();
});

it('deleteById は DeleteItem API を呼び、ALL_OLD で削除実績を返す', function () {
    // Given: 削除前 Item が返るモック
    [$client, $repository] = makeMockedCategoryRepo();
    $client->shouldReceive('deleteItem')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            return $args['TableName'] === CATEGORIES_TABLE_NAME
                && $args['ReturnValues'] === 'ALL_OLD';
        }))
        ->andReturn(new Result(['Attributes' => categoryMarshalled(sampleCategoryItem())]));

    // When
    $result = $repository->deleteById('id-1');

    // Then
    expect($result)->toBeTrue();
});

it('deleteById で削除前 Attributes が無い (= 対象不在) 場合は false', function () {
    // Given
    [$client, $repository] = makeMockedCategoryRepo();
    $client->shouldReceive('deleteItem')->once()->andReturn(new Result([]));

    // When/Then
    expect($repository->deleteById('missing'))->toBeFalse();
});
