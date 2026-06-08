<?php

declare(strict_types=1);

namespace App\Application\CfpSources;

use App\Domain\CfpSources\CfpSource;
use App\Domain\CfpSources\CfpSourceConflictException;
use App\Domain\CfpSources\CfpSourceRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * CfP ソース新規登録 UseCase (Issue #200 PR-1)。
 *
 * 責務:
 * - url 重複チェック (正規化後一致で CfpSourceConflictException)
 * - 入力 DTO から sourceId / createdAt / updatedAt を補完して CfpSource 構築
 * - Repository->save() で永続化
 */
class CreateCfpSourceUseCase
{
    public function __construct(
        private readonly CfpSourceRepository $repository,
    ) {}

    /**
     * @throws CfpSourceConflictException url 重複時
     */
    public function execute(CreateCfpSourceInput $input): CfpSource
    {
        if ($this->repository->findByUrl($input->url) !== null) {
            throw CfpSourceConflictException::withUrl($input->url);
        }

        $now = Carbon::now('Asia/Tokyo')->toIso8601String();

        $source = new CfpSource(
            sourceId: (string) Str::uuid(),
            name: $input->name,
            url: $input->url,
            enabled: $input->enabled,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->repository->save($source);

        return $source;
    }
}
