<?php

use App\Application\Categories\CreateCategoryUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryConflictException;
use App\Http\Middleware\VerifyOrigin;

beforeEach(function () {
    // 本テストは create endpoint のロジック検証が責務。Origin 検証は別テストで担保
    test()->withoutMiddleware(VerifyOrigin::class);
});

/**
 * POST /admin/api/categories (operationId: createCategory) の Feature テスト。
 *
 * OpenAPI 仕様 (data/openapi.yaml):
 *   - 201 Created: {"data": <Category>}
 *   - 409 CONFLICT: name / slug 重複
 *   - 422 VALIDATION_FAILED: バリデーション違反
 */
function validCreateCategoryPayload(): array
{
    return [
        'name' => 'PHP',
        'slug' => 'php',
        'displayOrder' => 100,
        'axis' => 'A',
    ];
}

it('正常な入力で 201 と data に作成された Category が返る', function () {
    // Given: UseCase が Category を返すモック
    $created = new Category(
        categoryId: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        name: 'PHP',
        slug: 'php',
        displayOrder: 100,
        axis: CategoryAxis::A,
        createdAt: '2026-05-04T10:00:00+09:00',
        updatedAt: '2026-05-04T10:00:00+09:00',
    );
    $useCase = Mockery::mock(CreateCategoryUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($created);
    app()->instance(CreateCategoryUseCase::class, $useCase);

    // When
    $response = $this->postJson('/admin/api/categories', validCreateCategoryPayload());

    // Then
    $response->assertStatus(201);
    $response->assertJsonPath('data.categoryId', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
    $response->assertJsonPath('data.name', 'PHP');
    $response->assertJsonPath('data.slug', 'php');
    $response->assertJsonPath('data.axis', 'A');
});

it('axis を省略しても 201 で作成できる (axis は optional)', function () {
    // Given
    $created = new Category(
        categoryId: 'id-1',
        name: 'X',
        slug: 'x',
        displayOrder: 1,
        axis: null,
        createdAt: '2026-05-04T10:00:00+09:00',
        updatedAt: '2026-05-04T10:00:00+09:00',
    );
    $useCase = Mockery::mock(CreateCategoryUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($created);
    app()->instance(CreateCategoryUseCase::class, $useCase);

    // When: axis 省略
    $response = $this->postJson('/admin/api/categories', [
        'name' => 'X',
        'slug' => 'x',
        'displayOrder' => 1,
    ]);

    // Then
    $response->assertStatus(201);
    expect($response->json('data.axis'))->toBeNull();
});

it('必須フィールド欠落で 422 + VALIDATION_FAILED', function () {
    // Given: name 欠落
    $response = $this->postJson('/admin/api/categories', [
        'slug' => 'php',
        'displayOrder' => 100,
    ]);

    // Then
    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('slug が pattern (lowercase + ハイフン + 数字) に違反すると 422', function () {
    // Given: slug が大文字混じり
    $response = $this->postJson('/admin/api/categories', [
        'name' => 'X',
        'slug' => 'PHP_8',
        'displayOrder' => 100,
    ]);

    // Then
    $response->assertStatus(422);
});

it('axis が enum 列挙外だと 422', function () {
    // Given: axis に無効値
    $response = $this->postJson('/admin/api/categories', [
        'name' => 'X',
        'slug' => 'x',
        'displayOrder' => 1,
        'axis' => 'Z',
    ]);

    // Then
    $response->assertStatus(422);
});

it('name 重複時に 409 + CONFLICT が返る', function () {
    // Given: UseCase が name 重複例外を投げる
    $useCase = Mockery::mock(CreateCategoryUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(CategoryConflictException::nameAlreadyExists('PHP'));
    app()->instance(CreateCategoryUseCase::class, $useCase);

    // When
    $response = $this->postJson('/admin/api/categories', validCreateCategoryPayload());

    // Then: 409 + CONFLICT (AdminApiExceptionRenderer が整形)
    $response->assertStatus(409);
    $response->assertJsonPath('error.code', 'CONFLICT');
});

it('slug 重複時に 409 + CONFLICT が返る', function () {
    // Given
    $useCase = Mockery::mock(CreateCategoryUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(CategoryConflictException::slugAlreadyExists('php'));
    app()->instance(CreateCategoryUseCase::class, $useCase);

    // When
    $response = $this->postJson('/admin/api/categories', validCreateCategoryPayload());

    // Then
    $response->assertStatus(409);
    $response->assertJsonPath('error.code', 'CONFLICT');
});
