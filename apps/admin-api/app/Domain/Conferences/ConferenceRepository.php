<?php

namespace App\Domain\Conferences;

/**
 * カンファレンス情報の永続化境界 (interface)。
 *
 * Domain 層から Infrastructure 層 (DynamoDB 等) を呼び出すための契約。
 * HTTP コントローラやドメインサービスはこの interface に依存し、
 * 実装 (DynamoDbConferenceRepository) には DI コンテナで紐付ける。
 *
 * findAll() は本アプリの想定件数 (50〜200) では全件取得で十分という前提に立つ。
 * 大規模化した場合はページネーション対応の別メソッドを追加する。
 */
interface ConferenceRepository
{
    /**
     * 全カンファレンスを取得する。並び順は実装依存 (呼び出し側で再ソート前提)。
     *
     * @return Conference[]
     */
    public function findAll(): array;

    /**
     * UUID 指定で 1 件取得する。存在しなければ null。
     */
    public function findById(string $conferenceId): ?Conference;

    /**
     * カンファレンスを保存する。conferenceId の有無で新規 / 更新を区別せず、
     * 同 ID があれば上書き、なければ新規作成 (upsert)。
     */
    public function save(Conference $conference): void;

    /**
     * UUID 指定で削除する。削除実行された場合は true、対象が無かった場合は false。
     */
    public function deleteById(string $conferenceId): bool;

    /**
     * 指定 categoryId を categories 配列に含むカンファレンスの件数を返す。
     * Categories 削除時の参照整合性チェック (HTTP 409) で使用する。
     */
    public function countByCategoryId(string $categoryId): int;
}
