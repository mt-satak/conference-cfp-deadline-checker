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
     * @param  array<string, mixed>  $fields  更新するフィールドのみのキーバリュー
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

        $updated = new Conference(
            conferenceId: $existing->conferenceId,
            name: $this->pick($fields, 'name', $existing->name),
            trackName: $this->pick($fields, 'trackName', $existing->trackName),
            officialUrl: $this->pick($fields, 'officialUrl', $existing->officialUrl),
            cfpUrl: $this->pick($fields, 'cfpUrl', $existing->cfpUrl),
            eventStartDate: $this->pick($fields, 'eventStartDate', $existing->eventStartDate),
            eventEndDate: $this->pick($fields, 'eventEndDate', $existing->eventEndDate),
            venue: $this->pick($fields, 'venue', $existing->venue),
            format: $this->pickFormat($fields, $existing->format),
            cfpStartDate: $this->pick($fields, 'cfpStartDate', $existing->cfpStartDate),
            cfpEndDate: $this->pick($fields, 'cfpEndDate', $existing->cfpEndDate),
            categories: $this->pick($fields, 'categories', $existing->categories),
            description: $this->pick($fields, 'description', $existing->description),
            themeColor: $this->pick($fields, 'themeColor', $existing->themeColor),
            createdAt: $existing->createdAt,
            updatedAt: $now,
        );

        $this->repository->save($updated);

        return $updated;
    }

    /**
     * 入力 array にキーがあれば該当値、無ければデフォルト値を返す。
     *
     * @param  array<string, mixed>  $fields
     */
    private function pick(array $fields, string $key, mixed $default): mixed
    {
        return array_key_exists($key, $fields) ? $fields[$key] : $default;
    }

    /**
     * format は型を厳密にしたいので専用ヘルパで取り出す。
     *
     * @param  array<string, mixed>  $fields
     */
    private function pickFormat(array $fields, ConferenceFormat $default): ConferenceFormat
    {
        if (! array_key_exists('format', $fields)) {
            return $default;
        }
        $value = $fields['format'];

        return $value instanceof ConferenceFormat ? $value : $default;
    }
}
