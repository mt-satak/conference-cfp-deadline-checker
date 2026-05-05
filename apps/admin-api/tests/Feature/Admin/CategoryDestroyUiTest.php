<?php

use App\Application\Categories\DeleteCategoryUseCase;
use App\Domain\Categories\CategoryConflictException;
use App\Domain\Categories\CategoryNotFoundException;
use App\Http\Middleware\VerifyOrigin;

/**
 * DELETE /admin/categories/{id} の Feature テスト。
 */
beforeEach(function () {
    test()->withoutVite();
    test()->withoutMiddleware(VerifyOrigin::class);
});

it('DELETE /admin/categories/{id} は成功時に index にリダイレクト + フラッシュ', function () {
    // Given
    $useCase = Mockery::mock(DeleteCategoryUseCase::class);
    $useCase->shouldReceive('execute')->once()->with('id-1');
    app()->instance(DeleteCategoryUseCase::class, $useCase);

    // When
    $response = $this->delete('/admin/categories/id-1');

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/categories');
    $response->assertSessionHas('status');
});

it('DELETE /admin/categories/{id} は該当無しなら 404', function () {
    // Given
    $useCase = Mockery::mock(DeleteCategoryUseCase::class);
    $useCase->shouldReceive('execute')->once()->andThrow(CategoryNotFoundException::withId('missing'));
    app()->instance(DeleteCategoryUseCase::class, $useCase);

    // When
    $response = $this->delete('/admin/categories/missing');

    // Then
    $response->assertStatus(404);
});

it('DELETE /admin/categories/{id} は参照中 Conference 存在で index に戻して error flash', function () {
    // Given
    $useCase = Mockery::mock(DeleteCategoryUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(CategoryConflictException::referencedByConferences('id-1', 3));
    app()->instance(DeleteCategoryUseCase::class, $useCase);

    // When
    $response = $this->delete('/admin/categories/id-1');

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/categories');
    expect(session('error'))->toContain('referenced by 3 conferences');
});
