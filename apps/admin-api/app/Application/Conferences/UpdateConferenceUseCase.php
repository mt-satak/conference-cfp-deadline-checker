<?php

namespace App\Application\Conferences;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceRepository;
use Illuminate\Support\Carbon;

/**
 * カンファレンス更新 UseCase。
 *
 * 責務:
 * - Repository->findById() で既存 Conference を取得 (なければ
 *   ConferenceNotFoundException を投げる)
 * - 入力 array にあるキーのみを反映した新しい Conference を構築 (部分更新)
 * - updatedAt を現在時刻 (JST) に更新、createdAt と conferenceId は維持
 * - Repository->save() で永続化
 *
 * 部分更新セマンティクス: 入力 array に含まれていないキーは元の値を維持する。
 * バリデーション (URL 形式 / 日付整合性 / categories の参照整合性 等) は HTTP 層
 * (FormRequest) で行う前提。
 */
class UpdateConferenceUseCase
{
    public function __construct(
        private readonly ConferenceRepository $repository,
    ) {}

    /**
     * 部分更新の入力 shape は UpdateConferenceRequest::validated() と同じ
     * (Controller で format のみ enum 化済み)。型 narrowing のため明示。
     *
     * @param  array{
     *     name?: string,
     *     trackName?: string|null,
     *     officialUrl?: string,
     *     cfpUrl?: string,
     *     eventStartDate?: string,
     *     eventEndDate?: string,
     *     venue?: string,
     *     format?: ConferenceFormat,
     *     cfpStartDate?: string|null,
     *     cfpEndDate?: string,
     *     categories?: array<int, string>,
     *     description?: string|null,
     *     themeColor?: string|null,
     * }  $fields
     *
     * @throws ConferenceNotFoundException
     */
    public function execute(string $conferenceId, array $fields): Conference
    {
        $existing = $this->repository->findById($conferenceId);
        if ($existing === null) {
            throw ConferenceNotFoundException::withId($conferenceId);
        }

        // 既存値で全フィールドを埋め、入力 array に含まれるキーだけ上書きする。
        // array_merge で「キー不在 = 既存値維持、値 null = 明示的に null セット」を
        // 自然に表現でき、フィールド毎の if 分岐 (= branch coverage 数 × 13) を
        // 1 箇所のマージに集約できる。
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
        ];
        $args = array_merge($args, $fields);

        // 名前付き引数の spread (PHP 8+): キー名 → コンストラクタ引数名にマップされる
        /** @var array{
         *     conferenceId: string,
         *     name: string,
         *     trackName: string|null,
         *     officialUrl: string,
         *     cfpUrl: string,
         *     eventStartDate: string,
         *     eventEndDate: string,
         *     venue: string,
         *     format: \App\Domain\Conferences\ConferenceFormat,
         *     cfpStartDate: string|null,
         *     cfpEndDate: string,
         *     categories: array<int, string>,
         *     description: string|null,
         *     themeColor: string|null,
         *     createdAt: string,
         *     updatedAt: string,
         * } $args
         */
        $updated = new Conference(...$args);

        $this->repository->save($updated);

        return $updated;
    }
}
