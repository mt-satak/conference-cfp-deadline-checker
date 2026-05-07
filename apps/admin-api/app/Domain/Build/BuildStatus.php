<?php

namespace App\Domain\Build;

/**
 * ビルドジョブの状態を表す値オブジェクト。
 *
 * OpenAPI 仕様 (data/openapi.yaml の BuildStatus) に整合。
 * - jobId / status / startedAt は必須
 * - commitId / commitMessage はリポジトリ連携時のみ存在
 * - endedAt はビルド完了時のみ存在
 * - triggerSource は推定可能な場合のみ存在 (Phase 5.3 以降は GitHub Actions の
 *   workflow event 名から判定する)
 */
final readonly class BuildStatus
{
    public function __construct(
        public string $jobId,
        public BuildJobStatus $status,
        public string $startedAt,
        public ?string $commitId,
        public ?string $commitMessage,
        public ?string $endedAt,
        public ?BuildTriggerSource $triggerSource,
    ) {}
}
