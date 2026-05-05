<?php

namespace App\Application\Conferences;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * カンファレンス新規登録 UseCase。
 *
 * 責務:
 * - 入力 DTO (CreateConferenceInput) から conferenceId / createdAt / updatedAt を
 *   補完して Conference Entity を構築する
 * - Repository->save() で永続化する
 * - 構築した Conference を返す
 *
 * conferenceId は UUID v4 を生成。
 * createdAt / updatedAt は現在時刻 (JST) を ISO 8601 文字列で記録。
 *
 * バリデーション (URL 形式 / 日付整合性 / categories の参照整合性 等) は HTTP 層
 * (FormRequest) で行う前提なので、本 UseCase 内では再検証しない。
 */
class CreateConferenceUseCase
{
    public function __construct(
        private readonly ConferenceRepository $repository,
    ) {}

    public function execute(CreateConferenceInput $input): Conference
    {
        $now = Carbon::now('Asia/Tokyo')->toIso8601String();

        $conference = new Conference(
            conferenceId: (string) Str::uuid(),
            name: $input->name,
            trackName: $input->trackName,
            officialUrl: $input->officialUrl,
            cfpUrl: $input->cfpUrl,
            eventStartDate: $input->eventStartDate,
            eventEndDate: $input->eventEndDate,
            venue: $input->venue,
            format: $input->format,
            cfpStartDate: $input->cfpStartDate,
            cfpEndDate: $input->cfpEndDate,
            categories: $input->categories,
            description: $input->description,
            themeColor: $input->themeColor,
            createdAt: $now,
            updatedAt: $now,
            status: $input->status,
        );

        $this->repository->save($conference);

        return $conference;
    }
}
