<?php

use App\Application\Categories\UpdateCategoryUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryConflictException;
use App\Domain\Categories\CategoryNotFoundException;
use App\Domain\Categories\CategoryRepository;
use Illuminate\Support\Carbon;

/**
 * UpdateCategoryUseCase の単体テスト。
 *
 * 責務:
 * - 存在チェック (なければ NotFound)
 * - name / slug 変更時の重複チェック (自分自身は除外)
 * - 部分更新セマンティクス (キー不在 = 既存値維持)
 * - updatedAt 更新、Repository->save() で永続化
 */

beforeEach(function () {
    Carbon::setTestNow('2026-05-04T10:00:00+09:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function existingCategory(): Category
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

it('入力 array で指定したフィールドのみ更新し、未指定フィールドは元の値を維持する', function () {
    // Given: 既存 Category、名前のみ更新
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findById')->once()->with('id-1')->andReturn(existingCategory());
    $repository->shouldReceive('findByName')->once()->with('PHP 8.5')->andReturn(null);
    $repository->shouldReceive('save')->once()->with(Mockery::type(Category::class));

    // When
    $useCase = new UpdateCategoryUseCase($repository);
    $updated = $useCase->execute('id-1', ['name' => 'PHP 8.5']);

    // Then: name のみ更新、他は維持
    expect($updated->name)->toBe('PHP 8.5');
    expect($updated->slug)->toBe('php');
    expect($updated->displayOrder)->toBe(100);
    expect($updated->axis)->toBe(CategoryAxis::A);
});

it('updatedAt は現在時刻、createdAt と categoryId は維持される', function () {
    // Given
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findById')->andReturn(existingCategory());
    $repository->shouldReceive('save')->once();

    // When
    $useCase = new UpdateCategoryUseCase($repository);
    $updated = $useCase->execute('id-1', ['displayOrder' => 200]);

    // Then
    expect($updated->categoryId)->toBe('id-1');
    expect($updated->createdAt)->toBe('2025-01-01T00:00:00+09:00');
    expect($updated->updatedAt)->toBe('2026-05-04T10:00:00+09:00');
});

it('存在しない categoryId で CategoryNotFoundException を投げる', function () {
    // Given
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findById')->once()->with('missing')->andReturn(null);
    $repository->shouldNotReceive('save');

    // When/Then
    $useCase = new UpdateCategoryUseCase($repository);
    expect(fn () => $useCase->execute('missing', ['name' => 'X']))
        ->toThrow(CategoryNotFoundException::class);
});

it('name を変更しようとした時、他レコードと重複していたら CategoryConflictException', function () {
    // Given: 別 ID の既存レコードに同 name が存在
    $other = new Category('id-2', 'Python', 'python', 200, null, '', '');
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findById')->once()->with('id-1')->andReturn(existingCategory());
    $repository->shouldReceive('findByName')->once()->with('Python')->andReturn($other);
    $repository->shouldNotReceive('save');

    // When/Then
    $useCase = new UpdateCategoryUseCase($repository);
    expect(fn () => $useCase->execute('id-1', ['name' => 'Python']))
        ->toThrow(CategoryConflictException::class);
});

it('slug を変更しようとした時、他レコードと重複していたら CategoryConflictException', function () {
    // Given
    $other = new Category('id-2', 'Python', 'python', 200, null, '', '');
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findById')->once()->with('id-1')->andReturn(existingCategory());
    $repository->shouldReceive('findBySlug')->once()->with('python')->andReturn($other);
    $repository->shouldNotReceive('save');

    // When/Then
    $useCase = new UpdateCategoryUseCase($repository);
    expect(fn () => $useCase->execute('id-1', ['slug' => 'python']))
        ->toThrow(CategoryConflictException::class);
});

it('name を同じ値で渡しても重複チェックはスキップする (自分自身を除外)', function () {
    // Given: name は元の値と同じで上書き
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findById')->andReturn(existingCategory());
    // findByName は呼ばれない (name 変更ナシなので)
    $repository->shouldNotReceive('findByName');
    $repository->shouldReceive('save')->once();

    // When
    $useCase = new UpdateCategoryUseCase($repository);
    $updated = $useCase->execute('id-1', ['name' => 'PHP', 'displayOrder' => 150]);

    // Then
    expect($updated->name)->toBe('PHP');
    expect($updated->displayOrder)->toBe(150);
});

it('axis を null で送ると null に更新される (明示的にクリア)', function () {
    // Given
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findById')->andReturn(existingCategory());
    $repository->shouldReceive('save')->once();

    // When
    $useCase = new UpdateCategoryUseCase($repository);
    $updated = $useCase->execute('id-1', ['axis' => null]);

    // Then
    expect($updated->axis)->toBeNull();
});
