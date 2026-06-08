<?php

declare(strict_types=1);

namespace App\Domain\CfpSources;

/**
 * CfP ソース Entity (Issue #200 PR-1)。
 *
 * 週次自動 CfP 発見 (Issue #200) のための「巡回対象 URL」を表す Aggregate Root。
 * 例: fortee.jp/events / connpass.com/explore?keyword=tech 等の集約ページ。
 *
 * 設計判断:
 * - sourceId (UUID v4) を識別子に持つ
 * - url は正規化前の生 URL を保持。重複検査は OfficialUrl::normalize を介して行う
 *   (= Conference 側と同じ表記揺れ吸収ロジックを再利用)
 * - enabled: 削除せず一時的に巡回対象から外す用 (= 履歴を残したい運用)
 * - 日時は ISO 8601 文字列 (= Conference / Category と同方針)
 */
final readonly class CfpSource
{
    public function __construct(
        public string $sourceId,
        public string $name,
        public string $url,
        public bool $enabled,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
