<?php

declare(strict_types=1);

namespace App\Application\Conferences;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;
use App\Domain\Conferences\OfficialUrl;
use Illuminate\Support\Facades\Log;

/**
 * 同 URL の Draft Conference 重複を解消する UseCase (Issue #169 Phase 2)。
 *
 * Issue #152 の AutoCrawl が初期版で毎週新規 UUID で Draft を作成していたため、
 * 同じ URL に対して累積した Draft を整理する一括クリーンアップツール。
 *
 * 動作:
 * 1. 全 Conference を取得
 * 2. status=Draft のみ抽出
 * 3. OfficialUrl::normalize でグルーピング
 * 4. 各グループで複数 Draft があれば最新 createdAt を残し、それ以外を deleteById で削除
 *
 * 「最新 createdAt を残す」根拠:
 * - LLM の最新抽出結果が現状最も妥当である前提
 * - admin が古い Draft を編集中のケースは現状想定しない
 *
 * Phase 1 (PR #170) で AutoCrawl 側の重複防止が入っているため、本 UseCase は
 * 「既存の重複を片付ける」ためだけに使う想定。日次 schedule 化は不要 (= 必要時に手動実行)。
 */
class DedupeDraftsUseCase
{
    public function __construct(
        private readonly ConferenceRepository $repository,
    ) {}

    public function execute(bool $dryRun = false): DedupeDraftsResult
    {
        $allDrafts = array_values(array_filter(
            $this->repository->findAll(),
            static fn (Conference $c): bool => $c->status === ConferenceStatus::Draft,
        ));

        // 正規化 URL でグルーピング: array<string, Conference[]>
        /** @var array<string, Conference[]> $groups */
        $groups = [];
        foreach ($allDrafts as $draft) {
            $key = OfficialUrl::normalize($draft->officialUrl);
            $groups[$key][] = $draft;
        }

        $duplicateGroups = 0;
        $deletedIds = [];

        foreach ($groups as $key => $drafts) {
            if (count($drafts) <= 1) {
                continue;
            }
            $duplicateGroups++;

            // createdAt 降順 (= 新しい順) でソート、先頭以外を削除対象に
            usort(
                $drafts,
                static fn (Conference $a, Conference $b): int => strcmp($b->createdAt, $a->createdAt),
            );
            $kept = $drafts[0];
            $toDelete = array_slice($drafts, 1);

            foreach ($toDelete as $draft) {
                $deletedIds[] = $draft->conferenceId;

                if ($dryRun) {
                    continue;
                }

                $this->repository->deleteById($draft->conferenceId);

                Log::info('dedupe-drafts: deleted', [
                    'channel' => 'dedupe-drafts',
                    'deleted_conference_id' => $draft->conferenceId,
                    'deleted_created_at' => $draft->createdAt,
                    'kept_conference_id' => $kept->conferenceId,
                    'kept_created_at' => $kept->createdAt,
                    'normalized_url' => $key,
                ]);
            }
        }

        return new DedupeDraftsResult(
            totalDrafts: count($allDrafts),
            duplicateGroups: $duplicateGroups,
            deletedCount: count($deletedIds),
            deletedIds: $deletedIds,
            dryRun: $dryRun,
        );
    }
}
