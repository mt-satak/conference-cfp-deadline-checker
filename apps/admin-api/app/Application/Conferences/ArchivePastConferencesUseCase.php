<?php

declare(strict_types=1);

namespace App\Application\Conferences;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 開催日を過ぎた Published Conference を Archived に遷移させる UseCase (Issue #165 Phase 2)。
 *
 * 想定起動経路:
 * - artisan command `conferences:archive-past` (Phase 2 で実装)
 * - 日次 Lambda (Phase 3 で CDK + EventBridge schedule で起動)
 *
 * 過去判定は Conference::isPastEvent に委譲 (= 純粋関数として Domain 層に置く)。
 * 本 UseCase は Repository 結合 + フィルタ + ループ save の責務のみ。
 *
 * dry-run mode は本番前の影響範囲確認用。出力には対象 ID が並ぶが save() は呼ばれない。
 */
class ArchivePastConferencesUseCase
{
    public function __construct(
        private readonly ConferenceRepository $repository,
    ) {}

    /**
     * @param  string  $today  ISO 8601 date (YYYY-MM-DD)。今日の日付として扱う
     * @param  bool  $dryRun  true なら save() を呼ばずに対象を返すのみ
     */
    public function execute(string $today, bool $dryRun = false): ArchivePastResult
    {
        $all = $this->repository->findAll();

        $candidates = array_values(array_filter(
            $all,
            static fn (Conference $c): bool => $c->status === ConferenceStatus::Published
                && $c->isPastEvent($today),
        ));

        $archivedIds = [];
        foreach ($candidates as $conf) {
            $archivedIds[] = $conf->conferenceId;

            if ($dryRun) {
                continue;
            }

            $archived = $conf->withStatus(
                ConferenceStatus::Archived,
                Carbon::now('Asia/Tokyo')->toIso8601String(),
            );
            $this->repository->save($archived);

            Log::info('archive-past: archived', [
                'channel' => 'archive-past',
                'conference_id' => $conf->conferenceId,
                'name' => $conf->name,
                'event_start_date' => $conf->eventStartDate,
                'event_end_date' => $conf->eventEndDate,
            ]);
        }

        return new ArchivePastResult(
            totalChecked: count($all),
            archivedCount: count($archivedIds),
            archivedIds: $archivedIds,
            dryRun: $dryRun,
        );
    }
}
