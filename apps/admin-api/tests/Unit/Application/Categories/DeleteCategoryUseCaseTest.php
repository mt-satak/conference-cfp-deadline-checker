<?php

use App\Application\Categories\DeleteCategoryUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryConflictException;
use App\Domain\Categories\CategoryNotFoundException;
use App\Domain\Categories\CategoryRepository;
use App\Domain\Conferences\ConferenceRepository;

/**
 * DeleteCategoryUseCase の単体テスト。
 *
 * 責務:
 * - 存在チェック (なければ NotFound)
 * - Conference 参照件数チェック (>0 なら Conflict、削除拒否)
 * - 両チェック通過したら Repository->deleteById()
 *
 * 優先度: 不在 (404) > 参照あり (409) — 不在を 404 で返したい意図 (UseCase 内コメント参照)。
 */
function existingCategoryForDelete(): Category
{
    return new Category(
        categoryId: 'id-1',
        name: 'PHP',
        slug: 'php',
        displayOrder: 100,
        axis: CategoryAxis::A,
        createdAt: '2025-01-01T00:00:00+09:00',
        updatedAt: '2025-01-01T00:00:00+09:00',
    );
}

it('存在し、参照する Conference が無ければ削除する', function () {
    // Given
    $categoryRepo = Mockery::mock(CategoryRepository::class);
    $categoryRepo->shouldReceive('findById')->once()->with('id-1')->andReturn(existingCategoryForDelete());
    $categoryRepo->shouldReceive('deleteById')->once()->with('id-1')->andReturn(true);
    $confRepo = Mockery::mock(ConferenceRepository::class);
    $confRepo->shouldReceive('countByCategoryId')->once()->with('id-1')->andReturn(0);

    // When
    $useCase = new DeleteCategoryUseCase($categoryRepo, $confRepo);
    $useCase->execute('id-1');

    // Then: Mockery が shouldReceive をすべて満たして例外なく完了
    expect(true)->toBeTrue();
});

it('存在しない categoryId で CategoryNotFoundException (Conference 件数チェックは走らない)', function () {
    // Given
    $categoryRepo = Mockery::mock(CategoryRepository::class);
    $categoryRepo->shouldReceive('findById')->once()->with('missing')->andReturn(null);
    $categoryRepo->shouldNotReceive('deleteById');
    $confRepo = Mockery::mock(ConferenceRepository::class);
    $confRepo->shouldNotReceive('countByCategoryId');

    // When/Then
    $useCase = new DeleteCategoryUseCase($categoryRepo, $confRepo);
    expect(fn () => $useCase->execute('missing'))
        ->toThrow(CategoryNotFoundException::class);
});

it('参照する Conference が存在する場合 CategoryConflictException (削除されない)', function () {
    // Given
    $categoryRepo = Mockery::mock(CategoryRepository::class);
    $categoryRepo->shouldReceive('findById')->andReturn(existingCategoryForDelete());
    $categoryRepo->shouldNotReceive('deleteById');
    $confRepo = Mockery::mock(ConferenceRepository::class);
    $confRepo->shouldReceive('countByCategoryId')->once()->with('id-1')->andReturn(3);

    // When/Then
    $useCase = new DeleteCategoryUseCase($categoryRepo, $confRepo);
    expect(fn () => $useCase->execute('id-1'))
        ->toThrow(CategoryConflictException::class);
});
