<?php

use App\Application\Categories\CreateCategoryInput;
use App\Application\Categories\CreateCategoryUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryConflictException;
use App\Domain\Categories\CategoryRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * CreateCategoryUseCase の単体テスト。
 *
 * 責務:
 * - name / slug 重複時 CategoryConflictException
 * - UUID と現在時刻を補完して Category を構築
 * - Repository->save() で永続化
 */

beforeEach(function () {
    Carbon::setTestNow('2026-05-04T10:00:00+09:00');
    Str::createUuidsUsing(fn () => Uuid::fromString('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'));
});

afterEach(function () {
    Carbon::setTestNow();
    Str::createUuidsNormally();
});

function makeCreateCategoryInput(): CreateCategoryInput
{
    return new CreateCategoryInput(
        name: 'PHP',
        slug: 'php',
        displayOrder: 100,
        axis: CategoryAxis::A,
    );
}

it('入力 DTO から UUID と現在時刻を補完して Category を返す', function () {
    // Given: name / slug 重複なし、save 1 回呼出
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findByName')->once()->with('PHP')->andReturn(null);
    $repository->shouldReceive('findBySlug')->once()->with('php')->andReturn(null);
    $repository->shouldReceive('save')->once()->with(Mockery::type(Category::class));

    // When
    $useCase = new CreateCategoryUseCase($repository);
    $created = $useCase->execute(makeCreateCategoryInput());

    // Then: 補完された値が反映される
    expect($created->categoryId)->toBe('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
    expect($created->name)->toBe('PHP');
    expect($created->slug)->toBe('php');
    expect($created->displayOrder)->toBe(100);
    expect($created->axis)->toBe(CategoryAxis::A);
    expect($created->createdAt)->toBe('2026-05-04T10:00:00+09:00');
    expect($created->updatedAt)->toBe('2026-05-04T10:00:00+09:00');
});

it('name 重複時 CategoryConflictException を投げる (slug チェックや save は走らない)', function () {
    // Given: name 既存あり
    $existing = new Category('id-x', 'PHP', 'php', 100, null, '2026-01-01T00:00:00+09:00', '2026-01-01T00:00:00+09:00');
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findByName')->once()->with('PHP')->andReturn($existing);
    // findBySlug / save は呼ばれない
    $repository->shouldNotReceive('findBySlug');
    $repository->shouldNotReceive('save');

    // When/Then
    $useCase = new CreateCategoryUseCase($repository);
    expect(fn () => $useCase->execute(makeCreateCategoryInput()))
        ->toThrow(CategoryConflictException::class);
});

it('slug 重複時 CategoryConflictException を投げる (save は走らない)', function () {
    // Given: name は OK だが slug が既存と衝突
    $existing = new Category('id-x', '別の名前', 'php', 100, null, '2026-01-01T00:00:00+09:00', '2026-01-01T00:00:00+09:00');
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findByName')->once()->with('PHP')->andReturn(null);
    $repository->shouldReceive('findBySlug')->once()->with('php')->andReturn($existing);
    $repository->shouldNotReceive('save');

    // When/Then
    $useCase = new CreateCategoryUseCase($repository);
    expect(fn () => $useCase->execute(makeCreateCategoryInput()))
        ->toThrow(CategoryConflictException::class);
});

it('axis を null で渡せる (optional)', function () {
    // Given
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findByName')->andReturn(null);
    $repository->shouldReceive('findBySlug')->andReturn(null);
    $repository->shouldReceive('save')->once();

    // When
    $useCase = new CreateCategoryUseCase($repository);
    $created = $useCase->execute(new CreateCategoryInput(
        name: 'X',
        slug: 'x',
        displayOrder: 1,
        axis: null,
    ));

    // Then
    expect($created->axis)->toBeNull();
});
