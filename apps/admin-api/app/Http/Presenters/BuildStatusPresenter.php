<?php

namespace App\Http\Presenters;

use App\Domain\Build\BuildStatus;

/**
 * BuildStatus Domain Entity → JSON 配列変換。
 *
 * OpenAPI 仕様 (data/openapi.yaml の BuildStatus) に整合する shape を作る。
 * optional フィールド (commitId / commitMessage / endedAt / triggerSource) は
 * null のとき出力しない。
 */
class BuildStatusPresenter
{
    /**
     * @return array{
     *     jobId: string,
     *     status: string,
     *     startedAt: string,
     *     commitId?: string,
     *     commitMessage?: string,
     *     endedAt?: string,
     *     triggerSource?: string,
     * }
     */
    public static function toArray(BuildStatus $status): array
    {
        $payload = [
            'jobId' => $status->jobId,
            'status' => $status->status->value,
            'startedAt' => $status->startedAt,
        ];

        if ($status->commitId !== null) {
            $payload['commitId'] = $status->commitId;
        }
        if ($status->commitMessage !== null) {
            $payload['commitMessage'] = $status->commitMessage;
        }
        if ($status->endedAt !== null) {
            $payload['endedAt'] = $status->endedAt;
        }
        if ($status->triggerSource !== null) {
            $payload['triggerSource'] = $status->triggerSource->value;
        }

        return $payload;
    }

    /**
     * BuildStatus[] を toArray した配列のリストに一括変換する (Issue #178 #3)。
     *
     * @param  BuildStatus[]  $statuses
     * @return list<array<string, mixed>>
     */
    public static function toList(array $statuses): array
    {
        return array_values(array_map(
            static fn (BuildStatus $s): array => self::toArray($s),
            $statuses,
        ));
    }
}
