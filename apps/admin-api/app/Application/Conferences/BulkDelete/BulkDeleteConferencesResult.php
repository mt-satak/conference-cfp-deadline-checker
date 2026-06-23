<?php

declare(strict_types=1);

namespace App\Application\Conferences\BulkDelete;

/**
 * カンファレンス一括削除 (Issue #219) の結果サマリ。
 *
 * フィールド:
 *   - requestedCount: 削除を要求された ID の件数 (= 重複排除後)
 *   - deletedCount:   実際に削除された件数 (deleteById が true を返した数)
 *
 * requestedCount > deletedCount となるのは、別タブで既に削除済み等で
 * deleteById が false を返した行があった場合 (= fail-soft、UseCase 参照)。
 */
final readonly class BulkDeleteConferencesResult
{
    public function __construct(
        public int $requestedCount,
        public int $deletedCount,
    ) {}
}
