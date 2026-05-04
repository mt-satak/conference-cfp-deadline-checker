<?php

namespace App\Domain\Categories;

use Exception;

/**
 * 指定された categoryId のカテゴリが見つからなかった時に投げる Domain 例外。
 *
 * HTTP レイヤでは AdminApiExceptionRenderer が 404 + NOT_FOUND に整形する。
 */
class CategoryNotFoundException extends Exception
{
    public static function withId(string $categoryId): self
    {
        return new self("Category not found: {$categoryId}");
    }
}
