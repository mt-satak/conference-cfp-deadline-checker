<?php

declare(strict_types=1);

namespace App\Application\Conferences\PendingChanges;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceRepository;
use Illuminate\Support\Carbon;

/**
 * 保留差分 (pendingChanges) を破棄する UseCase (Issue #188 PR-3)。
 *
 * 動作:
 *   1. findById で Conference を取得 (なければ ConferenceNotFoundException)
 *   2. pendingChanges が null/empty なら no-op で現状を返す (= 二重クリック safe)
 *   3. actual フィールドは一切変更せず、pendingChanges のみ null にクリア
 *   4. updatedAt を現在時刻 (JST) に更新
 *   5. Repository->save() で永続化
 *
 * Apply UseCase との対比: actual を全く触らないため格段にシンプル。
 */
class RejectPendingChangesUseCase
{
    public function __construct(
        private readonly ConferenceRepository $repository,
    ) {}

    /**
     * @throws ConferenceNotFoundException
     */
    public function execute(string $conferenceId): Conference
    {
        $existing = $this->repository->findById($conferenceId);
        if ($existing === null) {
            throw ConferenceNotFoundException::withId($conferenceId);
        }

        if (empty($existing->pendingChanges)) {
            return $existing;
        }

        $cleared = new Conference(
            conferenceId: $existing->conferenceId,
            name: $existing->name,
            trackName: $existing->trackName,
            officialUrl: $existing->officialUrl,
            cfpUrl: $existing->cfpUrl,
            eventStartDate: $existing->eventStartDate,
            eventEndDate: $existing->eventEndDate,
            venue: $existing->venue,
            format: $existing->format,
            cfpStartDate: $existing->cfpStartDate,
            cfpEndDate: $existing->cfpEndDate,
            categories: $existing->categories,
            description: $existing->description,
            themeColor: $existing->themeColor,
            createdAt: $existing->createdAt,
            updatedAt: Carbon::now('Asia/Tokyo')->toIso8601String(),
            status: $existing->status,
            pendingChanges: null,
        );

        $this->repository->save($cleared);

        return $cleared;
    }
}
