<?php

namespace App\Application\Categories;

use App\Domain\Categories\CategoryConflictException;
use App\Domain\Categories\CategoryNotFoundException;
use App\Domain\Categories\CategoryRepository;
use App\Domain\Conferences\ConferenceRepository;

/**
 * カテゴリ削除 UseCase。
 *
 * 責務:
 * - 削除対象が存在しなければ CategoryNotFoundException
 * - 該当 categoryId を参照する Conference が存在すれば CategoryConflictException
 *   (HTTP 409、運用者は事前にカンファレンス側のタグ付けを外す必要)
 * - Repository->deleteById() で削除
 *
 * 参照整合性チェックは Conferences Aggregate を読む必要があるため、
 * ConferenceRepository を依存として注入する (Domain 層 interface 経由)。
 */
class DeleteCategoryUseCase
{
    public function __construct(
        private readonly CategoryRepository $categoryRepository,
        private readonly ConferenceRepository $conferenceRepository,
    ) {}

    /**
     * @throws CategoryNotFoundException
     * @throws CategoryConflictException  参照する Conference が存在する場合
     */
    public function execute(string $categoryId): void
    {
        // 存在チェックを先に行う (404 vs 409 の優先度: 不在 → 404 を返したい)
        if ($this->categoryRepository->findById($categoryId) === null) {
            throw CategoryNotFoundException::withId($categoryId);
        }

        $referenceCount = $this->conferenceRepository->countByCategoryId($categoryId);
        if ($referenceCount > 0) {
            throw CategoryConflictException::referencedByConferences($categoryId, $referenceCount);
        }

        $this->categoryRepository->deleteById($categoryId);
    }
}
