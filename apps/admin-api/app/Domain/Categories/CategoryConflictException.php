<?php

namespace App\Domain\Categories;

use Exception;

/**
 * カテゴリ操作の整合性エラー (HTTP 409 Conflict 相当) を表す Domain 例外。
 *
 * - name 重複 (既存と同じ name でカテゴリを作成しようとした)
 * - slug 重複 (既存と同じ slug でカテゴリを作成しようとした)
 * - 削除不能 (削除対象を参照する conferences が存在する)
 *
 * いずれも単一の "CONFLICT" として AdminApiExceptionRenderer が 409 にマップする。
 * 個別事由は message で識別する。
 */
class CategoryConflictException extends Exception
{
    public static function nameAlreadyExists(string $name): self
    {
        return new self("Category name already exists: {$name}");
    }

    public static function slugAlreadyExists(string $slug): self
    {
        return new self("Category slug already exists: {$slug}");
    }

    public static function referencedByConferences(string $categoryId, int $count): self
    {
        return new self("Category is referenced by {$count} conferences and cannot be deleted: {$categoryId}");
    }
}
