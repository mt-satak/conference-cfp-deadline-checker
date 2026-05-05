<?php

namespace App\Domain\Conferences;

/**
 * カンファレンスの Entity (ドメイン層、Aggregate Root)。
 *
 * `conferenceId` を識別子に持つ Entity であり、Conferences Aggregate の Root。
 * Eloquent / DynamoDB SDK には依存しない純粋な構造体で、Repository (interface) を
 * 介して永続化層と境界を切ることで、テスト時には Repository をモックしやすくなる。
 *
 * 設計判断:
 * - 日付 / 日時は string (ISO 8601) で保持する。
 *   DynamoDB 側もこの形式の文字列で格納するため、無駄な変換を挟まない。
 *   ドメイン層で日付演算が必要になった時点で DateTimeImmutable 化を検討する。
 * - readonly class により全プロパティはコンストラクタ後変更不可 (PHP 8.2+)。
 *   状態遷移は新しい Conference インスタンスを返す形で表現する。
 *
 * 各プロパティの仕様は data/openapi.yaml の Conference スキーマ
 * および data/schema.md の conferences テーブル定義を参照。
 */
final readonly class Conference
{
    /**
     * @param  string[]  $categories  categories.categoryId の配列 (UUID v4)
     *
     * status はデフォルト Published。Phase 0.5 (Issue #41) 導入前の既存コール
     * サイトとの後方互換のため。Draft 状態は明示指定で構築する。
     * PR-2 で Draft 状態時に他フィールドを nullable 化するスコープ拡張を行う。
     */
    public function __construct(
        public string $conferenceId,
        public string $name,
        public ?string $trackName,
        public string $officialUrl,
        public string $cfpUrl,
        public string $eventStartDate,
        public string $eventEndDate,
        public string $venue,
        public ConferenceFormat $format,
        public ?string $cfpStartDate,
        public string $cfpEndDate,
        public array $categories,
        public ?string $description,
        public ?string $themeColor,
        public string $createdAt,
        public string $updatedAt,
        public ConferenceStatus $status = ConferenceStatus::Published,
    ) {}
}
