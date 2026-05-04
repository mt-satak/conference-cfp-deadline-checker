<?php

namespace App\Application\Conferences;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceRepository;

/**
 * カンファレンス 1 件取得 UseCase。
 *
 * 責務:
 * - Repository->findById() で取得
 * - 該当無しの場合 (null) は ConferenceNotFoundException を投げる
 *   (HTTP 層では AdminApiExceptionRenderer が 404 + NOT_FOUND に整形する想定)
 */
class GetConferenceUseCase
{
    public function __construct(
        private readonly ConferenceRepository $repository,
    ) {}

    /**
     * @throws ConferenceNotFoundException
     */
    public function execute(string $conferenceId): Conference
    {
        $conference = $this->repository->findById($conferenceId);
        if ($conference === null) {
            throw ConferenceNotFoundException::withId($conferenceId);
        }

        return $conference;
    }
}
