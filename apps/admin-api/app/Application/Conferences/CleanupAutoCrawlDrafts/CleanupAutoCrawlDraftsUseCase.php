<?php

declare(strict_types=1);

namespace App\Application\Conferences\CleanupAutoCrawlDrafts;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;
use App\Domain\Conferences\OfficialUrl;

/**
 * AutoCrawl 起源 Draft の一括削除 UseCase (Issue #188 PR-2)。
 *
 * 背景:
 *   PR-1 で AutoCrawl が Draft 別行を作らない設計に切り替わったため、過去の
 *   AutoCrawl が生成した Draft 行は admin 画面で「Published と並ぶ重複行」のまま残る。
 *   これらを一括削除して画面をクリーンアップする。
 *
 * 識別基準:
 *   Draft.officialUrl (正規化後) が「現存する Published」のいずれかと一致する場合、
 *   その Draft は AutoCrawl 起源とみなして削除対象にする。
 *
 *   admin が手動で作成する Draft は新規 conference 用なので Published と URL 重複しない。
 *   Archived は判定対象外 (= 過去 conference の URL と重複する手動 Draft を誤削除しない
 *   ための保守的選択)。
 *
 * 動作モード:
 *   - dryRun = true (デフォルト): 候補 ID を列挙するだけで deleteById を呼ばない
 *   - dryRun = false: deleteById を呼んで実削除
 *
 * idempotent: 削除完了後に再実行しても候補 0 件で no-op になる。
 */
class CleanupAutoCrawlDraftsUseCase
{
    public function __construct(
        private readonly ConferenceRepository $conferenceRepository,
    ) {}

    public function execute(bool $dryRun = true): CleanupAutoCrawlDraftsResult
    {
        $allConferences = $this->conferenceRepository->findAll();

        // Published 集合を officialUrl 正規化後で構築 (= 表記揺れ吸収)
        $publishedUrls = [];
        foreach ($allConferences as $c) {
            if ($c->status === ConferenceStatus::Published) {
                $publishedUrls[OfficialUrl::normalize($c->officialUrl)] = true;
            }
        }

        // Draft で officialUrl (正規化後) が Published 集合に含まれるものを候補化
        $candidateIds = [];
        foreach ($allConferences as $c) {
            if ($c->status !== ConferenceStatus::Draft) {
                continue;
            }
            if (isset($publishedUrls[OfficialUrl::normalize($c->officialUrl)])) {
                $candidateIds[] = $c->conferenceId;
            }
        }

        if ($dryRun) {
            return new CleanupAutoCrawlDraftsResult(
                dryRun: true,
                candidateIds: $candidateIds,
                deletedIds: [],
            );
        }

        $deletedIds = [];
        foreach ($candidateIds as $id) {
            if ($this->conferenceRepository->deleteById($id)) {
                $deletedIds[] = $id;
            }
        }

        return new CleanupAutoCrawlDraftsResult(
            dryRun: false,
            candidateIds: $candidateIds,
            deletedIds: $deletedIds,
        );
    }
}
