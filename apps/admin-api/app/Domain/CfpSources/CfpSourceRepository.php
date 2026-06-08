<?php

declare(strict_types=1);

namespace App\Domain\CfpSources;

/**
 * CfP ソース情報の永続化境界 (interface)。
 *
 * Categories と同パターン。想定件数は数 〜 数十件なので findAll() で全件取得して
 * 呼出側で createdAt 昇順ソートする方針。
 */
interface CfpSourceRepository
{
    /**
     * 全 source を取得する。並び順は実装依存 (呼び出し側で再ソート前提)。
     *
     * @return CfpSource[]
     */
    public function findAll(): array;

    /**
     * UUID 指定で 1 件取得する。存在しなければ null。
     */
    public function findById(string $sourceId): ?CfpSource;

    /**
     * url (正規化後) 完全一致で 1 件取得する (重複検査用)。
     * 存在しなければ null。
     */
    public function findByUrl(string $url): ?CfpSource;

    /**
     * source を保存する。同 sourceId があれば上書き、なければ新規作成 (upsert)。
     */
    public function save(CfpSource $source): void;

    /**
     * UUID 指定で削除する。削除実行された場合は true、対象が無かった場合は false。
     */
    public function deleteById(string $sourceId): bool;
}
