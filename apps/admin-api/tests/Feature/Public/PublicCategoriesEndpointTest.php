<?php

use App\Application\Categories\ListCategoriesUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;

/**
 * GET /api/public/categories の Feature テスト (Issue #95 / Phase 4.4)。
 *
 * 公開フロント (Astro) のビルド時に Conference.categories (UUID v4 配列) を
 * slug に解決するためのソース。
 *  - 認証なし、CloudFrontSecretMiddleware で直アクセス防御
 *  - displayOrder 昇順 (= ListCategoriesUseCase のデフォルト)
 *  - レスポンス shape は admin/api と同じ {data: [...], meta: {count}}
 */
function publicCategorySample(string $id, string $name, string $slug, int $order): Category
{
    return new Category(
        categoryId: $id,
        name: $name,
        slug: $slug,
        displayOrder: $order,
        axis: CategoryAxis::A,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
    );
}

it('GET /api/public/categories は 200 と data 配列 + meta.count を返す', function () {
    // Given
    $categories = [
        publicCategorySample('1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02', 'PHP', 'php', 100),
        publicCategorySample('2e5a3b94-7c59-4a2d-8d9b-8f3c4e5a6b03', 'Web', 'web', 200),
    ];
    $useCase = Mockery::mock(ListCategoriesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($categories);
    app()->instance(ListCategoriesUseCase::class, $useCase);

    // When
    $response = $this->getJson('/api/public/categories');

    // Then
    $response->assertStatus(200);
    $response->assertJsonPath('data.0.categoryId', '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02');
    $response->assertJsonPath('data.0.slug', 'php');
    $response->assertJsonPath('data.0.name', 'PHP');
    $response->assertJsonPath('data.1.slug', 'web');
    $response->assertJsonPath('meta.count', 2);
});

it('レスポンスの各 Category は CategoryPresenter の shape (categoryId / slug / name / displayOrder)', function () {
    // Given
    $category = publicCategorySample('1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02', 'PHP', 'php', 100);
    $useCase = Mockery::mock(ListCategoriesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([$category]);
    app()->instance(ListCategoriesUseCase::class, $useCase);

    // When
    $response = $this->getJson('/api/public/categories');

    // Then
    $response->assertStatus(200);
    $response->assertJsonPath('data.0.categoryId', '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02');
    $response->assertJsonPath('data.0.slug', 'php');
    $response->assertJsonPath('data.0.name', 'PHP');
    $response->assertJsonPath('data.0.displayOrder', 100);
    $response->assertJsonPath('data.0.axis', 'A');
});

it('UseCase が空配列を返す場合は data: [] と meta.count: 0 を返す', function () {
    // Given
    $useCase = Mockery::mock(ListCategoriesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([]);
    app()->instance(ListCategoriesUseCase::class, $useCase);

    // When
    $response = $this->getJson('/api/public/categories');

    // Then
    $response->assertStatus(200);
    $response->assertJsonPath('data', []);
    $response->assertJsonPath('meta.count', 0);
});

it('CloudFrontSecretMiddleware が掛かっており、Custom Origin Header 不一致時は 403', function () {
    // Given
    config(['cloudfront.origin_secret' => 'expected-secret-value']);

    // When
    $response = $this->withHeaders([
        'X-CloudFront-Secret' => 'wrong-secret',
    ])->getJson('/api/public/categories');

    // Then
    $response->assertStatus(403);
});
