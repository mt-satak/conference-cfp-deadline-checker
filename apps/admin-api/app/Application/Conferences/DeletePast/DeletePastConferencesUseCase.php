<?php

declare(strict_types=1);

namespace App\Application\Conferences\DeletePast;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceRepository;
use Illuminate\Support\Facades\Log;

/**
 * 開催日が過去の Conference を全ステータス対象でハード削除する UseCase (Issue #221 PR-1)。
 *
 * 想定起動経路:
 * - artisan command `conferences:delete-past`
 * - 週次 Lambda (月曜 JST 08:00、CDK + EventBridge schedule)
 *
 * 過去判定は Conference::isPastEvent に委譲 (= 純粋関数として Domain 層に置く)。
 * ステータスは問わない (Draft / Published / Archived すべて対象)。
 *
 * 「削除対象の存在チェック → あれば削除 / 無ければスキップ」は、過去判定で
 * candidates を絞った結果が空なら foreach が回らず deleteById を一切呼ばない、
 * という形で自然に満たされる (= 翌週また同じチェックが走る)。
 *
 * fail-soft: deleteById が false (= 別経路で既に削除済み) を返した分は
 * deletedIds / deletedCount に数えない。
 *
 * dry-run mode は本番前の影響範囲確認用。出力には対象 ID が並ぶが deleteById は呼ばれない。
 */
class DeletePastConferencesUseCase
{
    public function __construct(
        private readonly ConferenceRepository $repository,
    ) {}

    /**
     * @param  string  $today  ISO 8601 date (YYYY-MM-DD)。今日の日付として扱う
     * @param  bool  $dryRun  true なら deleteById を呼ばずに対象を返すのみ
     */
    public function execute(string $today, bool $dryRun = false): DeletePastConferencesResult
    {
        $all = $this->repository->findAll();

        $candidates = array_values(array_filter(
            $all,
            static fn (Conference $c): bool => $c->isPastEvent($today),
        ));

        $deletedIds = [];
        foreach ($candidates as $conf) {
            if ($dryRun) {
                $deletedIds[] = $conf->conferenceId;

                continue;
            }

            if (! $this->repository->deleteById($conf->conferenceId)) {
                // 別経路で既に削除済み等。fail-soft で次へ。
                continue;
            }

            $deletedIds[] = $conf->conferenceId;

            Log::info('delete-past: deleted', [
                'channel' => 'delete-past',
                'conference_id' => $conf->conferenceId,
                'name' => $conf->name,
                'status' => $conf->status->value,
                'event_start_date' => $conf->eventStartDate,
                'event_end_date' => $conf->eventEndDate,
            ]);
        }

        return new DeletePastConferencesResult(
            totalChecked: count($all),
            deletedCount: count($deletedIds),
            deletedIds: $deletedIds,
            dryRun: $dryRun,
        );
    }
}
