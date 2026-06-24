<?php

declare(strict_types=1);

namespace App\Application\Conferences\BulkDelete;

use App\Domain\Conferences\ConferenceRepository;

/**
 * カンファレンス一括削除 UseCase (Issue #219)。
 *
 * 一覧画面でチェックした複数行をまとめて削除する。
 *
 * 設計判断 (fail-soft):
 *   単件の DeleteConferenceUseCase は not-found で ConferenceNotFoundException を
 *   投げるが、bulk では別タブで既に削除済みの行が混ざっても全体を止めない。
 *   deleteById が false (= 対象なし) を返した分はスキップし deletedCount に
 *   数えないことで、並行削除でも残りを確実に削除する。
 *
 *   ID は重複排除してから削除する (= 同一 ID の二重カウント防止)。
 */
class BulkDeleteConferencesUseCase
{
    public function __construct(
        private readonly ConferenceRepository $repository,
    ) {}

    /**
     * @param  list<string>  $ids
     */
    public function execute(array $ids): BulkDeleteConferencesResult
    {
        $uniqueIds = array_values(array_unique($ids));

        $deletedCount = 0;
        foreach ($uniqueIds as $id) {
            if ($this->repository->deleteById($id)) {
                $deletedCount++;
            }
        }

        return new BulkDeleteConferencesResult(
            requestedCount: count($uniqueIds),
            deletedCount: $deletedCount,
        );
    }
}
