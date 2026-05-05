<?php

use App\Application\Categories\CreateCategoryInput;
use App\Application\Categories\CreateCategoryUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryConflictException;
use App\Http\Middleware\VerifyOrigin;

/**
 * /admin/categories/{create,store} の Feature テスト。
 */
beforeEach(function () {
    test()->withoutVite();
    test()->withoutMiddleware(VerifyOrigin::class);
});

it('GET /admin/categories/create はフォームを 200 で返す', function () {
    // When
    $response = $this->get('/admin/categories/create');

    // Then
    $response->assertStatus(200);
    $response->assertSee('カテゴリ新規作成', false);
    $response->assertSee('name="name"', false);
    $response->assertSee('name="slug"', false);
    $response->assertSee('name="displayOrder"', false);
    $response->assertSee('name="axis"', false);
});

it('POST /admin/categories は成功時に index にリダイレクト + フラッシュ', function () {
    // Given
    $useCase = Mockery::mock(CreateCategoryUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with(Mockery::type(CreateCategoryInput::class))
        ->andReturn(new Category(
            categoryId: 'aaa',
            name: 'PHP',
            slug: 'php',
            displayOrder: 100,
            axis: CategoryAxis::A,
            createdAt: '2026-05-04T10:00:00+09:00',
            updatedAt: '2026-05-04T10:00:00+09:00',
        ));
    app()->instance(CreateCategoryUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/categories', [
        'name' => 'PHP',
        'slug' => 'php',
        'displayOrder' => 100,
        'axis' => 'A',
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/categories');
    expect(session('status'))->toContain('PHP');
});

it('POST /admin/categories は name 重複時に form に戻して errors flash', function () {
    // Given
    $useCase = Mockery::mock(CreateCategoryUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(CategoryConflictException::nameAlreadyExists('PHP'));
    app()->instance(CreateCategoryUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/categories', [
        'name' => 'PHP',
        'slug' => 'php',
        'displayOrder' => 100,
    ]);

    // Then: 直前のページ (referer) に戻る + conflict キーで errors
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['conflict']);
});

it('POST /admin/categories はバリデーション違反時に errors flash', function () {
    // Given: 必須欠落 + slug pattern 違反
    // When
    $response = $this->post('/admin/categories', [
        'name' => '',
        'slug' => 'BadSlug',  // 大文字混じり
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['name', 'slug', 'displayOrder']);
});
