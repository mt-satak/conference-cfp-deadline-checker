<?php

use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Infrastructure\DynamoDb\DynamoDbCategoryRepository;
use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Support\Str;

/**
 * DynamoDbCategoryRepository の DynamoDB Local との結合テスト。
 *
 * 前提:
 *   - `pnpm db:up` で DynamoDB Local が localhost:8000 で起動中
 *   - `pnpm db:init` で cfp-categories テーブルが作成済
 *
 * 起動していない環境ではスキップする。
 * テストデータは UUID で隔離し、各テスト終了時に明示的に削除する。
 */
const CATEGORIES_DDB_ENDPOINT = 'http://localhost:8000';
const CATEGORIES_DDB_REGION = 'ap-northeast-1';
const CATEGORIES_DDB_TABLE = 'cfp-categories';

function categoriesDynamoDbLocalClient(): DynamoDbClient
{
    return new DynamoDbClient([
        'version' => 'latest',
        'region' => CATEGORIES_DDB_REGION,
        'endpoint' => CATEGORIES_DDB_ENDPOINT,
        'credentials' => ['key' => 'dummy', 'secret' => 'dummy'],
        'http' => ['connect_timeout' => 1, 'timeout' => 3],
    ]);
}

function skipIfCategoriesDynamoDbUnavailable(DynamoDbClient $client): void
{
    try {
        $client->listTables();
    } catch (Throwable) {
        test()->markTestSkipped('DynamoDB Local が起動していません (pnpm db:up を実行してください)');
    }
}

it('save → findById → findByName / findBySlug → deleteById の往復が DynamoDB Local で機能する', function () {
    // Given
    $client = categoriesDynamoDbLocalClient();
    skipIfCategoriesDynamoDbUnavailable($client);
    $repository = new DynamoDbCategoryRepository($client, CATEGORIES_DDB_TABLE);

    $id = (string) Str::uuid();
    $unique = substr($id, 0, 8);
    $category = new Category(
        categoryId: $id,
        name: "IT-{$unique}",
        slug: "it-{$unique}",
        displayOrder: 9999,
        axis: CategoryAxis::B,
        createdAt: '2026-05-04T10:00:00+09:00',
        updatedAt: '2026-05-04T10:00:00+09:00',
    );

    try {
        // When: 保存
        $repository->save($category);

        // Then: findById で読み戻せる
        $loaded = $repository->findById($id);
        expect($loaded)->not->toBeNull();
        expect($loaded->name)->toBe("IT-{$unique}");
        expect($loaded->axis)->toBe(CategoryAxis::B);

        // findByName / findBySlug でも取得できる
        $byName = $repository->findByName("IT-{$unique}");
        expect($byName?->categoryId)->toBe($id);

        $bySlug = $repository->findBySlug("it-{$unique}");
        expect($bySlug?->categoryId)->toBe($id);

        // 削除して結果が true、再読み込みで null
        expect($repository->deleteById($id))->toBeTrue();
        expect($repository->findById($id))->toBeNull();
    } finally {
        // テスト失敗時のクリーンアップ
        try {
            $repository->deleteById($id);
        } catch (Throwable) {
            // best-effort
        }
    }
});
