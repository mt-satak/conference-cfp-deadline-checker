<?php

namespace App\Domain\Categories;

/**
 * カテゴリの Entity (ドメイン層、Aggregate Root)。
 *
 * `categoryId` を識別子に持つ Entity。Conferences と同様に Eloquent / DynamoDB SDK
 * には依存しない純粋な構造体で、Repository (interface) を介して永続化層と境界を切る。
 *
 * 設計判断:
 * - displayOrder は integer。OpenAPI では「軸ごとに番号帯を分割した整数値」と
 *   定義されており、軸間の並び替えは displayOrder のみで行う
 * - axis は enum (運用補助。null 許容)。CategoryAxis::A..D のいずれか
 * - 日時は ISO 8601 文字列で保持 (Conference と同方針)
 *
 * 各プロパティの仕様は data/openapi.yaml の Category スキーマ
 * および data/schema.md の categories テーブル定義を参照。
 */
final readonly class Category
{
    public function __construct(
        public string $categoryId,
        public string $name,
        public string $slug,
        public int $displayOrder,
        public ?CategoryAxis $axis,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
