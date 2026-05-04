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
}
