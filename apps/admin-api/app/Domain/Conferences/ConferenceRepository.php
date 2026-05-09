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
     * officialUrl で 1 件取得する。存在しなければ null。
     *
     * 引数と DB 内 conference の officialUrl を OfficialUrl::normalize() で
     * 正規化してから比較するため、表記揺れ (https/http, trailing slash, www.,
     * query/fragment 等) を吸収する。
     *
     * Issue #152 Phase 1 の自動巡回で「既存重複」を検知するために使う。
     *
     * 件数規模 (50〜200) なので O(N) の全件 scan + memory 内比較で十分。
     * 大規模化した場合は normalized_official_url を別 attribute として保存して
     * GSI で indexed lookup にする。
     */
    public function findByOfficialUrl(string $officialUrl): ?Conference;

    /**
     * status=Draft の中から officialUrl 一致の Conference を 1 件取得する (Issue #169)。
     *
     * findByOfficialUrl との違い:
     * - status=Draft のみが検索対象 (Published / Archived は無視)
     * - 同 URL の Draft が複数あれば最新 createdAt を返す (= 重複時は最新優先)
     *
     * 用途: AutoCrawl が差分検知時に「既に同じ URL の Draft がないか」を確認し、
     * あれば新規 UUID で作らずに既存 Draft を上書きするため。
     */
    public function findDraftByOfficialUrl(string $officialUrl): ?Conference;

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
