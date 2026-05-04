<?php

use App\Application\Categories\UpdateCategoryUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryConflictException;
use App\Domain\Categories\CategoryNotFoundException;
use App\Http\Middleware\VerifyOrigin;

beforeEach(function () {
    test()->withoutMiddleware(VerifyOrigin::class);
});

/**
 * PUT /admin/api/categories/{id} (operationId: updateCategory) の Feature テスト。
 *
 *   - 200 OK: {"data": <Category>}
 *   - 404 NOT_FOUND
 *   - 409 CONFLICT: name / slug 重複
 *   - 422 VALIDATION_FAILED
 */

it('PUT /admin/api/categories/{id} は 200 と更新後 Category を返す', function () {
    // Given
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $updated = new Category(
        categoryId: $id,
        name: 'PHP 8.5',
        slug: 'php-85',
        displayOrder: 110,
        axis: CategoryAxis::A,
        createdAt: '2025-01-01T00:00:00+09:00',
        updatedAt: '2026-05-04T10:00:00+09:00',
    );
    $useCase = Mockery::mock(UpdateCategoryUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($updated);
    app()->instance(UpdateCategoryUseCase::class, $useCase);

    // When: 部分更新で name と displayOrder のみ送る
    $response = $this->putJson("/admin/api/categories/{$id}", [
        'name' => 'PHP 8.5',
        'displayOrder' => 110,
    ]);

    // Then
    $response->assertStatus(200);
    $response->assertJsonPath('data.categoryId', $id);
    $response->assertJsonPath('data.name', 'PHP 8.5');
    $response->assertJsonPath('data.displayOrder', 110);
});

it('PUT /admin/api/categories/{id} は該当無しなら 404 + NOT_FOUND', function () {
    // Given
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $useCase = Mockery::mock(UpdateCategoryUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(CategoryNotFoundException::withId($id));
    app()->instance(UpdateCategoryUseCase::class, $useCase);

    // When
    $response = $this->putJson("/admin/api/categories/{$id}", ['name' => 'X']);

    // Then
    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'NOT_FOUND');
});

it('PUT /admin/api/categories/{id} は name 重複で 409 + CONFLICT', function () {
    // Given
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $useCase = Mockery::mock(UpdateCategoryUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(CategoryConflictException::nameAlreadyExists('Python'));
    app()->instance(UpdateCategoryUseCase::class, $useCase);

    // When
    $response = $this->putJson("/admin/api/categories/{$id}", ['name' => 'Python']);

    // Then
    $response->assertStatus(409);
    $response->assertJsonPath('error.code', 'CONFLICT');
});

it('PUT /admin/api/categories/{id} は slug の pattern 違反で 422', function () {
    // Given: 大文字混じり slug
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $response = $this->putJson("/admin/api/categories/{$id}", [
        'slug' => 'BadSlug',
    ]);

    // Then
    $response->assertStatus(422);
});

it('PUT /admin/api/categories/{id} は axis に文字列を渡すと enum に変換して UseCase へ渡る', function () {
    // Given: UseCase に渡る $fields の axis が enum に変換されることを capture で検証
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $captured = null;
    $useCase = Mockery::mock(\App\Application\Categories\UpdateCategoryUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with($id, Mockery::on(function (array $fields) use (&$captured): bool {
            $captured = $fields;
            return true;
        }))
        ->andReturn(new \App\Domain\Categories\Category(
            categoryId: $id,
            name: 'PHP',
            slug: 'php',
            displayOrder: 100,
            axis: \App\Domain\Categories\CategoryAxis::B,
            createdAt: '2025-01-01T00:00:00+09:00',
            updatedAt: '2026-05-04T10:00:00+09:00',
        ));
    app()->instance(\App\Application\Categories\UpdateCategoryUseCase::class, $useCase);

    // When: axis="B"
    $response = $this->putJson("/admin/api/categories/{$id}", ['axis' => 'B']);

    // Then: 200 + UseCase に渡った axis が CategoryAxis::B
    $response->assertStatus(200);
    expect($captured)->toBeArray();
    /** @var array{axis?: mixed} $captured */
    expect($captured['axis'] ?? null)->toBe(\App\Domain\Categories\CategoryAxis::B);
});

it('PUT /admin/api/categories/{id} で axis を省略しても 200 (axis フィールドは UseCase に渡らない)', function () {
    // Given: axis 不在 → UseCase の $fields に axis キーが含まれないことを capture で検証
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $captured = null;
    $useCase = Mockery::mock(\App\Application\Categories\UpdateCategoryUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with($id, Mockery::on(function (array $fields) use (&$captured): bool {
            $captured = $fields;
            return ! array_key_exists('axis', $fields);
        }))
        ->andReturn(new \App\Domain\Categories\Category(
            categoryId: $id,
            name: 'PHP',
            slug: 'php',
            displayOrder: 100,
            axis: \App\Domain\Categories\CategoryAxis::A,
            createdAt: '2025-01-01T00:00:00+09:00',
            updatedAt: '2026-05-04T10:00:00+09:00',
        ));
    app()->instance(\App\Application\Categories\UpdateCategoryUseCase::class, $useCase);

    // When: axis 省略
    $response = $this->putJson("/admin/api/categories/{$id}", ['name' => 'PHP']);

    // Then: 200 + UseCase に渡った fields に axis が無い (= 既存維持セマンティクス)
    $response->assertStatus(200);
    expect($captured)->toBeArray();
    expect($captured)->not->toHaveKey('axis');
});
