<?php

namespace App\Domain\Build;

/**
 * ビルドジョブのステータス。
 *
 * OpenAPI 仕様 (data/openapi.yaml の BuildStatus.status) の enum に対応。
 * GitHubActionsBuildStatusReader が GitHub Actions の status / conclusion を
 * この enum にマッピングする (Phase 5.3 で AWS Amplify から移行)。
 *
 * Issue #117 で旧 Amplify SDK 由来の Provisioning / Cancelling を削除。
 * GitHub Actions には:
 *   - 起動準備中の中間 status は無い (queued → in_progress に直接遷移)
 *   - キャンセル処理は瞬時 (in_progress → cancelled の中間状態無し)
 * ため、両 enum 値は GitHub Actions に対応する状態が存在しない。
 */
enum BuildJobStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Failed = 'FAILED';
    case Succeed = 'SUCCEED';
    case Cancelled = 'CANCELLED';
}
