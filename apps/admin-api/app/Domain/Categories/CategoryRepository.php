<?php

namespace App\Domain\Categories;

/**
 * カテゴリ情報の永続化境界 (interface)。
 *
 * Conferences 同様、Domain 層から Infrastructure 層 (DynamoDB) を呼び出す契約。
 * 想定件数は 30〜50 件 (data/schema.md) なので findAll() で全件取得して呼出側で
 * displayOrder 昇順ソートする方針。
 */
interface CategoryRepository
{
    /**
     * 全カテゴリを取得する。並び順は実装依存 (呼び出し側で再ソート前提)。
     *
     * @return Category[]
     */
    public function findAll(): array;

    /**
     * UUID 指定で 1 件取得する。存在しなければ null。
     */
    public function findById(string $categoryId): ?Category;

    /**
     * name 完全一致でカテゴリを 1 件取得する (重複検査用)。
     * 存在しなければ null。
     */
    public function findByName(string $name): ?Category;

    /**
     * slug 完全一致でカテゴリを 1 件取得する (重複検査用)。
     * 存在しなければ null。
     */
    public function findBySlug(string $slug): ?Category;

    /**
     * カテゴリを保存する。同 categoryId があれば上書き、なければ新規作成 (upsert)。
     */
    public function save(Category $category): void;

    /**
     * UUID 指定で削除する。削除実行された場合は true、対象が無かった場合は false。
     */
    public function deleteById(string $categoryId): bool;
}
