<?php

declare(strict_types=1);

namespace App\Application\Conferences\PendingChanges;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;
use Illuminate\Support\Carbon;

/**
 * 保留差分 (pendingChanges) を actual に反映する UseCase (Issue #188 PR-3)。
 *
 * 動作:
 *   1. findById で Conference を取得 (なければ ConferenceNotFoundException)
 *   2. pendingChanges が null/empty なら no-op で現状を返す (= 二重クリック safe)
 *   3. pendingChanges の各 (field, new) を actual フィールドに反映
 *      - format は enum 文字列なので ConferenceFormat::tryFrom で再変換
 *      - 不明フィールド名は defensive に無視 (= 未来の AutoCrawl 拡張時の互換性)
 *   4. pendingChanges を null にクリア
 *   5. updatedAt を現在時刻 (JST) に更新
 *   6. Repository->save() で永続化
 *
 * 反映対象フィールドのホワイトリスト:
 *   AutoCrawl detectDiff() が出力する 7 フィールド (cfpUrl / eventStartDate /
 *   eventEndDate / venue / format / cfpStartDate / cfpEndDate)。それ以外の名前が
 *   入っていても無視するのは、Conference コンストラクタへの不正引数を防ぐため。
 */
class ApplyPendingChangesUseCase
{
    /**
     * actual に反映してよい pendingChanges のフィールド名集合。
     * AutoCrawl detectDiff() の compareFields と一致させる。
     */
    private const APPLIABLE_FIELDS = [
        'cfpUrl',
        'eventStartDate',
        'eventEndDate',
        'venue',
        'format',
        'cfpStartDate',
        'cfpEndDate',
    ];

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

        // 既存値で全フィールドを埋め、pendingChanges の new で上書きする (UpdateConferenceUseCase と同パターン)。
        $args = [
            'conferenceId' => $existing->conferenceId,
            'name' => $existing->name,
            'trackName' => $existing->trackName,
            'officialUrl' => $existing->officialUrl,
            'cfpUrl' => $existing->cfpUrl,
            'eventStartDate' => $existing->eventStartDate,
            'eventEndDate' => $existing->eventEndDate,
            'venue' => $existing->venue,
            'format' => $existing->format,
            'cfpStartDate' => $existing->cfpStartDate,
            'cfpEndDate' => $existing->cfpEndDate,
            'categories' => $existing->categories,
            'description' => $existing->description,
            'themeColor' => $existing->themeColor,
            'createdAt' => $existing->createdAt,
            'updatedAt' => Carbon::now('Asia/Tokyo')->toIso8601String(),
            'status' => $existing->status,
            'pendingChanges' => null,
        ];

        foreach ($existing->pendingChanges as $field => $entry) {
            if (! in_array($field, self::APPLIABLE_FIELDS, true)) {
                continue;
            }
            $newValue = $entry['new'] ?? null;
            $args[$field] = $field === 'format' && is_string($newValue)
                ? ConferenceFormat::tryFrom($newValue)
                : $newValue;
        }

        /** @var array{
         *     conferenceId: string,
         *     name: string,
         *     trackName: string|null,
         *     officialUrl: string,
         *     cfpUrl: string|null,
         *     eventStartDate: string|null,
         *     eventEndDate: string|null,
         *     venue: string|null,
         *     format: ConferenceFormat|null,
         *     cfpStartDate: string|null,
         *     cfpEndDate: string|null,
         *     categories: array<int, string>,
         *     description: string|null,
         *     themeColor: string|null,
         *     createdAt: string,
         *     updatedAt: string,
         *     status: ConferenceStatus,
         *     pendingChanges: null,
         * } $args
         */
        $updated = new Conference(...$args);

        $this->repository->save($updated);

        return $updated;
    }
}
