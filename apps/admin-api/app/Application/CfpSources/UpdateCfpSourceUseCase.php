<?php

declare(strict_types=1);

namespace App\Application\CfpSources;

use App\Domain\CfpSources\CfpSource;
use App\Domain\CfpSources\CfpSourceConflictException;
use App\Domain\CfpSources\CfpSourceNotFoundException;
use App\Domain\CfpSources\CfpSourceRepository;
use Illuminate\Support\Carbon;

/**
 * CfP ソース更新 UseCase (Issue #200 PR-1)。
 *
 * 部分更新セマンティクス: 入力 array に含まれていないキーは元の値を維持する。
 * url を変更する場合は他 source との重複チェックも行う (自分自身は除外)。
 */
class UpdateCfpSourceUseCase
{
    public function __construct(
        private readonly CfpSourceRepository $repository,
    ) {}

    /**
     * @param  array{name?: string, url?: string, enabled?: bool}  $fields
     *
     * @throws CfpSourceNotFoundException
     * @throws CfpSourceConflictException url 変更時に他 source と重複したら
     */
    public function execute(string $sourceId, array $fields): CfpSource
    {
        $existing = $this->repository->findById($sourceId);
        if ($existing === null) {
            throw CfpSourceNotFoundException::withId($sourceId);
        }

        // url 変更時は他 source との重複チェック
        if (array_key_exists('url', $fields) && $fields['url'] !== $existing->url) {
            $conflict = $this->repository->findByUrl($fields['url']);
            if ($conflict !== null && $conflict->sourceId !== $sourceId) {
                throw CfpSourceConflictException::withUrl($fields['url']);
            }
        }

        $updated = new CfpSource(
            sourceId: $existing->sourceId,
            name: $fields['name'] ?? $existing->name,
            url: $fields['url'] ?? $existing->url,
            enabled: $fields['enabled'] ?? $existing->enabled,
            createdAt: $existing->createdAt,
            updatedAt: Carbon::now('Asia/Tokyo')->toIso8601String(),
        );

        $this->repository->save($updated);

        return $updated;
    }
}
