<?php

namespace App\Application\Categories;

use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryConflictException;
use App\Domain\Categories\CategoryNotFoundException;
use App\Domain\Categories\CategoryRepository;
use Illuminate\Support\Carbon;

/**
 * カテゴリ更新 UseCase。
 *
 * 責務:
 * - 既存 Category 取得 (なければ CategoryNotFoundException)
 * - name / slug 変更時は他レコードとの重複チェック (重複時 CategoryConflictException)
 * - 部分更新セマンティクス: 入力 array に含まれないキーは元の値を維持
 * - updatedAt を現在時刻 (JST) に更新、createdAt と categoryId は維持
 * - Repository->save() で永続化
 *
 * バリデーション (slug 形式等) は HTTP 層 (FormRequest) で行う前提。
 */
class UpdateCategoryUseCase
{
    public function __construct(
        private readonly CategoryRepository $repository,
    ) {}

    /**
     * axis を明示的に null に戻す経路は Laravel FormRequest::validated() が null を
     * フィルタするため API 経由では存在しない (Controller / UseCase 側でも non-null
     * のみ扱う前提)。axis をクリアしたい場合は別 API 設計が必要。
     *
     * @param  array{
     *     name?: string,
     *     slug?: string,
     *     displayOrder?: int,
     *     axis?: \App\Domain\Categories\CategoryAxis,
     * }  $fields
     *
     * @throws CategoryNotFoundException
     * @throws CategoryConflictException
     */
    public function execute(string $categoryId, array $fields): Category
    {
        $existing = $this->repository->findById($categoryId);
        if ($existing === null) {
            throw CategoryNotFoundException::withId($categoryId);
        }

        // name / slug 変更時のみ重複チェック (自分自身は除外)
        if (array_key_exists('name', $fields) && $fields['name'] !== $existing->name) {
            $duplicate = $this->repository->findByName($fields['name']);
            if ($duplicate !== null && $duplicate->categoryId !== $categoryId) {
                throw CategoryConflictException::nameAlreadyExists($fields['name']);
            }
        }
        if (array_key_exists('slug', $fields) && $fields['slug'] !== $existing->slug) {
            $duplicate = $this->repository->findBySlug($fields['slug']);
            if ($duplicate !== null && $duplicate->categoryId !== $categoryId) {
                throw CategoryConflictException::slugAlreadyExists($fields['slug']);
            }
        }

        // UpdateConferenceUseCase と同じ array_merge + named-arg spread パターン。
        // フィールド毎の if 分岐 = branch coverage 数の増加を避ける。
        $args = [
            'categoryId' => $existing->categoryId,
            'name' => $existing->name,
            'slug' => $existing->slug,
            'displayOrder' => $existing->displayOrder,
            'axis' => $existing->axis,
            'createdAt' => $existing->createdAt,
            'updatedAt' => Carbon::now('Asia/Tokyo')->toIso8601String(),
        ];
        $args = array_merge($args, $fields);

        /** @var array{
         *     categoryId: string,
         *     name: string,
         *     slug: string,
         *     displayOrder: int,
         *     axis: \App\Domain\Categories\CategoryAxis|null,
         *     createdAt: string,
         *     updatedAt: string,
         * } $args  axis は既存値が null の場合のみ null、入力経由では non-null のみ
         */
        $updated = new Category(...$args);

        $this->repository->save($updated);

        return $updated;
    }
}
