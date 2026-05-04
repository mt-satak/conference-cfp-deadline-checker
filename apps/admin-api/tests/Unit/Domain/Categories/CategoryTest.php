<?php

use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;

/**
 * Category Entity の単体テスト。
 *
 * readonly クラスなので構築値の保持と axis enum の取り扱いを検証する。
 */
it('全フィールドを指定して Category を構築できる', function () {
    // Given: 全プロパティ値
    // When: Category 構築
    $category = new Category(
        categoryId: '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02',
        name: 'PHP',
        slug: 'php',
        displayOrder: 100,
        axis: CategoryAxis::A,
        createdAt: '2026-05-04T10:00:00+09:00',
        updatedAt: '2026-05-04T10:00:00+09:00',
    );

    // Then: 各プロパティが保持される
    expect($category->categoryId)->toBe('1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02');
    expect($category->name)->toBe('PHP');
    expect($category->slug)->toBe('php');
    expect($category->displayOrder)->toBe(100);
    expect($category->axis)->toBe(CategoryAxis::A);
    expect($category->createdAt)->toBe('2026-05-04T10:00:00+09:00');
    expect($category->updatedAt)->toBe('2026-05-04T10:00:00+09:00');
});

it('axis を null で構築できる (axis は optional)', function () {
    // Given/When: axis null
    $category = new Category(
        categoryId: '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02',
        name: 'PHP',
        slug: 'php',
        displayOrder: 100,
        axis: null,
        createdAt: '2026-05-04T10:00:00+09:00',
        updatedAt: '2026-05-04T10:00:00+09:00',
    );

    // Then: null が保持される
    expect($category->axis)->toBeNull();
});

it('CategoryAxis enum は A/B/C/D の 4 値を持つ', function () {
    // Given/When: enum cases
    $values = array_column(CategoryAxis::cases(), 'value');

    // Then: A/B/C/D
    expect($values)->toBe(['A', 'B', 'C', 'D']);
});
