<?php

namespace App\Application\Conferences;

use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceRepository;

/**
 * カンファレンス削除 UseCase。
 *
 * 責務:
 * - Repository->deleteById() を呼んで削除する
 * - 該当無し (戻り値 false) の場合は ConferenceNotFoundException を投げる
 *   (HTTP 層では AdminApiExceptionRenderer が 404 + NOT_FOUND に整形する想定)
 */
class DeleteConferenceUseCase
{
    public function __construct(
        private readonly ConferenceRepository $repository,
    ) {}

    /**
     * @throws ConferenceNotFoundException
     */
    public function execute(string $conferenceId): void
    {
        $deleted = $this->repository->deleteById($conferenceId);
        if (! $deleted) {
            throw ConferenceNotFoundException::withId($conferenceId);
        }
    }
}
