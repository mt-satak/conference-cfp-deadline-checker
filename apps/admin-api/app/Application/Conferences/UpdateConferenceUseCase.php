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

        $now = Carbon::now('Asia/Tokyo')->toIso8601String();

        // ?? は「キー不在 + 値 null」の両方で default にフォールバックするが、
        // 部分更新ではキー不在 = 既存値維持、値 null = "明示的に null をセット" を
        // 区別する必要があるため array_key_exists で分岐する。
        $updated = new Conference(
            conferenceId: $existing->conferenceId,
            name: array_key_exists('name', $fields) ? $fields['name'] : $existing->name,
            trackName: array_key_exists('trackName', $fields) ? $fields['trackName'] : $existing->trackName,
            officialUrl: array_key_exists('officialUrl', $fields) ? $fields['officialUrl'] : $existing->officialUrl,
            cfpUrl: array_key_exists('cfpUrl', $fields) ? $fields['cfpUrl'] : $existing->cfpUrl,
            eventStartDate: array_key_exists('eventStartDate', $fields) ? $fields['eventStartDate'] : $existing->eventStartDate,
            eventEndDate: array_key_exists('eventEndDate', $fields) ? $fields['eventEndDate'] : $existing->eventEndDate,
            venue: array_key_exists('venue', $fields) ? $fields['venue'] : $existing->venue,
            format: array_key_exists('format', $fields) ? $fields['format'] : $existing->format,
            cfpStartDate: array_key_exists('cfpStartDate', $fields) ? $fields['cfpStartDate'] : $existing->cfpStartDate,
            cfpEndDate: array_key_exists('cfpEndDate', $fields) ? $fields['cfpEndDate'] : $existing->cfpEndDate,
            categories: array_key_exists('categories', $fields) ? $fields['categories'] : $existing->categories,
            description: array_key_exists('description', $fields) ? $fields['description'] : $existing->description,
            themeColor: array_key_exists('themeColor', $fields) ? $fields['themeColor'] : $existing->themeColor,
            createdAt: $existing->createdAt,
            updatedAt: $now,
        );

        $this->repository->save($updated);

        return $updated;
    }
}
