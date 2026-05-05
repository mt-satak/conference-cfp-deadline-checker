<?php

use App\Application\Categories\GetCategoryUseCase;
use App\Application\Categories\UpdateCategoryUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryConflictException;
use App\Domain\Categories\CategoryNotFoundException;
use App\Http\Middleware\VerifyOrigin;

/**
 * /admin/categories/{id}/edit + PUT /admin/categories/{id} の Feature テスト。
 */
beforeEach(function () {
    test()->withoutVite();
    test()->withoutMiddleware(VerifyOrigin::class);
});

function makeEditCategorySample(string $id = 'id-1'): Category
{
    return new Category(
        categoryId: $id,
        name: 'PHP',
        slug: 'php',
        displayOrder: 100,
        axis: CategoryAxis::A,
        createdAt: '2026-01-01T00:00:00+09:00',
        updatedAt: '2026-01-01T00:00:00+09:00',
    );
}

it('GET /admin/categories/{id}/edit はフォーム + 既存値 + 削除セクションを返す', function () {
    // Given
    $get = Mockery::mock(GetCategoryUseCase::class);
    $get->shouldReceive('execute')->once()->with('id-1')->andReturn(makeEditCategorySample());
    app()->instance(GetCategoryUseCase::class, $get);

    // When
    $response = $this->get('/admin/categories/id-1/edit');

    // Then
    $response->assertStatus(200);
    $response->assertSee('カテゴリ編集', false);
    $response->assertSee('value="PHP"', false);
    $response->assertSee('value="php"', false);
    $response->assertSee('value="100"', false);
    $response->assertSee('削除する', false);
});

it('GET /admin/categories/{id}/edit は該当無しなら 404', function () {
    // Given
    $get = Mockery::mock(GetCategoryUseCase::class);
    $get->shouldReceive('execute')->once()->andThrow(CategoryNotFoundException::withId('missing'));
    app()->instance(GetCategoryUseCase::class, $get);

    // When
    $response = $this->get('/admin/categories/missing/edit');

    // Then
    $response->assertStatus(404);
});

it('PUT /admin/categories/{id} は成功時に index にリダイレクト + フラッシュ', function () {
    // Given
    $update = Mockery::mock(UpdateCategoryUseCase::class);
    $update->shouldReceive('execute')->once()->andReturn(makeEditCategorySample());
    app()->instance(UpdateCategoryUseCase::class, $update);

    // When
    $response = $this->put('/admin/categories/id-1', ['name' => 'PHP 8.5']);

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/categories');
    expect(session('status'))->toContain('PHP');
});

it('PUT /admin/categories/{id} は該当無しなら 404', function () {
    // Given
    $update = Mockery::mock(UpdateCategoryUseCase::class);
    $update->shouldReceive('execute')->once()->andThrow(CategoryNotFoundException::withId('missing'));
    app()->instance(UpdateCategoryUseCase::class, $update);

    // When
    $response = $this->put('/admin/categories/missing', ['name' => 'X']);

    // Then
    $response->assertStatus(404);
});

it('PUT /admin/categories/{id} は重複時に form に戻して errors flash', function () {
    // Given
    $update = Mockery::mock(UpdateCategoryUseCase::class);
    $update->shouldReceive('execute')
        ->once()
        ->andThrow(CategoryConflictException::nameAlreadyExists('Python'));
    app()->instance(UpdateCategoryUseCase::class, $update);

    // When
    $response = $this->put('/admin/categories/id-1', ['name' => 'Python']);

    // Then
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['conflict']);
});
